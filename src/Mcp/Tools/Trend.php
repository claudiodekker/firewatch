<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Buckets;
use ClaudioDekker\Firewatch\Mcp\CallFact;
use ClaudioDekker\Firewatch\Mcp\ComparisonReason;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Markdown;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Window;
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
            'type' => $this->groupedTypeArgument($schema),
            'group' => $schema->string()->description(__('firewatch::messages.trend_group_argument')),
            'by' => $this->measureArgument($schema, Measure::trended(...))
                ->default(Measure::OCCURRENCES->value)
                ->description(__('firewatch::messages.trend_by_argument')),
            'buckets' => $schema->integer()
                ->min(self::FEWEST_BUCKETS)
                ->max(self::MOST_BUCKETS)
                ->default(self::DEFAULT_BUCKETS)
                ->description(__('firewatch::messages.trend_buckets_argument')),
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
        $arguments = new Arguments($request, $this);
        $group = $arguments->group('trend(group: "<group id>")');
        $explicit = $arguments->typeWithGroups($group, self::EXAMPLE);
        $count = $arguments->between('buckets', ['type' => 'request']);
        $deploy = $arguments->deploy('trend(type: "request", deploy: "v1")');

        if ($explicit !== null) {
            $this->measure($arguments, $explicit);
        }

        $frame = $this->frame($now);
        $given = Window::read($request, $now, timezone: $frame->timezone, tool: $this->name());

        $frame->about($explicit === null ? [] : [$explicit], $given->derive(null, null), facts: [CallFact::ANCHORED]);

        $read = $frame->read(fn (SQLite3 $connection, StoreFacts $facts) => $this->load($connection, $facts, $arguments, $given, $explicit, $group, $deploy, $count));

        if ($read === null) {
            return $frame->empty();
        }

        [$type, $held, $window, $inWindow, $buckets] = $read;

        $frame->about($type === null ? [] : [$type], $window, facts: [CallFact::ANCHORED]);

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        $filters = $this->filters($group, $explicit, $deploy);

        if ($group !== null && $held === []) {
            return $frame->empty(Emptiness::noMatch($inWindow, $filters));
        }

        if ($inWindow === 0) {
            return $frame->empty(Emptiness::windowEmpty($frame->facts()->records));
        }

        if ($buckets === null) {
            return $frame->empty(Emptiness::noMatch($inWindow, $filters));
        }

        $shared = $explicit === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);

        return $frame->answer(
            summary: $this->summary($buckets),
            result: $buckets->result(),
            notes: $this->notes($buckets, $frame->now, $shared ? $group : null),
            next: $this->next($buckets, $group, $deploy, $frame->now),
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
     * @return array{RecordType|null, list<RecordType>, Window, int, Buckets|null}
     */
    protected function load(SQLite3 $connection, StoreFacts $facts, Arguments $arguments, Window $given, ?RecordType $explicit, ?string $group, ?string $deploy, int $count): array
    {
        [$type, $held] = $arguments->resolveGroupType($connection, $group, $explicit, "trend(group: \"{$group}\")");
        $buckets = null;

        if ($type !== null) {
            $by = $this->measure($arguments, $type);
            $coverageStart = $facts->historyFrom([$type]);
            $buckets = Buckets::read($connection, $type, $by, $given, $coverageStart, $group, $deploy, $count);
        }

        $window = $buckets === null ? $given->derive(null, null) : $buckets->window;

        return [$type, $held, $window, $this->inWindow($connection, $window), $buckets];
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
     * Read the measure to trend by; a percentile per bucket is refused with a pointer to rank.
     */
    protected function measure(Arguments $arguments, RecordType $type): Measure
    {
        $value = $arguments->get('by');
        $measure = is_string($value) ? Measure::tryFrom($value) : null;
        $fitting = Measure::trended($type);

        if ($measure?->percentile() !== null && ! in_array($measure, $fitting, true)) {
            throw $arguments->invalid(argument: 'by', expected: 'a measure per bucket; a percentile per bucket is never shown', accepted: implode(', ', array_map(fn (Measure $measure) => $measure->value, $fitting)), example: "rank(type: \"{$type->value}\", by: \"{$measure->value}\")");
        }

        return $arguments->amongOrDefault('by', $fitting, "a measure of {$type->value} per bucket", "trend(type: \"{$type->value}\", by: \"occurrences\")");
    }
}
