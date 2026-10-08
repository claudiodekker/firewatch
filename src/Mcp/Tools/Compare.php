<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Comparison;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
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
     * What a group id is, as the refusal of a malformed one says it.
     */
    protected const GROUP_ID_DESCRIPTION = 'a 32-character lowercase hex group id';

    /**
     * A valid call, as a refusal shows it.
     */
    protected const EXAMPLE = 'compare(type: "request", split_at: "<now of an earlier answer>")';

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
            'split_at' => $schema->string()->description(__('firewatch::messages.compare_split_at_argument'))->required(),
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
        $split = $this->split($request, $now, $timezone);

        $epoch = Instant::of($now);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $window, $comparison] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $request, $given, $epoch, $split, $explicit, $group, $limit));
        } catch (StoreUnusable $unusable) {
            $types = $explicit === null ? [] : [$explicit];
            $window = Window::between($given->since(), $given->until() ?? $epoch, $timezone);
            $blindSpots = [...BlindSpots::for($types, anchored: true), ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $types = $type === null ? [] : [$type];
        $blindSpots = [...BlindSpots::for($types, anchored: true), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total, straddling: $comparison?->straddling);
        $filters = $group === null ? ["type: {$type?->value}"] : ["group: {$group}", ...($explicit === null ? [] : ["type: {$explicit->value}"])];

        if ($group !== null && $held === []) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        if ($inWindow === 0) {
            $empty = Emptiness::windowEmpty($total);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        if ($comparison === null || $comparison->isEmpty()) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $shared = $explicit === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($comparison),
            empty: null,
            result: $comparison->result(),
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($request, $comparison, $oldest, $split, $shared ? $group : null),
            truncated: $this->truncated($comparison),
            next: $this->next($comparison, $window),
            cuttable: ['groups'],
            recount: $comparison->recounted(...),
        );
    }

    /**
     * Resolve the type and the window against the store, refuse a split outside the window, and compare, in one snapshot.
     *
     * @return array{int, int, float|null, float|null, StoreFacts, RecordType|null, list<RecordType>, Window, Comparison|null}
     */
    protected function load(SQLite3 $connection, Request $request, Window $given, float $epoch, float $split, ?RecordType $explicit, ?string $group, int $limit): array
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

        if ($split <= ($window->since() ?? -INF) || $split >= ($window->until() ?? $epoch)) {
            throw Refusal::splitOutsideWindow(self::EXAMPLE);
        }

        [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);

        $comparison = $type === null || $by === null || $inWindow === 0
            ? null
            : Comparison::of($connection, $type, $by, $window, $split, $coverageStart, $group, $limit);

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
    protected function summary(Comparison $comparison): string
    {
        $unevaluated = $comparison->unevaluated();

        if ($unevaluated !== null) {
            return __("firewatch::messages.compare_{$unevaluated[0]->value}_summary", [
                'side' => $unevaluated[1],
                'type' => $comparison->type->value,
            ]);
        }

        return trans_choice('firewatch::messages.compare_summary', $comparison->matched(), [
            'groups' => $comparison->matched(),
            'type' => $comparison->type->value,
            'by' => $comparison->by->value,
            'changes' => $comparison->counts(),
        ]);
    }

    /**
     * Get the notes of a comparison, in the fixed order.
     *
     * @return list<string>
     */
    protected function notes(Request $request, Comparison $comparison, ?float $oldest, float $split, ?string $shared): array
    {
        $notes = [];

        if ($shared !== null) {
            $notes[] = __('firewatch::messages.rank_job_group', ['group' => $shared]);
        }

        if ($comparison->unevaluated() !== null) {
            $notes[] = __('firewatch::messages.compare_not_evaluated_note');
        }

        if ($comparison->earlier['records'] > 0) {
            $notes[] = trans_choice('firewatch::messages.'.($comparison->earlier['more'] ? 'compare_earlier_more_note' : 'compare_earlier_note'), $comparison->earlier['records'], [
                'count' => $comparison->earlier['records'],
                'type' => $comparison->type->value,
            ]);
        }

        if ($request->get('since') === null && $oldest !== null && $oldest < $split - Comparison::STRADDLING_SECONDS) {
            $notes[] = __('firewatch::messages.compare_move_since_note');
        }

        return $notes;
    }

    /**
     * Get the entry of the groups the limit cut, if it cut any.
     *
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    protected function truncated(Comparison $comparison): array
    {
        $entry = $comparison->groups->truncation(section: 'groups', how: __('firewatch::messages.compare_truncated_how'), matched: $comparison->matched());

        return $entry === null ? [] : [$entry];
    }

    /**
     * Get the calls that list the records of the group that changed most and break it down by deploy, over the same window.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Comparison $comparison, Window $window): array
    {
        $group = $comparison->first();

        if ($group === null) {
            return [];
        }

        $type = $comparison->type === RecordType::QUEUED_JOB ? ['type' => $comparison->type->value] : [];

        return [
            [
                'tool' => 'occurrences',
                'arguments' => [
                    'group' => $group,
                    ...$type,
                    ...$window->arguments(),
                ],
                'why' => __('firewatch::messages.compare_next_occurrences'),
            ],
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
     * Read the instant the window is split at, which is required.
     */
    protected function split(Request $request, CarbonImmutable $now, string $timezone): float
    {
        $value = $request->get('split_at');

        if ($value === null) {
            throw Refusal::missing(argument: 'split_at', accepted: 'a time in the forms of since: the now of an earlier answer', example: self::EXAMPLE);
        }

        return TimeGrammar::parse($value, $now, $timezone) ?? throw Refusal::time(argument: 'split_at', value: $value, tool: $this->name());
    }
}
