<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Listing;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
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
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
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
     * The orders a list can have.
     *
     * @var list<string>
     */
    protected const ORDERS = ['recent', 'slowest', 'memory', 'queries'];

    /**
     * The levels of a log, least severe first.
     *
     * @var list<string>
     */
    protected const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * The outcomes of the types that have them.
     *
     * @var array<string, list<string>>
     */
    protected const OUTCOMES = [
        'job-attempt' => ['processed', 'failed', 'released'],
        'scheduled-task' => ['processed', 'failed', 'skipped'],
    ];

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
     * Get the arguments of the tool: the selectors, the order, the filters, the window, the deploy, the limit and the format.
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
            'since' => $schema->string()->description(__('firewatch::messages.since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument')),
            'deploy' => $schema->string()->description(__('firewatch::messages.rank_deploy_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.occurrences_limit_argument')),
            'cursor' => $schema->string()->description(__('firewatch::messages.occurrences_cursor_argument')),
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
        $group = $this->group($request);
        $type = $this->type($request);
        $ids = [
            'execution_id' => $this->id($request, 'execution_id'),
            'trace_id' => $this->id($request, 'trace_id'),
            'job_id' => $this->id($request, 'job_id'),
            'user_id' => $this->id($request, 'user_id'),
        ];

        if ($group === null && $type === null && array_filter($ids, fn (?string $id) => $id !== null) === []) {
            throw Refusal::missing('selector', 'group, type, execution_id, trace_id, job_id or user_id', self::EXAMPLE);
        }

        $order = $this->order($request);
        $limit = $this->limit($request);
        $deploy = $this->deploy($request);
        $with = $group !== null ? 'group' : (array_key_first(array_filter($ids)) ?? 'type');
        $filters = $this->filters($request, $type, $group !== null, $with, $order);

        $cursor = $request->get('cursor') === null ? null : Cursor::read($request->get('cursor'), $this->name(), $request->all(), $this->key(...));

        $epoch = (float) $now->format('U.u');
        $timezone = config()->string('app.timezone');
        $window = $cursor === null ? Window::read($request, $now, $timezone, $this->name()) : Window::between($cursor->since, $cursor->until, $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $typesRead = $type === null ? RecordType::events() : [$type];
        $structural = BlindSpots::for($typesRead, actor: $ids['user_id'] !== null);

        try {
            $read = $this->reader->snapshot(function (SQLite3 $connection) use ($window, $order, $group, $type, $ids, $deploy, $filters, $limit, $with, $cursor) {
                [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);
                $facts = StoreFacts::read($connection);

                if ($inWindow === 0) {
                    return compact('total', 'inWindow', 'oldest', 'newest', 'facts');
                }

                $held = $group === null ? [] : Listing::typesOf($connection, $group);
                $resolved = $type ?? (count($held) === 1 ? $held[0] : null);

                if ($held !== [] && $type !== null && ! in_array($type, $held, true)) {
                    throw Refusal::conflicting('group', 'type', 'a `type` that holds the group: '.implode(', ', array_map(fn (RecordType $held) => $held->value, $held)), self::EXAMPLE);
                }

                if ($group !== null && $held === []) {
                    return compact('total', 'inWindow', 'oldest', 'newest', 'facts');
                }

                $this->refuseMisfits($filters, $order, $resolved, $with, false);

                if ($filters['outcome'] !== null) {
                    $this->outcome($filters['outcome'], $resolved);
                }

                $listing = new Listing($window, $order, $group, $resolved, $ids['execution_id'], $ids['trace_id'], $ids['job_id'], $ids['user_id'], $deploy, $filters['method'], $filters['status'], $filters['outcome'], $filters['levels'], $filters['slower_than_ms'], $filters['matching']);
                $baseline = null;

                if ($filters['at_or_above'] !== null) {
                    $measured = $listing->baseline($connection, $filters['at_or_above']);
                    $baseline = [
                        ...$measured,
                        'percentile' => $filters['at_or_above'],
                    ];
                }

                $threshold = $baseline['threshold'] ?? null;
                $listed = $listing->rows($connection, $limit, $threshold, $cursor?->last);
                $sites = $group !== null && $resolved === RecordType::QUERY ? $listing->callSites($connection, $threshold) : null;

                return compact('total', 'inWindow', 'oldest', 'newest', 'facts', 'baseline', 'listed', 'sites');
            });
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...$structural, ...$this->conditions->for(null, $typesRead, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);

            return new Answer($this->name(), $epoch, $timezone, $window, $empty->summary(), $empty, [], Coverage::of($unusable, $typesRead, History::unknown(...$retention)), $blindSpots);
        }

        $createdAt = $read['facts']->meta['created_at'] ?? '';

        $cursor?->belongsTo($createdAt, $this->name());

        $blindSpots = [...$structural, ...$this->conditions->for($read['facts'], $typesRead, $window)];
        $history = History::of($read['facts']->meta, $typesRead, ...$retention);

        if ($read['total'] === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);

            return new Answer($this->name(), $epoch, $timezone, $window, $empty->summary(), $empty, [], new Coverage(CoverageState::EMPTY, $typesRead, $history, records: 0), $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $typesRead, $history, oldest: $read['oldest'], newest: $read['newest'], records: $read['total']);

        if ($read['inWindow'] === 0) {
            $empty = Emptiness::windowEmpty($read['total']);

            return new Answer($this->name(), $epoch, $timezone, $window, $empty->summary(), $empty, [], $coverage, $blindSpots);
        }

        if (! isset($read['listed']) || $read['listed']['rows'] === []) {
            $given = [
                'group' => $group,
                'type' => $type?->value,
                ...$ids,
                'method' => $filters['method'],
                'status' => $filters['status_text'],
                'outcome' => $filters['outcome'],
                'level' => $filters['level'],
                'slower_than_ms' => $filters['slower_than_ms'],
                'at_or_above' => $filters['at_or_above'],
                'matching' => $filters['matching'],
                'deploy' => $deploy,
            ];
            $named = array_filter($given, fn (mixed $value) => $value !== null);
            $empty = Emptiness::noMatch($read['inWindow'], array_map(fn (string $name, mixed $value) => "{$name}: {$value}", array_keys($named), $named));

            return new Answer($this->name(), $epoch, $timezone, $window, $empty->summary(), $empty, [], $coverage, $blindSpots);
        }

        $rows = Rows::bound($read['listed']['rows'], $limit);
        $result = [
            'order' => $order,
            'rows' => $rows->rows,
        ];

        if ($read['baseline'] !== null) {
            $result['baseline'] = $this->baseline($read['baseline']);
        }

        if ($read['sites'] !== null) {
            $result['call_sites'] = $read['sites'];
        }

        $notes = [];

        if ($read['baseline'] !== null && $read['baseline']['threshold'] === null) {
            $notes[] = __('firewatch::messages.occurrences_baseline_withheld', [
                'percentile' => $read['baseline']['percentile'],
                'have' => $read['baseline']['samples'],
                'needed' => $read['baseline']['needed'],
            ]);
        }

        if ($ids['user_id'] !== null) {
            $notes[] = __('firewatch::messages.occurrences_user_only');
        }

        $count = count($rows->rows);
        $summary = trans_choice('firewatch::messages.occurrences_summary', $count, [
            'count' => $count,
            'order' => $order,
        ]);
        $truncation = null;

        if ($rows->more) {
            $last = $read['listed']['keys'][$count - 1];
            $continued = Cursor::make($this->name(), $request->all(), $createdAt, $last, $window->since(), $window->until() ?? $epoch);
            $arguments = [
                ...array_diff_key($request->all(), ['cursor' => 0, 'format' => 0]),
                'cursor' => $continued,
            ];
            $truncation = $rows->truncation('rows', __('firewatch::messages.occurrences_cursor_how', ['call' => $this->call($arguments)]));
        }

        $first = $rows->rows[0]['group'];
        $next = [];

        if ($group === null && $first !== null) {
            $next[] = [
                'tool' => 'rank',
                'arguments' => ['group' => $first],
                'why' => __('firewatch::messages.occurrences_next_group'),
            ];
        }

        return new Answer(
            $this->name(),
            $epoch,
            $timezone,
            $window,
            $summary,
            null,
            $result,
            $coverage,
            $blindSpots,
            $notes,
            $truncation === null ? [] : [$truncation],
            $next,
        );
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
     * @param  array{samples: int, needed: int, threshold: int|float|null, percentile: string}  $baseline
     * @return array<string, mixed>
     */
    protected function baseline(array $baseline): array
    {
        $withheld = null;
        $thresholdMilliseconds = null;

        if ($baseline['threshold'] === null) {
            $withheld = [
                'reason' => 'sample_too_small',
                'have' => $baseline['samples'],
                'needed' => $baseline['needed'],
            ];
        } else {
            $thresholdMilliseconds = $baseline['threshold'] / 1000;
        }

        return [
            'percentile' => $baseline['percentile'],
            'threshold_ms' => $thresholdMilliseconds,
            'samples' => $baseline['samples'],
            'withheld' => $withheld,
        ];
    }

    /**
     * Read the group, 32 lowercase hexadecimal characters.
     */
    protected function group(Request $request): ?string
    {
        $value = $request->get('group');

        if ($value === null || (is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1)) {
            return $value;
        }

        throw Refusal::invalid('group', 'a group id of 32 lowercase hex characters', json_encode($value, JSON_THROW_ON_ERROR), 'the `group` of a row of `rank` or `occurrences`', 'occurrences(group: "'.str_repeat('0', 32).'")');
    }

    /**
     * Read the type of the records, one of the twelve.
     */
    protected function type(Request $request): ?RecordType
    {
        $value = $request->get('type');

        if ($value === null) {
            return null;
        }

        $type = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($type === null || $type === RecordType::USER) {
            throw Refusal::invalid('type', 'one of the record types', json_encode($value, JSON_THROW_ON_ERROR), implode(', ', array_map(fn (RecordType $type) => $type->value, RecordType::events())), self::EXAMPLE);
        }

        return $type;
    }

    /**
     * Read an identifier, which is text of at least one character.
     */
    protected function id(Request $request, string $argument): ?string
    {
        $value = $request->get($argument);

        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw Refusal::invalid($argument, 'an id of at least one character', json_encode($value, JSON_THROW_ON_ERROR), "the `{$argument}` of a record", "occurrences({$argument}: \"abc\")");
    }

    /**
     * Read the order of the list.
     */
    protected function order(Request $request): string
    {
        $value = $request->get('order');

        if ($value === null) {
            return 'recent';
        }

        if (is_string($value) && in_array($value, self::ORDERS, true)) {
            return $value;
        }

        throw Refusal::invalid('order', 'one of the orders', json_encode($value, JSON_THROW_ON_ERROR), implode(', ', self::ORDERS), 'occurrences(type: "request", order: "slowest")');
    }

    /**
     * Read the most rows to list, from 1 to 100.
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

        throw Refusal::invalid('limit', '1 to '.self::MAXIMUM_LIMIT, json_encode($value, JSON_THROW_ON_ERROR), 'a whole number from 1 to '.self::MAXIMUM_LIMIT, 'occurrences(type: "request", limit: '.self::DEFAULT_LIMIT.')');
    }

    /**
     * Read the exact deploy the records are restricted to, or null for all of them.
     */
    protected function deploy(Request $request): ?string
    {
        $value = $request->get('deploy');

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw Refusal::invalid('deploy', 'an exact deploy string', json_encode($value, JSON_THROW_ON_ERROR), 'an exact deploy string', 'occurrences(type: "request", deploy: "v1")');
    }

    /**
     * Read the filters, each checked against the type when it is known and for its own value.
     *
     * @return array{method: string|null, status: array{int, int}|null, status_text: string|null, outcome: string|null, level: string|null, levels: list<string>|null, slower_than_ms: int|float|null, at_or_above: string|null, matching: string|null}
     */
    protected function filters(Request $request, ?RecordType $type, bool $hasGroup, string $with, string $order): array
    {
        $filters = [
            'method' => $this->text($request, 'method'),
            'status_text' => $this->text($request, 'status'),
            'outcome' => $this->text($request, 'outcome'),
            'level' => $this->text($request, 'level'),
            'slower_than_ms' => $request->get('slower_than_ms'),
            'at_or_above' => $this->text($request, 'at_or_above'),
            'matching' => $this->text($request, 'matching'),
        ];

        $this->refuseMisfits($filters, $order, $type, $with, $hasGroup);

        $slower = $filters['slower_than_ms'];

        if ($slower !== null && (is_bool($slower) || ! (is_int($slower) || is_float($slower)) || $slower < 0)) {
            throw Refusal::invalid('slower_than_ms', 'a number of milliseconds from 0', json_encode($slower, JSON_THROW_ON_ERROR), 'a number of milliseconds from 0', 'occurrences(type: "request", slower_than_ms: 500)');
        }

        $levels = null;

        if ($filters['level'] !== null) {
            $at = array_search($filters['level'], self::LEVELS, true);

            if ($at === false) {
                throw Refusal::invalid('level', 'a log level', json_encode($filters['level'], JSON_THROW_ON_ERROR), implode(', ', self::LEVELS), 'occurrences(type: "log", level: "error")');
            }

            $levels = array_slice(self::LEVELS, $at);
        }

        if ($filters['at_or_above'] !== null && ! in_array($filters['at_or_above'], ['median', 'p95'], true)) {
            throw Refusal::invalid('at_or_above', 'median or p95', json_encode($filters['at_or_above'], JSON_THROW_ON_ERROR), 'median, p95', 'occurrences(type: "request", at_or_above: "p95")');
        }

        $matching = $filters['matching'];

        if ($matching !== null && (mb_strlen($matching) < 1 || mb_strlen($matching) > self::MAXIMUM_MATCHING)) {
            throw Refusal::invalid('matching', '1 to '.self::MAXIMUM_MATCHING.' characters', json_encode($matching, JSON_THROW_ON_ERROR), 'a substring of 1 to '.self::MAXIMUM_MATCHING.' characters', 'occurrences(type: "log", matching: "timeout")');
        }

        if ($type !== null && $filters['outcome'] !== null) {
            $this->outcome($filters['outcome'], $type);
        }

        return [
            'method' => $filters['method'],
            'status' => $filters['status_text'] === null ? null : $this->status($filters['status_text']),
            'status_text' => $filters['status_text'],
            'outcome' => $filters['outcome'],
            'level' => $filters['level'],
            'levels' => $levels,
            'slower_than_ms' => $slower,
            'at_or_above' => $filters['at_or_above'],
            'matching' => $matching,
        ];
    }

    /**
     * Read a filter that is text.
     */
    protected function text(Request $request, string $argument): ?string
    {
        $value = $request->get($argument);

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw Refusal::invalid($argument, 'text', json_encode($value, JSON_THROW_ON_ERROR), 'text', self::EXAMPLE);
    }

    /**
     * Read a status as the lowest and the highest code it keeps: `500`, `5xx` or `400-499`.
     *
     * @return array{int, int}
     */
    protected function status(string $status): array
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

        throw Refusal::invalid('status', 'a status such as 500, 5xx or 400-499', json_encode($status, JSON_THROW_ON_ERROR), '500, 5xx or 400-499', 'occurrences(type: "request", status: "5xx")');
    }

    /**
     * Refuse an outcome the type does not have.
     */
    protected function outcome(string $outcome, ?RecordType $type): void
    {
        $outcomes = $type === null ? [] : (self::OUTCOMES[$type->value] ?? []);

        if (! in_array($outcome, $outcomes, true)) {
            throw Refusal::invalid('outcome', 'an outcome of the type', json_encode($outcome, JSON_THROW_ON_ERROR), implode(', ', $outcomes), 'occurrences(type: "job-attempt", outcome: "failed")');
        }
    }

    /**
     * Refuse a filter, or an order, that does not fit the type, naming what it fits.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function refuseMisfits(array $filters, string $order, ?RecordType $type, string $with, bool $resolvable): void
    {
        $timed = array_values(array_filter(RecordType::events(), fn (RecordType $type) => ! in_array($type, [RecordType::EXCEPTION, RecordType::LOG], true)));
        $executions = [RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK];
        $requests = [RecordType::REQUEST, RecordType::OUTGOING_REQUEST];

        $rules = [
            ['method', $filters['method'] !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['status', $filters['status_text'] !== null, $requests, true, 'a call with `type` request or outgoing-request'],
            ['outcome', $filters['outcome'] !== null, [RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK], true, 'a call with `type` job-attempt or scheduled-task'],
            ['level', $filters['level'] !== null, [RecordType::LOG], true, 'a call with `type` log'],
            ['slower_than_ms', $filters['slower_than_ms'] !== null, $timed, false, 'a call with a timed `type`'],
            ['order', $order === 'slowest', $timed, false, 'a call with a timed `type`'],
            ['order', in_array($order, ['memory', 'queries'], true), $executions, true, 'a call with `type` request, command, job-attempt or scheduled-task'],
            ['at_or_above', $filters['at_or_above'] !== null, $timed, true, 'a call with a timed `type`'],
            ['matching', $filters['matching'] !== null, RecordType::events(), true, 'a call with a `type`'],
        ];

        foreach ($rules as [$argument, $given, $fits, $needsType, $accepted]) {
            $misfit = $type === null ? ($needsType && ! $resolvable) : ! in_array($type, $fits, true);

            if ($given && $misfit) {
                throw Refusal::conflicting($argument, $type->value ?? $with, $accepted, self::EXAMPLE);
            }
        }
    }

    /**
     * Count all records and those of the window, and find the span the records cover.
     *
     * @return array{int, int, float|null, float|null}
     */
    protected function count(SQLite3 $connection, Window $window): array
    {
        $condition = $window->condition();

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("SELECT count(*), count(*) FILTER (WHERE {$condition}), min(started_at), max(started_at) FROM records");

        $window->bind($statement);

        /** @var SQLite3Result $result */
        $result = $statement->execute();

        /** @var array{int, int, float|null, float|null} */
        return $result->fetchArray(SQLITE3_NUM);
    }
}
