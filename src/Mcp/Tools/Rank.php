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
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
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
     * Get the arguments of the tool: what to rank, by what, the window, the deploy, the limit and the format.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description(__('firewatch::messages.rank_type_argument')),
            'by' => $schema->string()->description(__('firewatch::messages.rank_by_argument')),
            'since' => $schema->string()->description(__('firewatch::messages.since_argument')),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument')),
            'deploy' => $schema->string()->description(__('firewatch::messages.rank_deploy_argument')),
            'limit' => $schema->integer()->description(__('firewatch::messages.rank_limit_argument')),
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
     * Read the arguments, then the store, and put the ranking in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $type = $this->type($request);
        $by = $this->measure($request, $type);
        $limit = $this->limit($request, $type);
        $deploy = $this->deploy($request, $type);

        $epoch = (float) $now->format('U.u');
        $timezone = config()->string('app.timezone');
        $window = Window::read($request, $now, $timezone, $this->name());
        $types = [$type];
        $structural = BlindSpots::for($types);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $ranking = new Ranking($type, $by, $window, $deploy);

        try {
            [$total, $inWindow, $oldest, $newest, $ranked, $facts] = $this->reader->snapshot(function (SQLite3 $connection) use ($window, $ranking) {
                [$total, $inWindow, $oldest, $newest] = $this->count($connection, $window);

                return [$total, $inWindow, $oldest, $newest, $inWindow === 0 ? null : $ranking->read($connection), StoreFacts::read($connection)];
            });
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...$structural, ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);

            $unknownHistory = History::unknown(...$retention);
            $coverage = Coverage::of($unusable, $types, $unknownHistory);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $blindSpots = [...$structural, ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        if ($total === 0) {
            $empty = Emptiness::storeEmpty($this->configuration->database);
            $coverage = new Coverage(CoverageState::EMPTY, $types, $history, records: 0);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $coverage = new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        if ($ranked === null) {
            $empty = Emptiness::windowEmpty($total);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        if ($ranked['rows'] === []) {
            $filters = ["type: {$type->value}", ...($deploy === null ? [] : ["deploy: {$deploy}"])];
            $empty = Emptiness::noMatch($inWindow, $filters);

            return new Answer(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots);
        }

        $rows = Rows::bound($ranked['rows'], $limit);
        $groupsRanked = count($ranked['rows']);
        $summary = trans_choice('firewatch::messages.rank_summary', $groupsRanked, ['count' => $groupsRanked, 'type' => $type->value, 'by' => $by->value]);
        $truncation = $rows->truncation('groups', __('firewatch::messages.rank_truncated_how'));
        $notes = $this->notes($by, $ranked);

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
            truncated: $truncation === null ? [] : [$truncation],
        );
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
            $notes[] = __('firewatch::messages.rank_fallback', ['statistic' => $floor[0], 'needed' => $floor[1]]);
        }

        if ($ranked['untimed'] > 0) {
            $notes[] = trans_choice('firewatch::messages.rank_untimed', $ranked['untimed'], ['count' => $ranked['untimed']]);
        }

        return $notes;
    }

    /**
     * Read the type to rank, which must be one that has groups.
     */
    protected function type(Request $request): RecordType
    {
        $types = implode(', ', array_map(fn (RecordType $type) => $type->value, Measure::types()));
        $example = 'rank(type: "request", by: "p95_duration")';
        $value = $request->get('type');

        if ($value === null) {
            throw Refusal::missing(argument: 'type', accepted: $types, example: $example);
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
    protected function measure(Request $request, RecordType $type): Measure
    {
        $value = $request->get('by');
        $default = Measure::default($type);

        if ($value === null) {
            return $default;
        }

        $measure = is_string($value) ? Measure::tryFrom($value) : null;

        if ($measure !== null && $measure->fits($type)) {
            return $measure;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);
        $accepted = implode(', ', array_map(fn (Measure $measure) => $measure->value, Measure::for($type)));

        throw Refusal::invalid(argument: 'by', expected: "a measure of {$type->value}", value: $shown, accepted: $accepted, example: "rank(type: \"{$type->value}\", by: \"{$default->value}\")");
    }

    /**
     * Read the most rows to list, from 1 to 100.
     */
    protected function limit(Request $request, RecordType $type): int
    {
        $value = $request->get('limit');

        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }

        if (is_int($value) && $value >= 1 && $value <= self::MAXIMUM_LIMIT) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'limit', expected: '1 to '.self::MAXIMUM_LIMIT, value: $shown, accepted: 'a whole number from 1 to '.self::MAXIMUM_LIMIT, example: "rank(type: \"{$type->value}\", limit: ".self::DEFAULT_LIMIT.')');
    }

    /**
     * Read the exact deploy the records are restricted to, or null for all of them.
     */
    protected function deploy(Request $request, RecordType $type): ?string
    {
        $value = $request->get('deploy');

        if ($value === null || is_string($value)) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'deploy', expected: 'an exact deploy string', value: $shown, accepted: 'an exact deploy string', example: "rank(type: \"{$type->value}\", deploy: \"v1\")");
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
