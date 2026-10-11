<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\StageView;
use ClaudioDekker\Firewatch\Mcp\TruncationReason;
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
     * What a group id is, as the refusal of a malformed one says it.
     */
    protected const GROUP_ID_DESCRIPTION = 'a 32-character lowercase hex group id';

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
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.grouped_type_argument')),
            'group' => $schema->string()->description(__('firewatch::messages.rank_group_argument')),
            'matching' => $schema->string()->description(__('firewatch::messages.rank_matching_argument')),
            'by' => $schema->string()->description(__('firewatch::messages.rank_by_argument')),
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
        $group = $this->group($request);
        $matching = $this->matching($request);
        $explicit = $this->type($request, $group === null);
        $limit = $this->limit($request, $explicit);
        $deploy = $this->deploy($request, $explicit);

        if ($group !== null) {
            $this->refuseWithGroup($request);
        }

        $by = $explicit === null ? null : $this->measure($request, $explicit, $group);

        $cursorValue = $request->get('cursor');
        $cursor = $cursorValue === null ? null : Cursor::read(value: $cursorValue, tool: $this->name(), arguments: $request->all(), key: $this->key(...));

        $frame = $this->frame($now);
        $window = $this->window($request, $now, $frame->timezone, $cursor);
        $filters = $group === null
            ? ["type: {$explicit?->value}", ...($matching === null ? [] : ["matching: {$matching}"]), ...($deploy === null ? [] : ["deploy: {$deploy}"])]
            : ["group: {$group}", ...($explicit === null ? [] : ["type: {$explicit->value}"])];

        $frame->about($explicit === null ? [] : [$explicit], $window);

        $read = $frame->read(function (SQLite3 $connection) use ($request, $window, $group, $explicit, $matching, $deploy, $limit, $by, $cursor, $filters) {
            $inWindow = $this->inWindow($connection, $window);
            $held = $group === null ? [] : Ranking::holders($connection, $group);

            if ($group !== null && $explicit !== null && $held !== [] && ! in_array($explicit, $held, true)) {
                $holding = implode(', ', array_map(fn (RecordType $type) => $type->value, $held));

                throw Refusal::conflicting(argument: 'type', with: 'group', accepted: "a type that holds the group: {$holding}", example: "rank(group: \"{$group}\")");
            }

            $type = $explicit ?? ($group === null ? null : Ranking::preferred($held));
            $unranked = fn (Frame $frame) => $this->unranked($frame, $filters, $inWindow, $group, $held);

            if ($type === null || ($group !== null && $held === [])) {
                return [$type, $unranked];
            }

            $measure = $by ?? $this->measure($request, $type, $group);
            $ranking = new Ranking($type, $measure, $window, $deploy, $matching, $group, $this->configuration);

            if ($inWindow === 0) {
                return [$type, $unranked];
            }

            if ($group === null) {
                $ranked = $ranking->read($connection);

                return [$type, fn (Frame $frame) => $this->ranking($request, $frame, $window, $filters, $inWindow, $type, $measure, $cursor, $limit, $ranked)];
            }

            $broken = $ranking->breakdown($connection, $limit);

            return [$type, fn (Frame $frame) => $this->breakdown($request, $frame, $filters, $inWindow, $type, $group, $held, $limit, $broken)];
        });

        if ($read === null) {
            return $frame->empty();
        }

        $cursor?->belongsTo($frame->facts()->meta->createdAt, $this->name());

        [$type, $answer] = $read;

        $frame->about($type === null ? [] : [$type], $window);

        if ($frame->isEmpty()) {
            return $frame->empty();
        }

        return $answer($frame);
    }

    /**
     * Say why nothing was ranked.
     *
     * @param  list<string>  $filters
     * @param  list<RecordType>  $held
     */
    protected function unranked(Frame $frame, array $filters, int $inWindow, ?string $group, array $held): Answer
    {
        $empty = $group === null || $held !== []
            ? Emptiness::windowEmpty($frame->facts()->records)
            : Emptiness::noMatch($inWindow, $filters);

        return $frame->empty($empty);
    }

    /**
     * Get the window of the call.
     *
     * @param  Cursor<array<string, mixed>>|null  $cursor
     */
    protected function window(Request $request, CarbonImmutable $now, string $timezone, ?Cursor $cursor): Window
    {
        if ($cursor !== null) {
            return Window::between(since: $cursor->since, until: $cursor->until, timezone: $timezone);
        }

        return Window::read($request, $now, timezone: $timezone, tool: $this->name());
    }

    /**
     * Put the groups, worst first, in the envelope.
     *
     * @param  list<string>  $filters
     * @param  Cursor<array{value: int|float|null, occurrences: int, hash: string}>|null  $cursor
     * @param  array{rows: list<array<string, mixed>>, keys: list<array{value: int|float|null, occurrences: int, hash: string}>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}  $ranked
     */
    protected function ranking(Request $request, Frame $frame, Window $window, array $filters, int $inWindow, RecordType $type, Measure $by, ?Cursor $cursor, int $limit, array $ranked): Answer
    {
        if ($ranked['rows'] === []) {
            return $frame->empty(Emptiness::noMatch($inWindow, $filters));
        }

        $page = array_keys(array_filter($ranked['keys'], fn (array $key) => $cursor === null || Ranking::compare($key, $cursor->last) > 0));
        $rows = Rows::bound(array_map(fn (int $index) => $ranked['rows'][$index], $page), $limit);
        $truncated = [];

        if ($rows->more) {
            $last = $ranked['keys'][$page[count($rows->rows) - 1]];
            $arguments = array_diff_key($request->all(), array_flip(['cursor', 'format']));
            $arguments['cursor'] = Cursor::make(tool: $this->name(), arguments: $request->all(), createdAt: $frame->facts()->meta->createdAt, last: $last, since: $window->since(), until: $window->until() ?? $frame->now);
            $call = $this->call($arguments);
            $how = __('firewatch::messages.rank_cursor_how', ['call' => $call]);
            $entry = $rows->truncation(section: 'groups', how: $how);
            $truncated = $entry === null ? [] : [$entry];
        }

        $groupsRanked = count($ranked['rows']);
        $summary = trans_choice('firewatch::messages.rank_summary', $groupsRanked, [
            'count' => $groupsRanked,
            'type' => $type->value,
            'by' => $by->value,
        ]);
        $notes = $this->notes($by, $ranked);
        $next = $rows->rows === [] ? [] : [$this->breakdownCall($window, $rows->rows[0]['group'], $type)];

        return $frame->answer(
            summary: $summary,
            result: [
                'type' => $type->value,
                'by' => $by->value,
                'failure_definition' => Failure::definition($type),
                'records' => $ranked['records'],
                'groups_ranked' => $groupsRanked,
                'records_without_group' => $ranked['withoutGroup'],
                'groups' => $rows->rows,
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
     * @param  list<RecordType>  $held
     * @param  array{rows: list<array<string, mixed>>, matched: int, records: int, label: string, stages: StageView|null}  $breakdown
     */
    protected function breakdown(Request $request, Frame $frame, array $filters, int $inWindow, RecordType $type, string $group, array $held, int $limit, array $breakdown): Answer
    {
        if ($breakdown['rows'] === []) {
            return $frame->empty(Emptiness::noMatch($inWindow, $filters));
        }

        $shared = $request->get('type') === null && in_array(RecordType::JOB_ATTEMPT, $held, true) && in_array(RecordType::QUEUED_JOB, $held, true);
        $truncated = $breakdown['matched'] > $limit ? [[
            'section' => 'deploys',
            'shown' => count($breakdown['rows']),
            'matched' => $breakdown['matched'],
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.rank_truncated_how'),
        ]] : [];
        $stages = $breakdown['stages'];
        $summary = trans_choice('firewatch::messages.rank_breakdown_summary', $breakdown['matched'], [
            'group' => $group,
            'count' => $breakdown['matched'],
        ]);
        $stageSummary = $stages?->summary() ?? '';
        $jobNotes = $shared ? [__('firewatch::messages.rank_job_group', ['group' => $group])] : [];
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
            notes: [...$jobNotes, ...$stageNotes],
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
     * Read the type to rank.
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
     * Read the measure to rank by.
     */
    protected function measure(Request $request, RecordType $type, ?string $group): Measure
    {
        $value = $request->get('by');
        $default = Measure::default($type);

        if ($value === null) {
            return $default;
        }

        $measure = is_string($value) ? Measure::tryFrom($value) : null;

        $fitting = $group === null ? Measure::for($type) : Measure::forBreakdown($type);

        if ($measure !== null && in_array($measure, $fitting, true)) {
            return $measure;
        }

        $expected = "a measure of {$type->value}".($group === null ? '' : ' in a breakdown of one group');
        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', array_map(fn (Measure $measure) => $measure->value, $fitting));

        throw Refusal::invalid(argument: 'by', expected: $expected, value: $shown, accepted: $accepted, example: "rank(type: \"{$type->value}\", by: \"{$default->value}\")");
    }

    /**
     * Read the most rows to list.
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
     * Read the group to break down.
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

        throw Refusal::invalid(argument: 'group', expected: self::GROUP_ID_DESCRIPTION, value: $shown, accepted: self::GROUP_ID_DESCRIPTION, example: 'rank(group: "<group id>")');
    }

    /**
     * Read the text a group's label must contain.
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
