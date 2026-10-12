<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Boundary;
use ClaudioDekker\Firewatch\Mcp\CallFact;
use ClaudioDekker\Firewatch\Mcp\Comparison;
use ClaudioDekker\Firewatch\Mcp\ComparisonReason;
use ClaudioDekker\Firewatch\Mcp\Concerns\BuildsAnswers;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\DeployPair;
use ClaudioDekker\Firewatch\Mcp\GroupSelection;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\SplitPoint;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\TimeGrammar;
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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('compare')]
#[Title('Compare')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class Compare extends Tool
{
    use BuildsAnswers {
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
            'type' => $this->groupedTypeArgument($schema),
            'group' => $schema->string()->description(__('firewatch::messages.compare_group_argument')),
            'split_at' => $schema->string()->description(__('firewatch::messages.compare_split_at_argument')),
            'deploy_before' => $schema->string()->description(__('firewatch::messages.compare_deploy_before_argument')),
            'deploy_after' => $schema->string()->description(__('firewatch::messages.compare_deploy_after_argument')),
            'by' => $this->measureArgument($schema, Measure::compared(...))->description(__('firewatch::messages.compare_by_argument')),
            ...$this->windowSchema($schema, 'compare'),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
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
     * Read the arguments, then the store, and put the comparison, or why there is none, in the answer.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this);
        $group = $arguments->group('compare(group: "<group id>", split_at: "<time>")');
        $explicit = $arguments->typeWithGroups($group, SplitPoint::EXAMPLE);
        $limit = $this->limit($arguments, $group);

        if ($explicit !== null) {
            $this->measure($arguments, $explicit);
        }

        $frame = $this->frame($now);
        $given = Window::read($request, $now, timezone: $frame->timezone, tool: $this->name());
        $boundary = $this->boundary($request, $now, $frame->timezone);
        $notes = $boundary->fixedNotes();

        $frame->about($explicit === null ? [] : [$explicit], Window::between($given->since(), $given->until() ?? $frame->now, $frame->timezone), facts: $boundary->isInstant() ? [CallFact::ANCHORED] : []);

        $read = $frame->read(fn (SQLite3 $connection, StoreFacts $facts) => $this->load($connection, $facts, $arguments, $given, $frame->now, $boundary, $explicit, $group, $limit));

        if ($read === null) {
            return $frame->empty(notes: $notes);
        }

        [$selection, $type, $window, $inWindow, $comparison] = $read;

        $frame->about($type === null ? [] : [$type], $window, facts: $boundary->isInstant() ? [CallFact::ANCHORED] : [], straddling: $comparison?->straddling);

        if ($frame->isEmpty()) {
            return $frame->empty(notes: $notes);
        }

        $hasRows = $comparison !== null && ! $comparison->isEmpty();
        $empty = $selection->vacancy($frame, $inWindow, $hasRows, $boundary->filters());

        if ($empty !== null || $comparison === null) {
            return $frame->empty($empty, $notes);
        }

        return $frame->answer(
            summary: $this->summary($comparison),
            result: $comparison->result(),
            notes: [...$selection->notes(), ...$this->notes($request, $comparison, $frame->facts()->oldest)],
            truncated: $this->truncated($comparison),
            next: $this->next($comparison, $window),
            cuttable: ['groups'],
            recount: $comparison->recounted(...),
        );
    }

    /**
     * Resolve the type and the window against the store, refuse a split outside the window, and compare, in one snapshot.
     *
     * @return array{GroupSelection, RecordType|null, Window, int, Comparison|null}
     */
    protected function load(SQLite3 $connection, StoreFacts $facts, Arguments $arguments, Window $given, float $epoch, Boundary $boundary, ?RecordType $explicit, ?string $group, int $limit): array
    {
        $selection = GroupSelection::resolve($connection, $group, $explicit, null, "compare(group: \"{$group}\", split_at: \"<time>\")");
        $type = $selection->measured();
        $by = $type === null ? null : $this->measure($arguments, $type);
        $coverageStart = $type === null ? null : $facts->historyFrom([$type]);

        $window = $this->window($given, $epoch, $coverageStart);

        $boundary->refuseOutside($window);

        $inWindow = $window->count($connection);

        $comparison = $type === null || $by === null || $inWindow === 0
            ? null
            : Comparison::of($connection, $type, $by, $window, $boundary, $coverageStart, $group, $limit);

        return [$selection, $type, $window, $inWindow, $comparison];
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

        if ($unevaluated === null) {
            return $comparison->boundary->summary($comparison);
        }

        [$reason, $side] = $unevaluated;

        if ($reason === ComparisonReason::EMPTY_SIDE) {
            return $comparison->boundary->emptySideSummary($comparison, $side);
        }

        return __("firewatch::messages.compare_{$reason->value}_summary", [
            'side' => $side,
            'type' => $comparison->type->value,
        ]);
    }

    /**
     * Get the notes of a comparison, in the fixed order: what a comparison that ran on nothing says, then the boundary's.
     *
     * @return list<string>
     */
    protected function notes(Request $request, Comparison $comparison, ?float $oldest): array
    {
        $notes = [];

        if ($comparison->unevaluated() !== null) {
            $notes[] = $comparison->boundary->notEvaluatedNote();
        }

        return [...$notes, ...$comparison->boundary->notes($comparison, $request->get('since') === null, $oldest)];
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
     * Get the calls that list the records of the first group listed, as the boundary divides them, and break it down by deploy, over the same window.
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

        $occurrences = array_map(fn (array $call) => [
            'tool' => 'occurrences',
            'arguments' => [
                'group' => $group,
                ...$type,
                ...$call['arguments'],
                ...$window->arguments(),
            ],
            'why' => $call['why'],
        ], $comparison->boundary->occurrences($comparison));

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
     * Read the measure to compare by.
     */
    protected function measure(Arguments $arguments, RecordType $type): Measure
    {
        $default = Measure::default($type);

        return $arguments->among('by', Measure::compared($type), "a measure of {$type->value}", "compare(type: \"{$type->value}\", split_at: \"<time>\", by: \"{$default->value}\")") ?? $default;
    }

    /**
     * Read the most groups to list, which a comparison of one group has no use for.
     */
    protected function limit(Arguments $arguments, ?string $group): int
    {
        if ($group !== null && $arguments->get('limit') !== null) {
            throw Refusal::conflicting(argument: 'limit', with: 'group', accepted: 'a call without `limit`: one group is one row', example: "compare(group: \"{$group}\", split_at: \"<time>\")");
        }

        $alongside = [
            'type' => 'request',
            'split_at' => '<time>',
        ];

        return $arguments->limit($alongside);
    }

    /**
     * Read the one boundary of the call: the split point of the window, or the deploy pair.
     */
    protected function boundary(Request $request, CarbonImmutable $now, string $timezone): Boundary
    {
        $split = $request->get('split_at');
        $before = $request->get('deploy_before');
        $after = $request->get('deploy_after');

        if ($split !== null && ($before !== null || $after !== null)) {
            throw Refusal::conflicting(argument: $before !== null ? 'deploy_before' : 'deploy_after', with: 'split_at', accepted: self::BOUNDARIES, example: DeployPair::EXAMPLE);
        }

        if ($split !== null) {
            $instant = TimeGrammar::parse($split, $now, $timezone) ?? throw Refusal::time(argument: 'split_at', value: $split, tool: $this->name());

            return new SplitPoint($instant);
        }

        if ($before === null && $after === null) {
            throw Refusal::missing(argument: 'split_at', accepted: self::BOUNDARIES, example: SplitPoint::EXAMPLE);
        }

        return DeployPair::read($before, $after);
    }
}
