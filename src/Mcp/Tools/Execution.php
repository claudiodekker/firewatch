<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Accounting;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Children;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\ExceptionSection;
use ClaudioDekker\Firewatch\Mcp\ExecutionHeader;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Markdown;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Timeline;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
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
     * Get the arguments of the tool: which execution, how many timeline entries and the format.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()->description(__('firewatch::messages.execution_id_argument')),
            'type' => $schema->string()->description(__('firewatch::messages.execution_type_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.execution_limit_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with one execution in full: its header, accounting, exceptions and timeline.
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
        $id = $this->executionId($request);
        $type = $this->type($request, $id);
        $limit = $this->limit($request);

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::none(reason: __('firewatch::messages.execution_window_reason'), timezone: $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $searched = $type === null ? $this->executionTypes() : [RecordType::from($type->value)];

        try {
            [$total, $oldest, $newest, $facts, $found, $traced] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $id, $type));
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...BlindSpots::for($searched, anchored: $id === null), ...$this->conditions->for(null, $searched, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $searched, History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $types = $found === null ? $searched : [RecordType::from($found['row']['type']), ...Children::types()];
        $blindSpots = [...BlindSpots::for($types, anchored: $id === null), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        if ($found !== null) {
            return $this->execution($epoch, $timezone, $window, $coverage, $blindSpots, $facts, $found, $limit);
        }

        if ($id !== null) {
            throw $this->notFound($id, $traced);
        }

        $filters = [$type === null ? __('firewatch::messages.execution_any_type') : "type: {$type->value}"];
        $empty = Emptiness::noMatch($total, $filters);

        return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
    }

    /**
     * Put one execution in the envelope: the header, the headers and payload of a request, the accounting, the exceptions, the timeline and what it caused.
     *
     * @param  list<array<string, mixed>>  $blindSpots
     * @param  array{row: array<string, mixed>, children: list<array<string, mixed>>}  $found
     */
    protected function execution(float $epoch, string $timezone, Window $window, Coverage $coverage, array $blindSpots, StoreFacts $facts, array $found, int $limit): Answer
    {
        $row = $found['row'];
        $children = $found['children'];
        $type = RecordType::from($row['type']);
        $header = ExecutionHeader::of($type, $row);
        $exceptions = ExceptionSection::of($children);
        $entries = Timeline::of($children);
        $timeline = Rows::bound($entries, $limit);

        $result = ['header' => $header];

        if ($type === RecordType::REQUEST) {
            $result['request'] = $this->request($row);
        }

        $result['accounting'] = Accounting::of($row, $children, $facts->meta, $timezone);
        $result['exceptions'] = $exceptions['rows'];
        $result['timeline'] = $timeline->rows;
        $result['caused'] = [
            'jobs_queued' => $row['jobs_queued'] ?? null,
            'trace_id' => $row['trace_id'],
        ];

        $truncated = array_values(array_filter([
            $this->exceptionsCut($exceptions),
            $this->timelineCut($timeline, count($entries)),
        ]));

        $outcome = Markdown::cell($header['outcome']);
        $summary = __('firewatch::messages.execution_summary', [
            'type' => $type->value,
            'id' => $row['execution_id'],
            'outcome' => $outcome,
        ]);

        $next = $this->next($row);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $summary,
            empty: null,
            result: $result,
            coverage: $coverage,
            blindSpots: $blindSpots,
            truncated: $truncated,
            next: $next,
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
            'headers' => Stored::json($row['headers']),
            'payload' => Stored::json($row['payload']),
        ];
    }

    /**
     * Get the `truncated` entry for exceptions beyond the most shown, or null when all are.
     *
     * @param  array{rows: list<array<string, mixed>>, matched: int}  $exceptions
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}|null
     */
    protected function exceptionsCut(array $exceptions): ?array
    {
        if ($exceptions['matched'] <= ExceptionSection::MAXIMUM) {
            return null;
        }

        return [
            'section' => 'exceptions',
            'shown' => count($exceptions['rows']),
            'matched' => $exceptions['matched'],
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.execution_exceptions_how'),
        ];
    }

    /**
     * Get the `truncated` entry for a timeline the limit cut, or null for a complete one.
     *
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}|null
     */
    protected function timelineCut(Rows $timeline, int $entries): ?array
    {
        $cut = $timeline->truncation(section: 'timeline', how: __('firewatch::messages.execution_timeline_how'));

        if ($cut === null) {
            return null;
        }

        return [
            ...$cut,
            'matched' => $entries,
        ];
    }

    /**
     * Get the calls that follow from the execution: rank its group.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(array $row): array
    {
        if (! is_string($row['group_hash'] ?? null)) {
            return [];
        }

        return [
            [
                'tool' => 'rank',
                'arguments' => ['group' => $row['group_hash']],
                'why' => __('firewatch::messages.execution_next_rank'),
            ],
        ];
    }

    /**
     * Get the refusal of an execution id the store does not hold, with where to read it when it is a trace id.
     */
    protected function notFound(string $id, bool $traced): Refusal
    {
        $accepted = 'an execution id; a trace id belongs to `trace`';
        $example = 'execution(execution_id: "<execution id>")';

        return $traced
            ? Refusal::traceIdNotExecution(argument: 'execution_id', id: $id, accepted: $accepted, example: $example)
            : Refusal::notFound(argument: 'execution_id', id: $id, accepted: $accepted, example: $example);
    }

    /**
     * Read the store's span and facts, and find the execution, with its children, in one snapshot.
     *
     * @return array{int, float|null, float|null, StoreFacts, array{row: array<string, mixed>, children: list<array<string, mixed>>}|null, bool}
     */
    protected function load(SQLite3 $connection, ?string $id, ?ExecutionType $type): array
    {
        $span = Stored::rows($connection, 'SELECT count(*) AS total, min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];
        $total = is_int($span['total']) ? $span['total'] : 0;
        $found = $total === 0 ? null : $this->find($connection, $id, $type);
        $traced = $id !== null && $this->isTrace($connection, $id);
        $facts = StoreFacts::read($connection);

        return [$total, $span['oldest'], $span['newest'], $facts, $found, $traced];
    }

    /**
     * Find the execution: the one with the id, or the one that finished last, of a type or of any.
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
     * Get the four execution types as record types.
     *
     * @return list<RecordType>
     */
    protected function executionTypes(): array
    {
        return array_map(fn (ExecutionType $type) => RecordType::from($type->value), ExecutionType::cases());
    }

    /**
     * Read the execution id, which is a non-empty string, or null for none.
     */
    protected function executionId(Request $request): ?string
    {
        $value = $request->get('execution_id');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'execution_id', expected: 'an execution id', value: $shown, accepted: 'an execution id', example: 'execution(execution_id: "<execution id>")');
    }

    /**
     * Read the type of execution to take the latest of, which is one of the four and is not given with an execution id.
     */
    protected function type(Request $request, ?string $id): ?ExecutionType
    {
        $value = $request->get('type');

        if ($value === null) {
            return null;
        }

        $type = is_string($value) ? ExecutionType::tryFrom($value) : null;

        if ($type === null) {
            $accepted = implode(', ', array_map(fn (ExecutionType $case) => $case->value, ExecutionType::cases()));
            $shown = json_encode($value, JSON_THROW_ON_ERROR);

            throw Refusal::invalid(argument: 'type', expected: 'one of the four execution types', value: $shown, accepted: $accepted, example: 'execution(type: "request")');
        }

        if ($id !== null) {
            throw Refusal::conflicting(argument: 'type', with: 'execution_id', accepted: 'a call with `execution_id` or with `type`, not both', example: 'execution(execution_id: "<execution id>")');
        }

        return $type;
    }

    /**
     * Read the most timeline entries to list, from 1 to 100.
     */
    protected function limit(Request $request): int
    {
        $value = $request->get('limit');

        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (is_int($value) && $value >= 1 && $value <= self::MAXIMUM_LIMIT) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: 'execution(limit: '.self::DEFAULT_LIMIT.')');
    }
}
