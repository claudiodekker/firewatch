<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Accounting;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\BudgetVerdict;
use ClaudioDekker\Firewatch\Mcp\Children;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\ExceptionSection;
use ClaudioDekker\Firewatch\Mcp\ExecutionHeader;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\Markdown;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Timeline;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('execution')]
#[Title('Execution')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Execution extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The timeline entries an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 50;

    /**
     * The most timeline entries an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.execution');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()->description(__('firewatch::messages.execution_id_argument')),
            'type' => $schema->string()->description(__('firewatch::messages.execution_type_argument')),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with one execution in full.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the execution, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this->name());
        $id = $this->executionId($arguments);
        $type = $this->type($arguments, $id);
        $limit = $arguments->limit(self::MAXIMUM_LIMIT, self::DEFAULT_LIMIT);

        $frame = $this->frame($now);
        $window = Window::none(reason: __('firewatch::messages.execution_window_reason'), timezone: $frame->timezone);
        $searched = $type === null ? ExecutionType::records() : [RecordType::from($type->value)];

        $frame->about($searched, $window, anchored: $id === null);

        $read = $frame->read(fn (SQLite3 $connection, StoreFacts $facts) => $this->load($connection, $facts, $id, $type));

        if ($read === null) {
            return $frame->empty();
        }

        [$found, $traced] = $read;

        $frame->about($found === null ? $searched : [RecordType::from($found['row']['type']), ...Children::types()], $window, anchored: $id === null);

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        if ($found !== null) {
            return $this->execution($frame, $found, $limit);
        }

        if ($id !== null) {
            throw $this->notFound($id, $traced);
        }

        $filters = [$type === null ? __('firewatch::messages.execution_any_type') : "type: {$type->value}"];

        return $frame->empty(Emptiness::noMatch($frame->facts()->records, $filters));
    }

    /**
     * Put one execution in the envelope.
     *
     * @param  array{row: array<string, mixed>, children: list<array<string, mixed>>}  $found
     */
    protected function execution(Frame $frame, array $found, int $limit): Answer
    {
        $row = $found['row'];
        $children = $found['children'];
        $type = RecordType::from($row['type']);
        $budget = BudgetVerdict::of($this->configuration, ExecutionType::from($type->value), $row);
        $header = [
            ...ExecutionHeader::of($type, $row),
            'budget' => $budget->toArray(),
        ];
        $exceptions = ExceptionSection::of($children);
        $entries = Timeline::of($children);
        $timeline = Rows::bound($entries, $limit);

        $result = ['header' => $header];

        if ($type === RecordType::REQUEST) {
            $result['request'] = $this->request($row);
        }

        $result['accounting'] = Accounting::of($row, $children, $frame->facts()->meta, $frame->timezone);
        $result['exceptions'] = $exceptions['rows'];
        $result['timeline'] = $timeline->rows;
        $result['caused'] = [
            'jobs_queued' => $row['jobs_queued'] ?? null,
            'trace_id' => $row['trace_id'],
        ];

        $truncated = array_values(array_filter([
            $this->exceptionsCut($exceptions, $row['execution_id']),
            $timeline->truncation(section: 'timeline', how: __('firewatch::messages.execution_timeline_how', ['id' => $row['execution_id']]), matched: count($entries)),
        ]));

        $outcome = Markdown::cell($header['outcome']);
        $summary = __('firewatch::messages.execution_summary', [
            'type' => $type->value,
            'id' => $row['execution_id'],
            'outcome' => $outcome,
        ]);

        $next = $this->next($row, $children);

        return $frame->answer(
            summary: $summary,
            result: $result,
            truncated: $truncated,
            next: $next,
            notes: [$budget->line()],
        );
    }

    /**
     * Get the headers and payload of a request as they were stored.
     *
     * @param  array<string, mixed>  $row
     * @return array{headers: mixed, payload: mixed}
     */
    protected function request(array $row): array
    {
        return [
            'headers' => Cell::json($row['headers']),
            'payload' => Cell::json($row['payload']),
        ];
    }

    /**
     * Get the `truncated` entry for exceptions beyond the most shown, or null when all are.
     *
     * @param  array{rows: list<array<string, mixed>>, matched: int}  $exceptions
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}|null
     */
    protected function exceptionsCut(array $exceptions, ?string $id): ?array
    {
        if ($exceptions['matched'] <= ExceptionSection::MAXIMUM) {
            return null;
        }

        return [
            'section' => 'exceptions',
            'shown' => count($exceptions['rows']),
            'matched' => $exceptions['matched'],
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.execution_exceptions_how', ['id' => $id]),
        ];
    }

    /**
     * Get the calls that follow from the execution.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $children
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(array $row, array $children): array
    {
        $next = [];

        if (is_string($row['group_hash'] ?? null)) {
            $next[] = [
                'tool' => 'rank',
                'arguments' => ['group' => $row['group_hash']],
                'why' => __('firewatch::messages.execution_next_rank'),
            ];
        }

        if (in_array(RecordType::QUERY->value, array_column($children, 'type'), true)) {
            $next[] = [
                'tool' => 'occurrences',
                'arguments' => [
                    'execution_id' => $row['execution_id'],
                    'type' => RecordType::QUERY->value,
                ],
                'why' => __('firewatch::messages.execution_next_occurrences'),
            ];
        }

        if (is_string($row['trace_id'] ?? null)) {
            $next[] = [
                'tool' => 'trace',
                'arguments' => ['trace_id' => $row['trace_id']],
                'why' => __('firewatch::messages.execution_next_trace'),
            ];
        }

        return $next;
    }

    /**
     * Get the refusal of an execution id the store does not hold.
     */
    protected function notFound(string $id, bool $traced): Refusal
    {
        $accepted = 'an execution id, not a trace id';
        $example = 'execution(execution_id: "<execution id>")';

        return $traced
            ? Refusal::traceIdNotExecution(argument: 'execution_id', id: $id, accepted: $accepted, example: $example)
            : Refusal::notFound(argument: 'execution_id', id: $id, accepted: $accepted, example: $example);
    }

    /**
     * Find the execution, with its children, and whether the id is a trace's.
     *
     * @return array{array{row: array<string, mixed>, children: list<array<string, mixed>>}|null, bool}
     */
    protected function load(SQLite3 $connection, StoreFacts $facts, ?string $id, ?ExecutionType $type): array
    {
        $found = $facts->records === 0 ? null : $this->find($connection, $id, $type);
        $traced = $id !== null && $this->isTrace($connection, $id);

        return [$found, $traced];
    }

    /**
     * Find the execution with the id, or the one that finished last.
     *
     * @return array{row: array<string, mixed>, children: list<array<string, mixed>>}|null
     */
    protected function find(SQLite3 $connection, ?string $id, ?ExecutionType $type): ?array
    {
        $bindings = [];

        foreach ($type === null ? ExecutionType::cases() : [$type] as $position => $case) {
            $bindings["type{$position}"] = $case->value;
        }

        $types = implode(', ', array_map(fn (string $name) => ":{$name}", array_keys($bindings)));
        $condition = $id === null ? 'ended_at IS NOT NULL' : 'execution_id = :id';
        $order = $id === null ? 'ended_at DESC, id DESC' : 'id';

        if ($id !== null) {
            $bindings['id'] = $id;
        }

        $match = Stored::rows($connection, "SELECT id, type FROM records WHERE type IN ({$types}) AND {$condition} ORDER BY {$order} LIMIT 1", $bindings)[0] ?? null;

        if ($match === null) {
            return null;
        }

        $view = RecordType::from($match['type'])->view();
        $row = Stored::rows($connection, "SELECT * FROM {$view} WHERE id = :id", ['id' => $match['id']])[0];
        $executionId = $row['execution_id'];
        $children = is_string($executionId) ? Children::read($connection, $executionId) : [];

        return [
            'row' => [
                ...$row,
                'type' => $match['type'],
            ],
            'children' => $children,
        ];
    }

    /**
     * Determine if any record in the store carries the value as its trace id.
     */
    protected function isTrace(SQLite3 $connection, string $id): bool
    {
        return Stored::rows($connection, 'SELECT 1 AS held FROM records WHERE trace_id = :id LIMIT 1', ['id' => $id]) !== [];
    }

    /**
     * Read the execution id, or null for none.
     */
    protected function executionId(Arguments $arguments): ?string
    {
        $value = $arguments->get('execution_id');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw $arguments->invalid(argument: 'execution_id', expected: 'an execution id', accepted: 'an execution id', example: 'execution(execution_id: "<execution id>")');
    }

    /**
     * Read the type of execution to take the latest of.
     */
    protected function type(Arguments $arguments, ?string $id): ?ExecutionType
    {
        $type = $arguments->choice('type', ExecutionType::cases(), 'one of the four execution types', 'execution(type: "request")');

        if ($type !== null && $id !== null) {
            throw Refusal::conflicting(argument: 'type', with: 'execution_id', accepted: 'a call with `execution_id` or with `type`, not both', example: 'execution(execution_id: "<execution id>")');
        }

        return $type;
    }
}
