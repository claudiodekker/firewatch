<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use BackedEnum;
use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\Listing;
use ClaudioDekker\Firewatch\Mcp\LogLevel;
use ClaudioDekker\Firewatch\Mcp\Order;
use ClaudioDekker\Firewatch\Mcp\Outcome;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\Mcp\WithheldReason;
use ClaudioDekker\Firewatch\RecordType;
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
#[Name('occurrences')]
#[Title('Occurrences')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Occurrences extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The rows an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 20;

    /**
     * The most rows an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

    /**
     * The longest `matching` there is.
     */
    protected const MAXIMUM_MATCHING = 200;

    /**
     * The example every refusal of this tool gives.
     */
    protected const EXAMPLE = 'occurrences(type: "request")';

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
        return __('firewatch::messages.tools.occurrences');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()->description(__('firewatch::messages.occurrences_group_argument')),
            'type' => $schema->string()->description(__('firewatch::messages.occurrences_type_argument')),
            'execution_id' => $schema->string()->description(__('firewatch::messages.occurrences_execution_id_argument')),
            'trace_id' => $schema->string()->description(__('firewatch::messages.occurrences_trace_id_argument')),
            'job_id' => $schema->string()->description(__('firewatch::messages.occurrences_job_id_argument')),
            'user_id' => $schema->string()->description(__('firewatch::messages.occurrences_user_id_argument')),
            'order' => $schema->string()->description(__('firewatch::messages.occurrences_order_argument')),
            'method' => $schema->string()->description(__('firewatch::messages.occurrences_method_argument')),
            'status' => $schema->string()->description(__('firewatch::messages.occurrences_status_argument')),
            'outcome' => $schema->string()->description(__('firewatch::messages.occurrences_outcome_argument')),
            'level' => $schema->string()->description(__('firewatch::messages.occurrences_level_argument')),
            'slower_than_ms' => $schema->number()->description(__('firewatch::messages.occurrences_slower_than_ms_argument')),
            'at_or_above' => $schema->string()->description(__('firewatch::messages.occurrences_at_or_above_argument')),
            'matching' => $schema->string()->description(__('firewatch::messages.occurrences_matching_argument')),
            ...$this->windowSchema($schema),
            'deploy' => $schema->string()->description(__('firewatch::messages.deploy_argument')),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
            'cursor' => $schema->string()->description(__('firewatch::messages.cursor_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the individual records the selectors pick out.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the list in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this->name());
        $group = $arguments->group('occurrences(group: "<group id>")');
        $type = $arguments->type(RecordType::events(), 'one of the record types', self::EXAMPLE);
        $ids = $this->ids($arguments);

        if ($group === null && $type === null && array_filter($ids) === []) {
            throw Refusal::missing(argument: 'selector', accepted: 'group, type, execution_id, trace_id, job_id or user_id', example: self::EXAMPLE);
        }

        $order = $arguments->choice('order', Order::cases(), 'one of the orders', 'occurrences(type: "request", order: "slowest")') ?? Order::RECENT;
        $limit = $arguments->limit(self::MAXIMUM_LIMIT, self::DEFAULT_LIMIT, alongside: ['type' => 'request']);
        $deploy = $arguments->deploy('occurrences(type: "request", deploy: "v1")');
        $selector = $group !== null ? 'group' : (array_key_first(array_filter($ids)) ?? 'type');
        $filters = $this->filters($arguments);

        $this->refuseMisfits(filters: $filters, order: $order, type: $type, selector: $selector, resolvable: $group !== null);

        if ($type !== null) {
            $this->refuseOutcome($filters['outcome'], $type);
        }

        $cursorValue = $request->get('cursor');
        $cursor = $cursorValue === null ? null : Cursor::read(value: $cursorValue, tool: $this->name(), arguments: $request->all(), key: $this->key(...));

        $frame = $this->frame($now);
        $window = $this->window($request, $now, $frame->timezone, $cursor);

        $frame->about($type === null ? RecordType::events() : [$type], $window, actor: $ids['user_id'] !== null);

        $read = $frame->read(fn (SQLite3 $connection) => $this->inspect($connection, $window, $order, $group, $type, $ids, $deploy, $filters, $limit, $selector, $cursor));

        if ($read === null) {
            return $frame->empty();
        }

        $cursor?->belongsTo($frame->facts()->meta->createdAt, $this->name());

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        if ($read['inWindow'] === 0) {
            return $frame->empty(Emptiness::windowEmpty($frame->facts()->records));
        }

        if ($read['listed'] === null || $read['listed']['rows'] === []) {
            return $frame->empty(Emptiness::noMatch($read['inWindow'], $this->given($group, $type, $ids, $deploy, $filters)));
        }

        return $this->listing($request, $frame, $window, $order, $group, $ids['user_id'], $limit, $read);
    }

    /**
     * Read the store inside its snapshot.
     *
     * @param  array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}  $ids
     * @param  array{method: string|null, status: array{int, int}|null, status_text: string|null, outcome: Outcome|null, level: LogLevel|null, slower_than_ms: int|float|null, at_or_above: Percentile|null, matching: string|null}  $filters
     * @param  Cursor<array{value: int|float, id: int}>|null  $cursor
     * @return array{inWindow: int, baseline: array{samples: int, needed: int, threshold: int|float|null, percentile: Percentile}|null, listed: array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>}|null, sites: list<array{location: string|null, count: int}>|null}
     */
    protected function inspect(SQLite3 $connection, Window $window, Order $order, ?string $group, ?RecordType $type, array $ids, ?string $deploy, array $filters, int $limit, string $selector, ?Cursor $cursor): array
    {
        $inWindow = $this->inWindow($connection, $window);
        $nothing = [
            'inWindow' => $inWindow,
            'baseline' => null,
            'listed' => null,
            'sites' => null,
        ];

        if ($inWindow === 0) {
            return $nothing;
        }

        $held = $group === null ? [] : Listing::typesOf($connection, $group);

        if ($held !== [] && $type !== null && ! in_array($type, $held, true)) {
            $holding = implode(', ', array_map(fn (RecordType $held) => $held->value, $held));

            throw Refusal::conflicting(argument: 'group', with: 'type', accepted: "a `type` that holds the group: {$holding}", example: self::EXAMPLE);
        }

        if ($group !== null && $held === []) {
            return $nothing;
        }

        $resolved = $type ?? (count($held) === 1 ? $held[0] : null);

        $this->refuseMisfits(filters: $filters, order: $order, type: $resolved, selector: $selector, resolvable: false);

        $this->refuseOutcome($filters['outcome'], $resolved);

        $listing = new Listing(
            window: $window,
            order: $order,
            group: $group,
            type: $resolved,
            executionId: $ids['execution_id'],
            traceId: $ids['trace_id'],
            jobId: $ids['job_id'],
            userId: $ids['user_id'],
            deploy: $deploy,
            method: $filters['method'],
            status: $filters['status'],
            outcome: $filters['outcome'],
            level: $filters['level'],
            slowerThanMilliseconds: $filters['slower_than_ms'],
            matching: $filters['matching'],
        );
        $baseline = $filters['at_or_above'] === null ? null : [
            ...$listing->baseline($connection, $filters['at_or_above']),
            'percentile' => $filters['at_or_above'],
        ];
        $threshold = $baseline['threshold'] ?? null;
        $listed = $listing->rows($connection, $limit, $threshold, $cursor?->last);
        $sites = $group !== null && $resolved === RecordType::QUERY ? $listing->callSites($connection, $threshold) : null;

        return [
            ...$nothing,
            'baseline' => $baseline,
            'listed' => $listed,
            'sites' => $sites,
        ];
    }

    /**
     * Put the rows, newest or worst first, in the envelope.
     *
     * @param  array{baseline: array{samples: int, needed: int, threshold: int|float|null, percentile: Percentile}|null, listed: array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>}|null, sites: list<array{location: string|null, count: int}>|null}  $read
     */
    protected function listing(Request $request, Frame $frame, Window $window, Order $order, ?string $group, ?string $userId, int $limit, array $read): Answer
    {
        /** @var array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>} $listed */
        $listed = $read['listed'];
        $rows = Rows::bound($listed['rows'], $limit);
        $result = [
            'order' => $order->value,
            'rows' => $rows->rows,
        ];

        if ($read['baseline'] !== null) {
            $result['baseline'] = $this->baseline($read['baseline']);
        }

        if ($read['sites'] !== null) {
            $result['call_sites'] = $read['sites'];
        }

        $count = count($rows->rows);
        $summary = trans_choice('firewatch::messages.occurrences_summary', $count, [
            'count' => $count,
            'order' => $order->value,
        ]);
        $truncated = $this->truncated($request, $rows, $listed['keys'], $frame->now, $window, $frame->facts()->meta->createdAt);

        return $frame->answer(
            summary: $summary,
            result: $result,
            notes: $this->notes($read['baseline'], $userId),
            truncated: $truncated,
            next: $this->next($window, $group, $listed['rows'][0]['group']),
        );
    }

    /**
     * Get the notes of the answer.
     *
     * @param  array{samples: int, needed: int, threshold: int|float|null, percentile: Percentile}|null  $baseline
     * @return list<string>
     */
    protected function notes(?array $baseline, ?string $userId): array
    {
        $notes = [];

        if ($baseline !== null && $baseline['threshold'] === null) {
            $notes[] = __('firewatch::messages.occurrences_baseline_withheld', [
                'percentile' => $baseline['percentile']->value,
                'have' => $baseline['samples'],
                'needed' => $baseline['needed'],
            ]);
        }

        if ($userId !== null) {
            $notes[] = __('firewatch::messages.occurrences_user_only');
        }

        return $notes;
    }

    /**
     * Get the `truncated` entries of the answer.
     *
     * @param  list<array{value: int|float, id: int}>  $keys
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    protected function truncated(Request $request, Rows $rows, array $keys, float $epoch, Window $window, ?float $createdAt): array
    {
        if (! $rows->more) {
            return [];
        }

        $last = $keys[count($rows->rows) - 1];
        $arguments = array_diff_key($request->all(), array_flip(['cursor', 'format']));
        $arguments['cursor'] = Cursor::make(tool: $this->name(), arguments: $request->all(), createdAt: $createdAt, last: $last, since: $window->since(), until: $window->until() ?? $epoch);
        $call = $this->call($arguments);
        $how = __('firewatch::messages.occurrences_cursor_how', ['call' => $call]);
        $entry = $rows->truncation(section: 'rows', how: $how);

        return $entry === null ? [] : [$entry];
    }

    /**
     * Get the call that shows how the group of the first row compares with its peers.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Window $window, ?string $group, ?string $first): array
    {
        if ($group !== null || $first === null) {
            return [];
        }

        return [[
            'tool' => 'rank',
            'arguments' => [
                'group' => $first,
                ...$window->arguments(),
            ],
            'why' => __('firewatch::messages.occurrences_next_group'),
        ]];
    }

    /**
     * Get the window of the call.
     *
     * @param  Cursor<array{value: int|float, id: int}>|null  $cursor
     */
    protected function window(Request $request, CarbonImmutable $now, string $timezone, ?Cursor $cursor): Window
    {
        if ($cursor !== null) {
            return Window::between(since: $cursor->since, until: $cursor->until, timezone: $timezone);
        }

        return Window::read($request, $now, timezone: $timezone, tool: $this->name());
    }

    /**
     * Get the filters and selectors the call was given, as the words of an empty answer name them.
     *
     * @param  array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}  $ids
     * @param  array{method: string|null, status: array{int, int}|null, status_text: string|null, outcome: Outcome|null, level: LogLevel|null, slower_than_ms: int|float|null, at_or_above: Percentile|null, matching: string|null}  $filters
     * @return list<string>
     */
    protected function given(?string $group, ?RecordType $type, array $ids, ?string $deploy, array $filters): array
    {
        $given = array_filter([
            'group' => $group,
            'type' => $type?->value,
            ...$ids,
            'method' => $filters['method'],
            'status' => $filters['status_text'],
            'outcome' => $filters['outcome']?->value,
            'level' => $filters['level']?->value,
            'slower_than_ms' => $filters['slower_than_ms'],
            'at_or_above' => $filters['at_or_above']?->value,
            'matching' => $filters['matching'],
            'deploy' => $deploy,
        ], fn (mixed $value) => $value !== null);

        return array_map(fn (string $name, mixed $value) => "{$name}: {$value}", array_keys($given), $given);
    }

    /**
     * Read the key of the last row a cursor continues after, or null when it is none.
     *
     * @param  array<string, mixed>  $last
     * @return array{value: int|float, id: int}|null
     */
    protected function key(array $last): ?array
    {
        $value = $last['value'] ?? null;
        $id = $last['id'] ?? null;

        if (! (is_int($value) || is_float($value)) || ! is_int($id)) {
            return null;
        }

        return [
            'value' => $value,
            'id' => $id,
        ];
    }

    /**
     * Get the baseline of the answer.
     *
     * @param  array{samples: int, needed: int, threshold: int|float|null, percentile: Percentile}  $baseline
     * @return array<string, mixed>
     */
    protected function baseline(array $baseline): array
    {
        $withheld = null;
        $thresholdMilliseconds = null;

        if ($baseline['threshold'] === null) {
            $withheld = [
                'reason' => WithheldReason::SAMPLE_TOO_SMALL->value,
                'have' => $baseline['samples'],
                'needed' => $baseline['needed'],
            ];
        } else {
            $thresholdMilliseconds = Stored::milliseconds($baseline['threshold']);
        }

        return [
            'percentile' => $baseline['percentile']->value,
            'threshold_ms' => $thresholdMilliseconds,
            'samples' => $baseline['samples'],
            'withheld' => $withheld,
        ];
    }

    /**
     * Read the identifiers that select records.
     *
     * @return array{execution_id: string|null, trace_id: string|null, job_id: string|null, user_id: string|null}
     */
    protected function ids(Arguments $arguments): array
    {
        return [
            'execution_id' => $this->id($arguments, 'execution_id'),
            'trace_id' => $this->id($arguments, 'trace_id'),
            'job_id' => $this->id($arguments, 'job_id'),
            'user_id' => $this->id($arguments, 'user_id'),
        ];
    }

    /**
     * Read an identifier.
     */
    protected function id(Arguments $arguments, string $argument): ?string
    {
        $value = $arguments->get($argument);

        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw $arguments->invalid(argument: $argument, expected: 'an id of at least one character', accepted: "the `{$argument}` of a record", example: "occurrences({$argument}: \"abc\")");
    }

    /**
     * Read the filters.
     *
     * @return array{method: string|null, status: array{int, int}|null, status_text: string|null, outcome: Outcome|null, level: LogLevel|null, slower_than_ms: int|float|null, at_or_above: Percentile|null, matching: string|null}
     */
    protected function filters(Arguments $arguments): array
    {
        $status = $arguments->text('status', self::EXAMPLE);

        return [
            'method' => $arguments->text('method', self::EXAMPLE),
            'status' => $status === null ? null : $this->status($arguments, $status),
            'status_text' => $status,
            'outcome' => $this->choice($arguments, 'outcome', Outcome::cases(), 'an outcome', 'occurrences(type: "job-attempt", outcome: "failed")'),
            'level' => $this->choice($arguments, 'level', LogLevel::cases(), 'a log level', 'occurrences(type: "log", level: "error")'),
            'slower_than_ms' => $this->slowerThan($arguments),
            'at_or_above' => $this->choice($arguments, 'at_or_above', Percentile::cases(), 'median or p95', 'occurrences(type: "request", at_or_above: "p95")'),
            'matching' => $this->matching($arguments),
        ];
    }

    /**
     * Read a status as the lowest and the highest code it keeps.
     *
     * @return array{int, int}
     */
    protected function status(Arguments $arguments, string $status): array
    {
        $classes = [
            '1xx' => 100,
            '2xx' => 200,
            '3xx' => 300,
            '4xx' => 400,
            '5xx' => 500,
        ];
        $class = $classes[$status] ?? null;

        if ($class !== null) {
            return [$class, $class + 99];
        }

        if (preg_match('/^\d{3}(?:-\d{3})?$/', $status) === 1) {
            [$from, $to] = sscanf($status, '%d-%d') + [1 => null];

            if ($from <= ($to ?? $from)) {
                return [$from, $to ?? $from];
            }
        }

        throw $arguments->invalid(argument: 'status', expected: 'a status such as 500, 5xx or 400-499', accepted: '500, 5xx or 400-499', example: 'occurrences(type: "request", status: "5xx")');
    }

    /**
     * Read one of the cases of a filter that is text.
     *
     * @template TCase of BackedEnum
     *
     * @param  list<TCase>  $accepted
     * @return TCase|null
     */
    protected function choice(Arguments $arguments, string $argument, array $accepted, string $expected, string $example): ?BackedEnum
    {
        if ($arguments->text($argument, self::EXAMPLE) === null) {
            return null;
        }

        return $arguments->choice($argument, $accepted, $expected, $example);
    }

    /**
     * Read the milliseconds a record must be slower than.
     */
    protected function slowerThan(Arguments $arguments): int|float|null
    {
        $value = $arguments->get('slower_than_ms');

        if ($value === null) {
            return null;
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return $value;
        }

        throw $arguments->invalid(argument: 'slower_than_ms', expected: 'a number of milliseconds from 0', accepted: 'a number of milliseconds from 0', example: 'occurrences(type: "request", slower_than_ms: 500)');
    }

    /**
     * Read the text a record must contain.
     */
    protected function matching(Arguments $arguments): ?string
    {
        $value = $arguments->text('matching', self::EXAMPLE);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) >= 1 && mb_strlen($value) <= self::MAXIMUM_MATCHING) {
            return $value;
        }

        throw $arguments->invalid(argument: 'matching', expected: '1 to '.self::MAXIMUM_MATCHING.' characters', accepted: 'a substring of 1 to '.self::MAXIMUM_MATCHING.' characters', example: 'occurrences(type: "log", matching: "timeout")');
    }

    /**
     * Refuse an outcome the type does not have.
     */
    protected function refuseOutcome(?Outcome $outcome, ?RecordType $type): void
    {
        if ($outcome === null) {
            return;
        }

        $outcomes = $type === null ? [] : Outcome::for($type);

        if (! in_array($outcome, $outcomes, true)) {
            $shown = json_encode($outcome->value, JSON_THROW_ON_ERROR);
            $accepted = implode(', ', array_map(fn (Outcome $outcome) => $outcome->value, $outcomes));

            throw Refusal::invalid(argument: 'outcome', expected: 'an outcome of the type', value: $shown, accepted: $accepted, example: 'occurrences(type: "job-attempt", outcome: "failed")');
        }
    }

    /**
     * Refuse a filter, or an order, that does not fit the type.
     *
     * @param  array{method: string|null, status: array{int, int}|null, status_text: string|null, outcome: Outcome|null, level: LogLevel|null, slower_than_ms: int|float|null, at_or_above: Percentile|null, matching: string|null}  $filters
     */
    protected function refuseMisfits(array $filters, Order $order, ?RecordType $type, string $selector, bool $resolvable): void
    {
        $timed = array_values(array_filter(RecordType::events(), fn (RecordType $type) => ! in_array($type, [RecordType::EXCEPTION, RecordType::LOG], true)));
        $executions = ExecutionType::records();
        $requests = [RecordType::REQUEST, RecordType::OUTGOING_REQUEST];

        $rules = [
            ['method', $filters['method'] !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['status', $filters['status'] !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['outcome', $filters['outcome'] !== null, [RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK], true, 'a call with `type` job-attempt or scheduled-task'],
            ['level', $filters['level'] !== null, [RecordType::LOG], true, 'a call with `type` log'],
            ['slower_than_ms', $filters['slower_than_ms'] !== null, $timed, false, 'a call with a timed `type`'],
            ['order', $order === Order::SLOWEST, $timed, false, 'a call with a timed `type`'],
            ['order', in_array($order, [Order::MEMORY, Order::QUERIES], true), $executions, true, 'a call with `type` request, command, job-attempt or scheduled-task'],
            ['at_or_above', $filters['at_or_above'] !== null, $timed, true, 'a call with a timed `type`'],
            ['matching', $filters['matching'] !== null, RecordType::events(), true, 'a call with a `type`'],
        ];

        foreach ($rules as [$argument, $given, $fits, $needsType, $accepted]) {
            $misfit = $type === null ? ($needsType && ! $resolvable) : ! in_array($type, $fits, true);

            if ($given && $misfit) {
                throw Refusal::conflicting(argument: $argument, with: $type === null ? $selector : $type->value, accepted: $accepted, example: self::EXAMPLE);
            }
        }
    }
}
