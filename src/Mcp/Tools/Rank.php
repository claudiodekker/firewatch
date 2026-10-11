<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Continuation;
use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\GroupSelection;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Population;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\StageView;
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
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->groupedTypeArgument($schema),
            'group' => $schema->string()->description(__('firewatch::messages.rank_group_argument')),
            'matching' => $this->matchingArgument($schema)->description(__('firewatch::messages.rank_matching_argument')),
            'by' => $this->measureArgument($schema, Measure::for(...))->description(__('firewatch::messages.rank_by_argument')),
            ...$this->windowSchema($schema),
            'deploy' => $schema->string()->description(__('firewatch::messages.deploy_argument')),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
            'cursor' => $schema->string()->description(__('firewatch::messages.cursor_argument')),
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
        $arguments = new Arguments($request, $this);
        $group = $arguments->group('rank(group: "<group id>")');
        $matching = $arguments->matching('rank(type: "request", matching: "orders")');
        $explicit = $arguments->typeWithGroups($group, 'rank(type: "request", by: "p95_duration")');
        $limit = $arguments->limit($explicit === null ? ['group' => '<group id>'] : ['type' => $explicit->value]);
        $deploy = $arguments->deploy($this->call([
            'type' => ($explicit ?? RecordType::REQUEST)->value,
            'deploy' => 'v1',
        ]));

        if ($group !== null) {
            $this->refuseWithGroup($request);
        }

        $by = $explicit === null ? null : $this->measure($arguments, $explicit, $group);

        $continuation = Continuation::read($request, $this->name(), $this->key(...));

        $frame = $this->frame($now);
        $window = $continuation->window($now, $frame->timezone);

        $frame->about($explicit === null ? [] : [$explicit], $window);

        $read = $frame->read(function (SQLite3 $connection) use ($arguments, $window, $group, $explicit, $matching, $deploy, $limit, $by, $continuation) {
            $inWindow = $window->count($connection);
            $selection = GroupSelection::resolve($connection, $group, $explicit, $deploy, "rank(group: \"{$group}\")");
            $type = $selection->measured();
            $filters = $selection->filters($matching === null ? [] : ["matching: {$matching}"]);
            $unranked = fn (Frame $frame) => $frame->empty($frame->vacancy(inWindow: $inWindow, groupHeld: $selection->isHeld(), hasRows: false, filters: $filters));

            if ($type === null || ! $selection->isHeld()) {
                return [$type, $unranked];
            }

            $measure = $by ?? $this->measure($arguments, $type, $group);
            $ranking = new Ranking(new Population($type, $window, $deploy, $group), $measure, $matching, $this->configuration);

            if ($inWindow === 0) {
                return [$type, $unranked];
            }

            if ($group === null) {
                $ranked = $ranking->read($connection, $continuation->after(), $limit);

                return [$type, fn (Frame $frame) => $this->ranking($continuation, $frame, $window, $filters, $inWindow, $type, $measure, $ranked)];
            }

            $broken = $ranking->breakdown($connection, $limit);

            return [$type, fn (Frame $frame) => $this->breakdown($frame, $filters, $inWindow, $type, $group, $selection, $broken)];
        });

        if ($read === null) {
            return $frame->empty();
        }

        $continuation->belongsTo($frame->facts()->meta->createdAt);

        [$type, $answer] = $read;

        $frame->about($type === null ? [] : [$type], $window);

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        return $answer($frame);
    }

    /**
     * Put the groups, worst first, in the envelope.
     *
     * @param  list<string>  $filters
     * @param  Continuation<array{value: int|float|null, occurrences: int, hash: string}>  $continuation
     * @param  array{page: Rows<array{value: int|float|null, occurrences: int, hash: string}>, rows: list<array<string, mixed>>, ranked: int, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}  $ranked
     */
    protected function ranking(Continuation $continuation, Frame $frame, Window $window, array $filters, int $inWindow, RecordType $type, Measure $by, array $ranked): Answer
    {
        if ($ranked['ranked'] === 0) {
            return $frame->empty($frame->vacancy(inWindow: $inWindow, groupHeld: true, hasRows: false, filters: $filters));
        }

        $page = $ranked['page'];
        $truncated = $continuation->truncated($page, $page->rows, 'groups', 'firewatch::messages.rank_cursor_how', $frame->facts()->meta->createdAt, $window, $frame->now);

        $rows = $ranked['rows'];
        $groupsRanked = $ranked['ranked'];
        $summary = trans_choice('firewatch::messages.rank_summary', $groupsRanked, [
            'count' => $groupsRanked,
            'type' => $type->value,
            'by' => $by->value,
        ]);
        $notes = $this->notes($by, $ranked);
        $next = $rows === [] ? [] : [$this->breakdownCall($window, $rows[0]['group'], $type)];

        return $frame->answer(
            summary: $summary,
            result: [
                'type' => $type->value,
                'by' => $by->value,
                'failure_definition' => Failure::definition($type),
                'records' => $ranked['records'],
                'groups_ranked' => $groupsRanked,
                'records_without_group' => $ranked['withoutGroup'],
                'groups' => $rows,
            ],
            notes: $notes,
            truncated: $truncated,
            next: $next,
        );
    }

    /**
     * Put the deploys one group was recorded under in the envelope.
     *
     * @param  list<string>  $filters
     * @param  array{page: Rows<array<string, mixed>>, rows: list<array<string, mixed>>, matched: int, records: int, label: string, stages: StageView|null}  $breakdown
     */
    protected function breakdown(Frame $frame, array $filters, int $inWindow, RecordType $type, string $group, GroupSelection $selection, array $breakdown): Answer
    {
        if ($breakdown['rows'] === []) {
            return $frame->empty($frame->vacancy(inWindow: $inWindow, groupHeld: true, hasRows: false, filters: $filters));
        }

        $entry = $breakdown['page']->truncation(section: 'deploys', how: __('firewatch::messages.rank_truncated_how'), matched: $breakdown['matched']);
        $truncated = $entry === null ? [] : [$entry];
        $stages = $breakdown['stages'];
        $summary = trans_choice('firewatch::messages.rank_breakdown_summary', $breakdown['matched'], [
            'group' => $group,
            'count' => $breakdown['matched'],
        ]);
        $stageSummary = $stages?->summary() ?? '';
        $stageNotes = $stages?->notes() ?? [];
        $stageFields = $stages?->fields() ?? StageView::none();

        return $frame->answer(
            summary: $summary.$stageSummary,
            result: [
                'type' => $type->value,
                'group' => $group,
                'label' => $breakdown['label'],
                'records' => $breakdown['records'],
                ...$stageFields,
                'deploys' => $breakdown['rows'],
            ],
            notes: [...$selection->notes(), ...$stageNotes],
            truncated: $truncated,
        );
    }

    /**
     * Get the call that breaks a group down by deploy.
     *
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    protected function breakdownCall(Window $window, string $group, RecordType $type): array
    {
        $arguments = ['group' => $group];

        if ($type === RecordType::QUEUED_JOB) {
            $arguments['type'] = $type->value;
        }

        return [
            'tool' => $this->name(),
            'arguments' => [...$arguments, ...$window->arguments()],
            'why' => __('firewatch::messages.rank_next_group'),
        ];
    }

    /**
     * Read the key of the last row a cursor continues after, or null when it is none.
     *
     * @param  array<string, mixed>  $last
     * @return array{value: int|float|null, occurrences: int, hash: string}|null
     */
    protected function key(array $last): ?array
    {
        $value = $last['value'] ?? null;

        if (! array_key_exists('value', $last) || ! (is_int($value) || is_float($value) || $value === null)) {
            return null;
        }

        if (! is_int($last['occurrences'] ?? null) || ! is_string($last['hash'] ?? null)) {
            return null;
        }

        return [
            'value' => $value,
            'occurrences' => $last['occurrences'],
            'hash' => $last['hash'],
        ];
    }

    /**
     * Get the notes of the answer.
     *
     * @param  array{untimed: int, orderedBy: Measure}  $ranked
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
     * Read the measure to rank by.
     */
    protected function measure(Arguments $arguments, RecordType $type, ?string $group): Measure
    {
        $default = Measure::default($type);
        $fitting = $group === null ? Measure::for($type) : Measure::forBreakdown($type);
        $expected = "a measure of {$type->value}".($group === null ? '' : ' in a breakdown of one group');

        return $arguments->among('by', $fitting, $expected, "rank(type: \"{$type->value}\", by: \"{$default->value}\")") ?? $default;
    }

    /**
     * Refuse what a breakdown of one group has no use for.
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
}
