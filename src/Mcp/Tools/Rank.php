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
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
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
use SQLite3Result;
use SQLite3Stmt;

/**
 * @api
 */
#[Name('rank')]
#[Title('Rank')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Rank extends Tool
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
     * The most characters a label match has.
     */
    protected const MAXIMUM_MATCHING = 200;

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
        return __('firewatch::messages.tools.rank');
    }

    /**
     * Get the arguments of the tool: what to rank, by what, the window, the deploy, the limit and the format.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.rank_type_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.rank_group_argument')),
            'matching' => $schema->string()->description(__('firewatch::messages.rank_matching_argument')),
            'by' => $schema->string()->description(__('firewatch::messages.rank_by_argument')),
            'since' => $schema->string()->description(__('firewatch::messages.since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument')),
            'deploy' => $schema->string()->description(__('firewatch::messages.rank_deploy_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.rank_limit_argument')),
            'cursor' => $schema->string()->description(__('firewatch::messages.rank_cursor_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the groups of one type, worst first by a measure.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the ranking, or the breakdown of one group, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $group = $this->group($request);
        $matching = $this->matching($request);
        $explicit = $this->type($request, $group === null);
        $limit = $this->limit($request, $explicit);
        $deploy = $this->deploy($request, $explicit);

        if ($group !== null) {
            $this->refuseWithGroup($request);
        }

        if ($explicit !== null) {
            $this->measure($request, $explicit, $group);
        }

        $cursor = $request->get('cursor') === null ? null : Cursor::read(value: $request->get('cursor'), tool: $this->name(), arguments: $request->all());

        $epoch = (float) $now->format('U.u');
        $timezone = config()->string('app.timezone');
        $window = $cursor === null ? Window::read($request, $now, $timezone, $this->name()) : Window::between($cursor->since, $cursor->until, $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $by, $ranking, $breakdown] = $this->reader->snapshot(function (SQLite3 $connection) use ($request, $window, $group, $explicit, $matching, $deploy, $limit) {
                [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);
                $held = $group === null ? [] : $this->holders($connection, $group);

                if ($group !== null && $explicit !== null && $held !== [] && ! in_array($explicit, $held, true)) {
                    $holding = implode(', ', array_map(fn (RecordType $type) => $type->value, $held));

                    throw Refusal::conflicting(argument: 'type', with: 'group', accepted: "a type that holds the group: {$holding}", example: "rank(group: \"{$group}\")");
                }

                $type = $explicit ?? ($group === null ? null : $this->preferred($held));

                if ($type === null || ($group !== null && $held === [])) {
                    return [$total, $inWindow, $oldest, $newest, StoreFacts::read($connection), $type, $held, null, null, null];
                }

                $by = $this->measure($request, $type, $group);
                $ranking = new Ranking($type, $by, $window, $deploy, $matching, $group);

                $read = $inWindow !== 0;
                $facts = StoreFacts::read($connection);
                $ranked = $read && $group === null ? $ranking->read($connection) : null;
                $broken = $read && $group !== null ? $ranking->breakdown($connection, $limit) : null;

                return [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $by, $ranked, $broken];
            });
        } catch (StoreUnusable $unusable) {
            $types = $explicit === null ? [] : [$explicit];
            $blindSpots = [...BlindSpots::for($types), ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);

            $unknownHistory = History::unknown(...$retention);
            $coverage = Coverage::of($unusable, $types, $unknownHistory);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $cursor?->belongsTo($facts->meta->createdAt, $this->name());

        $types = $type === null ? [] : [$type];
        $blindSpots = [...BlindSpots::for($types), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        if ($ranking === null && $breakdown === null && ($group === null || $held !== [])) {
            $empty = Emptiness::windowEmpty($total);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $filters = $group === null
            ? ["type: {$type?->value}", ...($matching === null ? [] : ["matching: {$matching}"]), ...($deploy === null ? [] : ["deploy: {$deploy}"])]
            : ["group: {$group}", ...($explicit === null ? [] : ["type: {$explicit->value}"])];

        if ($type !== null && $by !== null && $ranking !== null) {
            return $this->ranking($request, $epoch, $timezone, $window, $coverage, $blindSpots, $filters, $inWindow, $type, $by, $cursor, $facts->meta->createdAt, $limit, $ranking);
        }

        if ($type !== null && $group !== null && $breakdown !== null) {
            return $this->breakdown($request, $epoch, $timezone, $window, $coverage, $blindSpots, $filters, $inWindow, $type, $group, $held, $limit, $breakdown);
        }

        $empty = Emptiness::noMatch($inWindow, $filters);

        return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
    }

    /**
     * Put the groups, worst first, in the envelope: the page after the cursor, cut at the limit.
     *
     * @param  list<array<string, mixed>>  $blindSpots
     * @param  list<string>  $filters
     * @param  array{rows: list<array<string, mixed>>, keys: list<array{value: int|float|null, occurrences: int, hash: string}>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}  $ranked
     */
    protected function ranking(Request $request, float $epoch, string $timezone, Window $window, Coverage $coverage, array $blindSpots, array $filters, int $inWindow, RecordType $type, Measure $by, ?Cursor $cursor, ?float $createdAt, int $limit, array $ranked): Answer
    {
        if ($ranked['rows'] === []) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $page = array_keys(array_filter($ranked['keys'], fn (array $key) => $cursor === null || Ranking::compare($key, $cursor->last) > 0));
        $rows = Rows::bound(array_map(fn (int $index) => $ranked['rows'][$index], $page), $limit);
        $truncated = [];

        if ($rows->more) {
            $last = $ranked['keys'][$page[count($rows->rows) - 1]];
            $cursorArgument = Cursor::make(tool: $this->name(), arguments: $request->all(), createdAt: $createdAt, last: $last, since: $window->since(), until: $window->until() ?? $epoch);
            $arguments = [
                ...array_diff_key($request->all(), array_flip(['cursor', 'format'])),
                'cursor' => $cursorArgument,
            ];
            $call = $this->call($arguments);
            $how = __('firewatch::messages.rank_cursor_how', ['call' => $call]);
            $entry = $rows->truncation('groups', $how);
            $truncated = $entry === null ? [] : [$entry];
        }

        $groupsRanked = count($ranked['rows']);
        $summary = trans_choice('firewatch::messages.rank_summary', $groupsRanked, [
            'count' => $groupsRanked,
            'type' => $type->value,
            'by' => $by->value,
        ]);
        $notes = $this->notes($by, $ranked);
        $next = $rows->rows === [] ? [] : [$this->breakdownCall($request, $rows->rows[0]['group'], $type)];

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $summary,
            empty: null,
            result: [
                'type' => $type->value,
                'by' => $by->value,
                'failure_definition' => Ranking::failureDefinition($type),
                'records' => $ranked['records'],
                'groups_ranked' => $groupsRanked,
                'records_without_group' => $ranked['withoutGroup'],
                'groups' => $rows->rows,
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $notes,
            truncated: $truncated,
            next: $next,
        );
    }

    /**
     * Put the deploys one group was recorded under in the envelope, in the order they were first seen.
     *
     * @param  list<array<string, mixed>>  $blindSpots
     * @param  list<string>  $filters
     * @param  list<RecordType>  $held
     * @param  array{rows: list<array<string, mixed>>, matched: int, records: int, label: string}  $breakdown
     */
    protected function breakdown(Request $request, float $epoch, string $timezone, Window $window, Coverage $coverage, array $blindSpots, array $filters, int $inWindow, RecordType $type, string $group, array $held, int $limit, array $breakdown): Answer
    {
        if ($breakdown['rows'] === []) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $shared = $request->get('type') === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);
        $truncated = $breakdown['matched'] > $limit ? [[
            'section' => 'deploys',
            'shown' => count($breakdown['rows']),
            'matched' => $breakdown['matched'],
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.rank_truncated_how'),
        ]] : [];
        $summary = trans_choice('firewatch::messages.rank_breakdown_summary', $breakdown['matched'], [
            'group' => $group,
            'count' => $breakdown['matched'],
        ]);
        $notes = $shared ? [__('firewatch::messages.rank_job_group', ['group' => $group])] : [];

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $summary,
            empty: null,
            result: [
                'type' => $type->value,
                'group' => $group,
                'label' => $breakdown['label'],
                'records' => $breakdown['records'],
                'deploys' => $breakdown['rows'],
            ],
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $notes,
            truncated: $truncated,
        );
    }

    /**
     * Get the call that breaks a group down by deploy, in the window of this one.
     *
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    protected function breakdownCall(Request $request, string $group, RecordType $type): array
    {
        $arguments = ['group' => $group];

        if ($type === RecordType::QUEUED_JOB) {
            $arguments['type'] = $type->value;
        }

        foreach (['since', 'until'] as $name) {
            if ($request->get($name) !== null) {
                $arguments[$name] = $request->get($name);
            }
        }

        return [
            'tool' => $this->name(),
            'arguments' => $arguments,
            'why' => __('firewatch::messages.rank_next_group'),
        ];
    }

    /**
     * Get a call of the tool as it is written, from its arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function call(array $arguments): string
    {
        return $this->name().'('.implode(', ', array_map(fn (string $name, mixed $value) => $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), array_keys($arguments), $arguments)).')';
    }

    /**
     * Read the types that hold a group in the store, in the order of the types.
     *
     * @return list<RecordType>
     */
    protected function holders(SQLite3 $connection, string $group): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT DISTINCT type FROM records WHERE group_hash = :group');
        $statement->bindValue(':group', $group);

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $held = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $held[] = is_string($row[0]) ? RecordType::tryFrom($row[0]) : null;
        }

        return array_values(array_filter(Measure::types(), fn (RecordType $type) => in_array($type, $held, true)));
    }

    /**
     * Pick the type of a group that no type was asked for: a job group is held by job attempts and dispatches, and the attempts carry the execution measures.
     *
     * @param  list<RecordType>  $held
     */
    protected function preferred(array $held): ?RecordType
    {
        return in_array(RecordType::JOB_ATTEMPT, $held, true) ? RecordType::JOB_ATTEMPT : ($held[0] ?? null);
    }

    /**
     * Get the notes of the answer: that the order fell back to the maximum, and that records without a duration are left out of the durations.
     *
     * @param  array{rows: list<array<string, mixed>>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}  $ranked
     * @return list<string>
     */
    protected function notes(Measure $by, array $ranked): array
    {
        $notes = [];

        if ($ranked['orderedBy'] !== $by && ($floor = $by->floor()) !== null) {
            $notes[] = __('firewatch::messages.rank_fallback', [
                'statistic' => $floor[0],
                'needed' => $floor[1],
            ]);
        }

        if ($ranked['untimed'] > 0) {
            $notes[] = trans_choice('firewatch::messages.rank_untimed', $ranked['untimed'], ['count' => $ranked['untimed']]);
        }

        return $notes;
    }

    /**
     * Read the type to rank, which must be one that has groups.
     */
    protected function type(Request $request, bool $required): ?RecordType
    {
        $types = implode(', ', array_map(fn (RecordType $type) => $type->value, Measure::types()));
        $example = 'rank(type: "request", by: "p95_duration")';
        $value = $request->get('type');

        if ($value === null) {
            return $required ? throw Refusal::missing(argument: 'type', accepted: $types, example: $example) : null;
        }

        $type = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($type === null || ! in_array($type, Measure::types(), true)) {
            $shown = json_encode($value, JSON_THROW_ON_ERROR);

            throw Refusal::invalid(argument: 'type', expected: 'one of the types with groups', value: $shown, accepted: $types, example: $example);
        }

        return $type;
    }

    /**
     * Read the measure to rank by, which must fit the type; the default is the 95th percentile of the duration, or how often for an exception.
     */
    protected function measure(Request $request, RecordType $type, ?string $group): Measure
    {
        $value = $request->get('by');
        $default = Measure::default($type);

        if ($value === null) {
            return $default;
        }

        $measure = is_string($value) ? Measure::tryFrom($value) : null;

        // A breakdown by deploy has no query counter to rank by.
        $fitting = array_filter(Measure::for($type), fn (Measure $fitting) => $group === null || $fitting !== Measure::QUERIES);

        if ($measure !== null && in_array($measure, $fitting, true)) {
            return $measure;
        }

        $expected = "a measure of {$type->value}".($group === null ? '' : ' in a breakdown of one group');
        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', array_map(fn (Measure $measure) => $measure->value, $fitting));

        throw Refusal::invalid(argument: 'by', expected: $expected, value: $shown, accepted: $accepted, example: "rank(type: \"{$type->value}\", by: \"{$default->value}\")");
    }

    /**
     * Read the most rows to list, from 1 to 100.
     */
    protected function limit(Request $request, ?RecordType $type): int
    {
        $value = $request->get('limit');

        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (is_int($value) && $value >= 1 && $value <= self::MAXIMUM_LIMIT) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: ($type === null ? 'rank(group: "<group id>", limit: ' : "rank(type: \"{$type->value}\", limit: ").self::DEFAULT_LIMIT.')');
    }

    /**
     * Read the exact deploy the records are restricted to, or null for all of them.
     */
    protected function deploy(Request $request, ?RecordType $type): ?string
    {
        $value = $request->get('deploy');

        if ($value === null || is_string($value)) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'deploy', expected: 'an exact deploy string', value: $shown, accepted: 'an exact deploy string', example: ($type === null ? 'rank(type: "request", deploy: "v1")' : "rank(type: \"{$type->value}\", deploy: \"v1\")"));
    }

    /**
     * Read the group to break down, which is a 32-character lowercase hex group hash.
     */
    protected function group(Request $request): ?string
    {
        $value = $request->get('group');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'group', expected: 'a 32-character lowercase hex group id', value: $shown, accepted: 'a 32-character lowercase hex group id', example: 'rank(group: "<group id>")');
    }

    /**
     * Read the text a group's label must contain, from 1 to 200 characters.
     */
    protected function matching(Request $request): ?string
    {
        $value = $request->get('matching');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && mb_strlen($value) >= 1 && mb_strlen($value) <= self::MAXIMUM_MATCHING) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'matching', expected: '1 to '.self::MAXIMUM_MATCHING.' characters', value: $shown, accepted: 'a text of 1 to '.self::MAXIMUM_MATCHING.' characters', example: 'rank(type: "request", matching: "orders")');
    }

    /**
     * Refuse what a breakdown of one group has no use for: a label to match, a deploy to restrict to, and a cursor, as it lists its deploys in one answer.
     */
    protected function refuseWithGroup(Request $request): void
    {
        $accepting = [
            'matching' => 'a call with `type` and `matching`',
            'deploy' => 'a call without `deploy`',
            'cursor' => 'a call without `cursor`',
        ];

        foreach ($accepting as $argument => $accepted) {
            if ($request->get($argument) !== null) {
                throw Refusal::conflicting(argument: $argument, with: 'group', accepted: $accepted, example: 'rank(group: "<group id>")');
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
