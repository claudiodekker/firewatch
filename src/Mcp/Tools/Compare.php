<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Comparison;
use ClaudioDekker\Firewatch\Mcp\ComparisonReason;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\DeployPair;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TimeGrammar;
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
#[Name('compare')]
#[Title('Compare')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Compare extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The groups an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 20;

    /**
     * The most groups an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

    /**
     * How much older than the split the oldest record of the store must be for the note to move `since`, in seconds.
     */
    protected const EARLIER_CHANGES_SECONDS = 3600;

    /**
     * What a group id is, as the refusal of a malformed one says it.
     */
    protected const GROUP_ID_DESCRIPTION = 'a 32-character lowercase hex group id';

    /**
     * A valid call, as a refusal shows it.
     */
    protected const EXAMPLE = 'compare(type: "request", split_at: "<now of an earlier answer>")';

    /**
     * A valid call by a deploy pair, as a refusal shows it.
     */
    protected const PAIR_EXAMPLE = 'compare(type: "request", deploy_before: "<deploy>", deploy_after: "<another deploy>")';

    /**
     * The boundaries a call accepts, as a refusal names them.
     */
    protected const BOUNDARIES = 'exactly one boundary: `split_at` (a time, such as the now of an earlier answer), or `deploy_before` with `deploy_after` (exact deploy strings)';

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
        return __('firewatch::messages.tools.compare');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.compare_type_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.compare_group_argument')),
            'split_at' => $schema->string()->description(__('firewatch::messages.compare_split_at_argument')),
            'deploy_before' => $schema->string()->description(__('firewatch::messages.compare_deploy_before_argument')),
            'deploy_after' => $schema->string()->description(__('firewatch::messages.compare_deploy_after_argument')),
            'by' => $schema->string()->description(__('firewatch::messages.compare_by_argument')),
            'since' => $schema->string()->description(__('firewatch::messages.compare_since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.compare_until_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.compare_limit_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with every group of one type, or one group, before the split against after it.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the comparison, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $group = $this->group($request);
        $explicit = $this->type($request, $group === null);
        $limit = $this->limit($request, $group);

        if ($explicit !== null) {
            $this->measure($request, $explicit);
        }

        $timezone = config()->string('app.timezone');
        $given = Window::read($request, $now, timezone: $timezone, tool: $this->name());
        $boundary = $this->boundary($request, $now, $timezone);
        $anchored = ! $boundary instanceof DeployPair;
        $pairNotes = $anchored ? [] : [__('firewatch::messages.compare_deploy_pair_note')];

        $epoch = Instant::of($now);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $window, $comparison] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $request, $given, $epoch, $boundary, $explicit, $group, $limit));
        } catch (StoreUnusable $unusable) {
            $types = $explicit === null ? [] : [$explicit];
            $window = Window::between($given->since(), $given->until() ?? $epoch, $timezone);
            $blindSpots = [...BlindSpots::for($types, anchored: $anchored), ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots, notes: $pairNotes);
        }

        $types = $type === null ? [] : [$type];
        $blindSpots = [...BlindSpots::for($types, anchored: $anchored), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots, notes: $pairNotes);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total, straddling: $comparison?->straddling);
        $filters = $this->filters($group, $explicit, $boundary);

        if ($group !== null && $held === []) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots, notes: $pairNotes);
        }

        if ($inWindow === 0) {
            $empty = Emptiness::windowEmpty($total);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots, notes: $pairNotes);
        }

        if ($comparison === null || $comparison->isEmpty()) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots, notes: $pairNotes);
        }

        $shared = $explicit === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($comparison, $boundary),
            empty: null,
            result: $comparison->result(),
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($request, $comparison, $oldest, $boundary, $shared ? $group : null),
            truncated: $this->truncated($comparison),
            next: $this->next($comparison, $window, $boundary),
            cuttable: ['groups'],
            recount: $comparison->recounted(...),
        );
    }

    /**
     * Get the filters of the call as an empty answer names them: the group when one is given, then the type the call names, then a deploy pair.
     *
     * @return list<string>
     */
    protected function filters(?string $group, ?RecordType $type, float|DeployPair $boundary): array
    {
        $filters = [];

        if ($group !== null) {
            $filters[] = "group: {$group}";
        }

        if ($type !== null) {
            $filters[] = "type: {$type->value}";
        }

        if ($boundary instanceof DeployPair) {
            $filters = [...$filters, ...$boundary->filters()];
        }

        return $filters;
    }

    /**
     * Resolve the type and the window against the store, refuse a split outside the window, and compare, in one snapshot.
     *
     * @return array{int, int, float|null, float|null, StoreFacts, RecordType|null, list<RecordType>, Window, Comparison|null}
     */
    protected function load(SQLite3 $connection, Request $request, Window $given, float $epoch, float|DeployPair $boundary, ?RecordType $explicit, ?string $group, int $limit): array
    {
        $facts = StoreFacts::read($connection);
        $held = $group === null ? [] : Ranking::holders($connection, $group);

        if ($group !== null && $explicit !== null && $held !== [] && ! in_array($explicit, $held, true)) {
            $holding = implode(', ', array_map(fn (RecordType $type) => $type->value, $held));

            throw Refusal::conflicting(argument: 'type', with: 'group', accepted: "a type that holds the group: {$holding}", example: "compare(group: \"{$group}\", split_at: \"<time>\")");
        }

        $type = $explicit ?? Ranking::preferred($held);
        $by = $type === null ? null : $this->measure($request, $type);
        $coverageStart = $type === null ? null : History::of($facts->meta, [$type])->from;

        $window = $this->window($given, $epoch, $coverageStart);

        if (is_float($boundary) && ($boundary <= ($window->since() ?? -INF) || $boundary >= ($window->until() ?? $epoch))) {
            throw Refusal::splitOutsideWindow(self::EXAMPLE);
        }

        [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);

        $comparison = $type === null || $by === null || $inWindow === 0
            ? null
            : Comparison::of($connection, $type, $by, $window, $boundary, $coverageStart, $group, $limit);

        return [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $window, $comparison];
    }

    /**
     * Get the window of the call: an omitted `since` is the type's coverage start and an omitted `until` the store clock.
     */
    protected function window(Window $given, float $epoch, ?float $coverageStart): Window
    {
        $since = $given->since() ?? $coverageStart;
        $until = $given->until() ?? $epoch;

        if ($since !== null && $since >= $until) {
            throw Refusal::window(since: $since, until: $until, timezone: $given->timezone(), tool: $this->name());
        }

        return Window::between($since, $until, $given->timezone());
    }

    /**
     * Get the summary of a comparison: the changes counted over every group, or why it could not run.
     */
    protected function summary(Comparison $comparison, float|DeployPair $boundary): string
    {
        [$reason, $side] = $comparison->unevaluated() ?? [null, null];

        if ($reason === ComparisonReason::EMPTY_SIDE && $boundary instanceof DeployPair) {
            return __('firewatch::messages.compare_empty_deploy_summary', [
                'deploy' => $side === 'before' ? $boundary->before : $boundary->after,
                'side' => $side,
                'type' => $comparison->type->value,
            ]);
        }

        if ($reason !== null) {
            return __("firewatch::messages.compare_{$reason->value}_summary", [
                'side' => $side,
                'type' => $comparison->type->value,
            ]);
        }

        $counted = [
            'groups' => $comparison->matched(),
            'type' => $comparison->type->value,
            'by' => $comparison->by->value,
            'changes' => $comparison->counts(),
        ];

        if ($boundary instanceof DeployPair) {
            return trans_choice('firewatch::messages.compare_pair_summary', $comparison->matched(), [
                ...$counted,
                'before' => $boundary->before,
                'after' => $boundary->after,
            ]);
        }

        return trans_choice('firewatch::messages.compare_summary', $comparison->matched(), $counted);
    }

    /**
     * Get the notes of a comparison, in the fixed order: a deploy pair's ends with what a pair cannot separate.
     *
     * @return list<string>
     */
    protected function notes(Request $request, Comparison $comparison, ?float $oldest, float|DeployPair $boundary, ?string $shared): array
    {
        $notes = [];

        if ($shared !== null) {
            $notes[] = __('firewatch::messages.rank_job_group', ['group' => $shared]);
        }

        if ($comparison->unevaluated() !== null) {
            $notes[] = $boundary instanceof DeployPair ? __('firewatch::messages.compare_pair_not_evaluated_note') : __('firewatch::messages.compare_not_evaluated_note');
        }

        if ($boundary instanceof DeployPair) {
            return [...$notes, __('firewatch::messages.compare_deploy_pair_note')];
        }

        if ($comparison->earlier !== null && $comparison->earlier['records'] > 0) {
            $notes[] = trans_choice('firewatch::messages.'.($comparison->earlier['more'] ? 'compare_earlier_more_note' : 'compare_earlier_note'), $comparison->earlier['records'], [
                'count' => $comparison->earlier['records'],
                'type' => $comparison->type->value,
            ]);
        }

        if ($request->get('since') === null && $oldest !== null && $oldest < $boundary - self::EARLIER_CHANGES_SECONDS) {
            $notes[] = __('firewatch::messages.compare_move_since_note');
        }

        return $notes;
    }

    /**
     * Get the entries of the groups the limit cut and of the deploys left unlisted, if any.
     *
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    protected function truncated(Comparison $comparison): array
    {
        $groups = $comparison->groups->truncation(section: 'groups', how: __('firewatch::messages.compare_truncated_how'), matched: $comparison->matched());
        $deploys = $comparison->deploys?->truncation(section: 'deploys', how: __('firewatch::messages.compare_deploys_truncated_how'));

        return array_values(array_filter([$groups, $deploys]));
    }

    /**
     * Get the calls that list the records of the first group listed, of each deploy that holds some on a deploy pair, and break it down by deploy, over the same window.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Comparison $comparison, Window $window, float|DeployPair $boundary): array
    {
        $group = $comparison->first();

        if ($group === null) {
            return [];
        }

        $type = $comparison->type === RecordType::QUEUED_JOB ? ['type' => $comparison->type->value] : [];
        $deploys = $boundary instanceof DeployPair ? $this->holding($comparison, $boundary) : [null];

        $occurrences = array_map(fn (?string $deploy) => [
            'tool' => 'occurrences',
            'arguments' => [
                'group' => $group,
                ...$type,
                ...($deploy === null ? [] : ['deploy' => $deploy]),
                ...$window->arguments(),
            ],
            'why' => $deploy === null ? __('firewatch::messages.compare_next_occurrences') : __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => $deploy]),
        ], $deploys);

        return [
            ...$occurrences,
            [
                'tool' => 'rank',
                'arguments' => [
                    'group' => $group,
                    ...$type,
                    ...$window->arguments(),
                ],
                'why' => __('firewatch::messages.compare_next_rank'),
            ],
        ];
    }

    /**
     * Get the deploys of the pair under which the first group listed has records.
     *
     * @return list<string>
     */
    protected function holding(Comparison $comparison, DeployPair $pair): array
    {
        $row = $comparison->groups->rows[0];
        $deploys = [];

        if ($row['beforeRecords'] > 0) {
            $deploys[] = $pair->before;
        }

        if ($row['afterRecords'] > 0) {
            $deploys[] = $pair->after;
        }

        return $deploys;
    }

    /**
     * Read the type to compare.
     */
    protected function type(Request $request, bool $required): ?RecordType
    {
        $types = implode(', ', array_map(fn (RecordType $type) => $type->value, Measure::types()));
        $value = $request->get('type');

        if ($value === null) {
            return $required ? throw Refusal::missing(argument: 'type', accepted: $types, example: self::EXAMPLE) : null;
        }

        $type = is_string($value) ? RecordType::tryFrom($value) : null;

        if ($type === null || ! in_array($type, Measure::types(), true)) {
            $shown = json_encode($value, JSON_THROW_ON_ERROR);

            throw Refusal::invalid(argument: 'type', expected: 'one of the types with groups', value: $shown, accepted: $types, example: self::EXAMPLE);
        }

        return $type;
    }

    /**
     * Read the measure to compare by.
     */
    protected function measure(Request $request, RecordType $type): Measure
    {
        $value = $request->get('by');
        $default = Measure::default($type);

        if ($value === null) {
            return $default;
        }

        $measure = is_string($value) ? Measure::tryFrom($value) : null;
        $fitting = Measure::compared($type);

        if ($measure !== null && in_array($measure, $fitting, true)) {
            return $measure;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', array_map(fn (Measure $measure) => $measure->value, $fitting));

        throw Refusal::invalid(argument: 'by', expected: "a measure of {$type->value}", value: $shown, accepted: $accepted, example: "compare(type: \"{$type->value}\", split_at: \"<time>\", by: \"{$default->value}\")");
    }

    /**
     * Read the most groups to list, which a comparison of one group has no use for.
     */
    protected function limit(Request $request, ?string $group): int
    {
        $value = $request->get('limit');

        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if ($group !== null) {
            throw Refusal::conflicting(argument: 'limit', with: 'group', accepted: 'a call without `limit`: one group is one row', example: "compare(group: \"{$group}\", split_at: \"<time>\")");
        }

        if (is_int($value) && $value >= 1 && $value <= self::MAXIMUM_LIMIT) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: 'compare(type: "request", split_at: "<time>", limit: '.self::DEFAULT_LIMIT.')');
    }

    /**
     * Read the group to compare.
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

        throw Refusal::invalid(argument: 'group', expected: self::GROUP_ID_DESCRIPTION, value: $shown, accepted: self::GROUP_ID_DESCRIPTION, example: 'compare(group: "<group id>", split_at: "<time>")');
    }

    /**
     * Read the one boundary of the call: the instant the window is split at, or the deploy pair.
     */
    protected function boundary(Request $request, CarbonImmutable $now, string $timezone): float|DeployPair
    {
        $split = $request->get('split_at');
        $before = $request->get('deploy_before');
        $after = $request->get('deploy_after');

        if ($split !== null && ($before !== null || $after !== null)) {
            throw Refusal::conflicting(argument: $before !== null ? 'deploy_before' : 'deploy_after', with: 'split_at', accepted: self::BOUNDARIES, example: self::PAIR_EXAMPLE);
        }

        if ($split !== null) {
            return TimeGrammar::parse($split, $now, $timezone) ?? throw Refusal::time(argument: 'split_at', value: $split, tool: $this->name());
        }

        if ($before === null && $after === null) {
            throw Refusal::missing(argument: 'split_at', accepted: self::BOUNDARIES, example: self::EXAMPLE);
        }

        return $this->pair($before, $after);
    }

    /**
     * Read the deploy pair: two different, exact deploy strings.
     */
    protected function pair(mixed $before, mixed $after): DeployPair
    {
        if ($after === null) {
            throw Refusal::missing(argument: 'deploy_after', accepted: 'an exact deploy string, with deploy_before', example: self::PAIR_EXAMPLE);
        }

        if ($before === null) {
            throw Refusal::missing(argument: 'deploy_before', accepted: 'an exact deploy string, with deploy_after', example: self::PAIR_EXAMPLE);
        }

        $pair = new DeployPair($this->deploy('deploy_before', $before), $this->deploy('deploy_after', $after));

        if ($pair->before !== $pair->after) {
            return $pair;
        }

        $shown = json_encode($after, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'deploy_after', expected: 'a deploy other than deploy_before', value: $shown, accepted: 'a deploy other than deploy_before', example: self::PAIR_EXAMPLE);
    }

    /**
     * Read one deploy of the pair: an exact deploy string, which is never empty, since an empty one marks a record with no deploy.
     */
    protected function deploy(string $argument, mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: $argument, expected: 'an exact deploy string', value: $shown, accepted: 'an exact deploy string, which is not empty', example: self::PAIR_EXAMPLE);
    }
}
