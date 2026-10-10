<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Buckets;
use ClaudioDekker\Firewatch\Mcp\ComparisonReason;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Markdown;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Refusal;
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

/**
 * @api
 */
#[Name('trend')]
#[Title('Trend')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Trend extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The buckets a trend has when no count is asked for.
     */
    protected const DEFAULT_BUCKETS = 12;

    /**
     * The fewest buckets a trend has.
     */
    protected const FEWEST_BUCKETS = 2;

    /**
     * The most buckets a trend has.
     */
    protected const MOST_BUCKETS = 60;

    /**
     * What a group id is, as the refusal of a malformed one says it.
     */
    protected const GROUP_ID_DESCRIPTION = 'a 32-character lowercase hex group id';

    /**
     * The call a refusal shows when it has no better one.
     */
    protected const EXAMPLE = 'trend(type: "request")';

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
        return __('firewatch::messages.tools.trend');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.grouped_type_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.trend_group_argument')),
            'by' => $schema->string()->description(__('firewatch::messages.trend_by_argument')),
            'buckets' => $schema->integer()->description(__('firewatch::messages.trend_buckets_argument')),
            ...$this->windowSchema($schema, 'trend'),
            'deploy' => $schema->string()->description(__('firewatch::messages.deploy_argument')),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with a measure of one type, or one group, over equal buckets of the window.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the trend, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $group = $this->group($request);
        $explicit = $this->type($request, $group === null);
        $count = $this->buckets($request);
        $deploy = $this->deploy($request);

        if ($explicit !== null) {
            $this->measure($request, $explicit);
        }

        $timezone = config()->string('app.timezone');
        $given = Window::read($request, $now, timezone: $timezone, tool: $this->name());

        $epoch = Instant::of($now);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        try {
            [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $window, $buckets] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $request, $given, $explicit, $group, $deploy, $count));
        } catch (StoreUnusable $unusable) {
            $types = $explicit === null ? [] : [$explicit];
            $window = $given->derive(null, null);
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

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);
        $filters = $this->filters($group, $explicit, $deploy);

        if ($group !== null && $held === []) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        if ($inWindow === 0) {
            $empty = Emptiness::windowEmpty($total);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        if ($buckets === null) {
            $empty = Emptiness::noMatch($inWindow, $filters);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $shared = $explicit === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);

        return new Answer(
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            summary: $this->summary($buckets),
            empty: null,
            result: $buckets->result(),
            coverage: $coverage,
            blindSpots: $blindSpots,
            notes: $this->notes($buckets, $epoch, $shared ? $group : null),
            next: $this->next($buckets, $group, $deploy, $epoch),
            cuttable: [],
        );
    }

    /**
     * Get the filters of the call as an empty answer names them.
     *
     * @return list<string>
     */
    protected function filters(?string $group, ?RecordType $type, ?string $deploy): array
    {
        $filters = [];

        if ($group !== null) {
            $filters[] = "group: {$group}";
        }

        if ($type !== null) {
            $filters[] = "type: {$type->value}";
        }

        if ($deploy !== null) {
            $filters[] = "deploy: {$deploy}";
        }

        return $filters;
    }

    /**
     * Resolve the type, the measure and the coverage start, and read the buckets, in one snapshot.
     *
     * @return array{int, int, float|null, float|null, StoreFacts, RecordType|null, list<RecordType>, Window, Buckets|null}
     */
    protected function load(SQLite3 $connection, Request $request, Window $given, ?RecordType $explicit, ?string $group, ?string $deploy, int $count): array
    {
        $facts = StoreFacts::read($connection);
        $held = $group === null ? [] : Ranking::holders($connection, $group);

        if ($group !== null && $explicit !== null && $held !== [] && ! in_array($explicit, $held, true)) {
            $holding = implode(', ', array_map(fn (RecordType $type) => $type->value, $held));

            throw Refusal::conflicting(argument: 'type', with: 'group', accepted: "a type that holds the group: {$holding}", example: "trend(group: \"{$group}\")");
        }

        $type = $explicit ?? Ranking::preferred($held);
        $buckets = null;

        if ($type !== null) {
            $by = $this->measure($request, $type);
            $coverageStart = History::of($facts->meta, [$type])->from;
            $buckets = Buckets::read($connection, $type, $by, $given, $coverageStart, $group, $deploy, $count);
        }

        $window = $buckets === null ? $given->derive(null, null) : $buckets->window;

        [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);

        return [$total, $inWindow, $oldest, $newest, $facts, $type, $held, $window, $buckets];
    }

    /**
     * Get the summary: the direction and the peak, or why there is no direction.
     */
    protected function summary(Buckets $buckets): string
    {
        if ($buckets->reason === ComparisonReason::OUTSIDE_COVERAGE) {
            return __('firewatch::messages.trend_outside_coverage_summary', ['type' => $buckets->type->value]);
        }

        if ($buckets->direction === null) {
            return __('firewatch::messages.trend_sample_too_small_summary', [
                'have' => $buckets->valued,
                'buckets' => count($buckets->buckets),
                'by' => $buckets->by->value,
                'needed' => Buckets::DIRECTION_NEEDS,
            ]);
        }

        $replace = [
            'type' => $buckets->type->value,
            'by' => $buckets->by->value,
            'buckets' => count($buckets->buckets),
            'direction' => $buckets->direction->value,
        ];

        if ($buckets->peak === null) {
            return __('firewatch::messages.trend_summary_no_peak', $replace);
        }

        return __('firewatch::messages.trend_summary', [
            ...$replace,
            'peak' => $buckets->peak['index'],
        ]);
    }

    /**
     * Get the notes in the fixed order: the shared job group, the partial buckets, the window of no width, the idle time.
     *
     * @return list<string>
     */
    protected function notes(Buckets $buckets, float $now, ?string $shared): array
    {
        $notes = [];

        if ($shared !== null) {
            $notes[] = __('firewatch::messages.rank_job_group', ['group' => $shared]);
        }

        $partial = $buckets->partialCount();

        if ($partial > 0) {
            $notes[] = trans_choice('firewatch::messages.trend_partial_note', $partial, [
                'count' => $partial,
                'type' => $buckets->type->value,
            ]);
        }

        if ($buckets->isInstant()) {
            $notes[] = __('firewatch::messages.trend_width_zero_note');
        }

        $idle = $buckets->idle($now);

        if ($idle !== null) {
            $notes[] = __('firewatch::messages.trend_idle_note', ['duration' => Markdown::duration($idle)]);
        }

        return $notes;
    }

    /**
     * Get the call that lists the records of the peak bucket; one that ends at a derived until ends at the store clock instead, so that the record at it is in.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(Buckets $buckets, ?string $group, ?string $deploy, float $now): array
    {
        $peak = $buckets->peakBucket();

        if ($peak === null) {
            return [];
        }

        $selector = $group === null ? [] : ['group' => $group];

        if ($group === null || $buckets->type === RecordType::QUEUED_JOB) {
            $selector['type'] = $buckets->type->value;
        }

        $last = $peak['index'] === count($buckets->buckets) - 1 && $buckets->window->derives('until');

        return [
            [
                'tool' => 'occurrences',
                'arguments' => [
                    ...$selector,
                    ...($deploy === null ? [] : ['deploy' => $deploy]),
                    'since' => $peak['since'],
                    'until' => $last ? $now : $peak['until'],
                ],
                'why' => __('firewatch::messages.trend_next_occurrences'),
            ],
        ];
    }

    /**
     * Read the type to trend.
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
     * Read the measure to trend by; a percentile per bucket is refused with a pointer to rank.
     */
    protected function measure(Request $request, RecordType $type): Measure
    {
        $value = $request->get('by');

        if ($value === null) {
            return Measure::OCCURRENCES;
        }

        $measure = is_string($value) ? Measure::tryFrom($value) : null;
        $fitting = Measure::trended($type);

        if ($measure !== null && in_array($measure, $fitting, true)) {
            return $measure;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', array_map(fn (Measure $measure) => $measure->value, $fitting));

        if ($measure?->percentile() !== null) {
            throw Refusal::invalid(argument: 'by', expected: 'a measure per bucket; a percentile per bucket is never shown', value: $shown, accepted: $accepted, example: "rank(type: \"{$type->value}\", by: \"{$measure->value}\")");
        }

        throw Refusal::invalid(argument: 'by', expected: "a measure of {$type->value} per bucket", value: $shown, accepted: $accepted, example: "trend(type: \"{$type->value}\", by: \"occurrences\")");
    }

    /**
     * Read how many buckets to cut the window into.
     */
    protected function buckets(Request $request): int
    {
        $value = $request->get('buckets');

        if ($value === null) {
            return self::DEFAULT_BUCKETS;
        }

        if (is_int($value) && $value >= self::FEWEST_BUCKETS && $value <= self::MOST_BUCKETS) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $range = self::FEWEST_BUCKETS.' to '.self::MOST_BUCKETS;

        throw Refusal::invalid(argument: 'buckets', expected: $range, value: $shown, accepted: "a whole number from {$range}", example: 'trend(type: "request", buckets: '.self::DEFAULT_BUCKETS.')');
    }

    /**
     * Read the group to trend.
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

        throw Refusal::invalid(argument: 'group', expected: self::GROUP_ID_DESCRIPTION, value: $shown, accepted: self::GROUP_ID_DESCRIPTION, example: 'trend(group: "<group id>")');
    }

    /**
     * Read the exact deploy whose records alone count.
     */
    protected function deploy(Request $request): ?string
    {
        $value = $request->get('deploy');

        if ($value === null || is_string($value)) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'deploy', expected: 'an exact deploy string', value: $shown, accepted: 'an exact deploy string', example: 'trend(type: "request", deploy: "v1")');
    }
}
