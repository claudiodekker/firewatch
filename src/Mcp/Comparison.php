<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-type Side array{deploy: string|null, since: float|null, until: float|null, clipped: bool, outside: bool, empty: bool, records: int, first: float|null, last: float|null, earlier: array{records: int, more: bool}|null}
 * @phpstan-type Row array{hash: string, label: mixed, method: mixed, beforeRecords: int, afterRecords: int, before: int|float|null, after: int|float|null, change: Change, steppedDown: bool, reason: ComparisonReason|null, have: int|null, needed: int|null}
 */
class Comparison
{
    /**
     * The most deploys an answer with an empty side lists.
     */
    protected const DEPLOYS_LISTED = 10;

    /**
     * The most records that started before the coverage start an answer counts.
     */
    protected const EARLIER_COUNTED = 100;

    /**
     * The count of a side that counts the records before the coverage start, until it reads them.
     */
    protected const NONE_EARLIER = [
        'records' => 0,
        'more' => false,
    ];

    /**
     * The most times longer one side's observed span may be than the other's for a volume measure to be judged.
     */
    protected const SPAN_RATIO = 2;

    /**
     * The decimals a change percentage is rounded to.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * The fewest records a side needs for a measure that is no percentile.
     */
    protected const ONE_RECORD = 1;

    /**
     * What a row that stepped down from the 95th percentile states it was measured on.
     */
    protected const MEASURED_ON_MEDIAN = 'p50';

    /**
     * Create a new comparison instance.
     *
     * @param  Side  $before
     * @param  Side  $after
     * @param  Rows<Row>  $groups  in the order of the answer
     * @param  array<string, int>  $changes  every group's change counted, by its value, over the groups shown and cut
     * @param  array{ComparisonReason, string}|null  $unevaluated
     * @param  Rows<array{deploy: string, records: int, first_at: float}>|null  $deploys  the deploys of the window, listed only when a side is empty
     */
    protected function __construct(
        public readonly Boundary $boundary,
        public readonly RecordType $type,
        public readonly Measure $by,
        public readonly array $before,
        public readonly array $after,
        public readonly Rows $groups,
        public readonly array $changes,
        public readonly ?int $straddling,
        protected readonly ?array $unevaluated,
        public readonly ?Rows $deploys,
    ) {
        //
    }

    /**
     * Compare the groups of the type on the two sides of the boundary, a split of the window or a deploy pair, in the snapshot of the connection.
     */
    public static function of(SQLite3 $connection, RecordType $type, Measure $by, Window $window, Boundary $boundary, ?float $coverageStart, ?string $group, int $limit): self
    {
        [$before, $after] = $boundary->sides($window, $coverageStart);

        $beforeOfType = self::statistics($connection, $type, $by, $before, $window->timezone());
        $afterOfType = self::statistics($connection, $type, $by, $after, $window->timezone());
        $beforeGroups = self::selected($beforeOfType, $group);
        $afterGroups = self::selected($afterOfType, $group);

        $before = self::earlier($connection, $type, self::counted($before, $beforeOfType, $beforeGroups), $coverageStart);
        $after = self::earlier($connection, $type, self::counted($after, $afterOfType, $afterGroups), $coverageStart);

        $evaluated = ! $before['empty'] && ! $after['empty'] && ! $before['outside'] && ! $after['outside'];
        $rows = $evaluated ? self::rows($by, $beforeGroups, $afterGroups, self::likeSpans($before, $after)) : [];
        $changes = array_count_values(array_map(fn (array $row) => $row['change']->value, $rows));

        $straddling = $boundary->straddling($connection, $type, $before, $group);
        $sides = Window::between($before['since'], $after['until'], $window->timezone());
        $unevaluated = self::unevaluatedOn($connection, $type, $before, $after, $sides);
        $deploys = $unevaluated !== null && $unevaluated[0] === ComparisonReason::EMPTY_SIDE ? self::deploys($connection, $type, $sides, $group) : null;

        return new self($boundary, $type, $by, $before, $after, Rows::bound($rows, $limit), $changes, $straddling, $unevaluated, $deploys);
    }

    /**
     * Get why the comparison could not run at all, and the side it could not run on, or null when it ran.
     *
     * @return array{ComparisonReason, string}|null
     */
    public function unevaluated(): ?array
    {
        return $this->unevaluated;
    }

    /**
     * Determine if nothing matched: the window of the sides holds no record of the type, or the one group asked for has none in a window whose sides both hold the type.
     */
    public function isEmpty(): bool
    {
        return $this->unevaluated === null && $this->changes === [];
    }

    /**
     * Get how many groups were compared, shown or cut.
     */
    public function matched(): int
    {
        return array_sum($this->changes);
    }

    /**
     * Get the group of the first row, or null when there is none.
     */
    public function first(): ?string
    {
        return $this->groups->rows[0]['hash'] ?? null;
    }

    /**
     * Get the deploys of the sides on which the group of the first row has records, in side order, none on a split.
     *
     * @return list<string>
     */
    public function firstDeploys(): array
    {
        $row = $this->groups->rows[0] ?? null;
        $deploys = [];

        if ($row !== null && $row['beforeRecords'] > 0 && $this->before['deploy'] !== null) {
            $deploys[] = $this->before['deploy'];
        }

        if ($row !== null && $row['afterRecords'] > 0 && $this->after['deploy'] !== null) {
            $deploys[] = $this->after['deploy'];
        }

        return $deploys;
    }

    /**
     * Get every change that some group has, counted, in the order of the changes, as a summary lists them.
     */
    public function counts(): string
    {
        $counted = array_filter(Change::cases(), fn (Change $change) => ($this->changes[$change->value] ?? 0) > 0);

        return implode(', ', array_map(fn (Change $change) => $this->changes[$change->value].' '.$change->value, $counted));
    }

    /**
     * Get the result of the answer.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        [$reason, $side] = $this->unevaluated() ?? [null, null];

        return [
            'type' => $this->type->value,
            'by' => $this->by->value,
            'change' => $reason === null ? null : Change::NOT_EVALUATED->value,
            'reason' => $reason?->value,
            'side' => $side,
            'before' => $this->sideResult($this->before),
            'after' => $this->sideResult($this->after),
            'rollup' => $reason === null ? $this->rollup() : null,
            'groups' => array_map($this->rowResult(...), $this->groups->rows),
            'deploys' => $this->deploys?->rows,
        ];
    }

    /**
     * Get a result with its rollup counting as cut every group its rows no longer show, for a result the size budget shortened.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function recounted(array $result): array
    {
        if (is_array($result['rollup']) && is_array($result['groups'])) {
            $result['rollup']['cut'] = $this->matched() - count($result['groups']);
        }

        return $result;
    }

    /**
     * Get the rollup: every change counted over the groups shown and cut, then the groups on one side only and the groups cut.
     *
     * @return array<string, int>
     */
    protected function rollup(): array
    {
        $rollup = ['groups' => $this->matched()];

        foreach (Change::cases() as $change) {
            $rollup[$change->value] = $this->changes[$change->value] ?? 0;
        }

        $rollup['one_side_only'] = $rollup[Change::NEW->value] + $rollup[Change::GONE->value];
        $rollup['cut'] = $this->matched() - count($this->groups->rows);

        return $rollup;
    }

    /**
     * Get a side as the answer states it, led by its deploy on a deploy pair, and closed by the records that started before the coverage start where it counts them.
     *
     * @param  Side  $side
     * @return array{deploy?: string, since_at: float|null, until_at: float|null, clipped: bool, records: int, observed_span_ms: float|null, earlier_records?: int, earlier_more?: bool}
     */
    protected function sideResult(array $side): array
    {
        $span = self::span($side);
        $deploy = $side['deploy'] === null ? [] : ['deploy' => $side['deploy']];
        $earlier = $side['earlier'] === null ? [] : [
            'earlier_records' => $side['earlier']['records'],
            'earlier_more' => $side['earlier']['more'],
        ];

        return [
            ...$deploy,
            'since_at' => $side['since'],
            'until_at' => $side['until'],
            'clipped' => $side['clipped'],
            'records' => $side['records'],
            'observed_span_ms' => Stored::milliseconds($span),
            ...$earlier,
        ];
    }

    /**
     * Get a row as the answer states it, its values in the unit of the measure.
     *
     * @param  Row  $row
     * @return array<string, mixed>
     */
    protected function rowResult(array $row): array
    {
        $unit = $this->by->unit();
        $difference = $row['before'] === null || $row['after'] === null ? null : $row['after'] - $row['before'];
        $percent = self::percent($row['before'], $row['after']);

        $shown = [
            'group' => $row['hash'],
            'label' => Ranking::shownLabel($this->type, $row['label']),
        ];

        if (in_array($this->type, [RecordType::REQUEST, RecordType::OUTGOING_REQUEST], true)) {
            $shown['method'] = $row['method'];
        }

        return [
            ...$shown,
            'before_records' => $row['beforeRecords'],
            'after_records' => $row['afterRecords'],
            "before{$unit}" => $this->by->shown($row['before']),
            "after{$unit}" => $this->by->shown($row['after']),
            "difference{$unit}" => $this->by->shown($difference),
            'change_pct' => $percent === null ? null : round($percent, self::PERCENT_DECIMALS),
            'change' => $row['change']->value,
            'measured_on' => $row['steppedDown'] ? self::MEASURED_ON_MEDIAN : null,
            'reason' => $row['reason']?->value,
            'have' => $row['have'],
            'needed' => $row['needed'],
        ];
    }

    /**
     * Get a side clipped to the type's coverage start, whether nothing of it is left, and whether it counts the records that started before that start.
     *
     * @return Side
     */
    public static function side(?float $since, ?float $until, ?string $deploy, ?float $coverageStart, bool $countsEarlier): array
    {
        $clipped = $coverageStart !== null && ($since === null || $since < $coverageStart);
        $start = $clipped ? $coverageStart : $since;

        return [
            'deploy' => $deploy,
            'since' => $start,
            'until' => $until,
            'clipped' => $clipped,
            'outside' => $start !== null && $until !== null && $until <= $start,
            'empty' => true,
            'records' => 0,
            'first' => null,
            'last' => null,
            'earlier' => $countsEarlier ? self::NONE_EARLIER : null,
        ];
    }

    /**
     * Get why a comparison of the sides cannot run, and the side, or both, it cannot run on: a side outside coverage, or a side with no record of the type while the window of the sides holds some.
     *
     * @param  Side  $before
     * @param  Side  $after
     * @return array{ComparisonReason, string}|null
     */
    protected static function unevaluatedOn(SQLite3 $connection, RecordType $type, array $before, array $after, Window $sides): ?array
    {
        return match (true) {
            $before['outside'] => [ComparisonReason::OUTSIDE_COVERAGE, 'before'],
            $after['outside'] => [ComparisonReason::OUTSIDE_COVERAGE, 'after'],
            $before['empty'] && $after['empty'] => self::holds($connection, $type, $sides) ? [ComparisonReason::EMPTY_SIDE, 'both'] : null,
            $before['empty'] => [ComparisonReason::EMPTY_SIDE, 'before'],
            $after['empty'] => [ComparisonReason::EMPTY_SIDE, 'after'],
            default => null,
        };
    }

    /**
     * Determine if the window holds a record of the type, which two sides of a split that are both empty never do and two deploys may.
     */
    protected static function holds(SQLite3 $connection, RecordType $type, Window $window): bool
    {
        $rows = Stored::rows($connection, 'SELECT EXISTS (SELECT 1 FROM '.$type->view().' WHERE '.$window->condition().') AS held', [], $window);

        return $rows[0]['held'] === 1;
    }

    /**
     * Read the statistics of every group of the type on one side, of its deploy alone on a deploy pair.
     *
     * @param  Side  $side
     * @return array<string, array<string, mixed>>
     */
    protected static function statistics(SQLite3 $connection, RecordType $type, Measure $by, array $side, string $timezone): array
    {
        if ($side['outside']) {
            return [];
        }

        $ranking = new Ranking($type, $by, Window::between($side['since'], $side['until'], $timezone), deploy: $side['deploy']);

        return $ranking->statistics($connection);
    }

    /**
     * Get the groups a comparison selects: every group of the type, or the one asked for.
     *
     * @param  array<string, array<string, mixed>>  $ofType
     * @return array<string, array<string, mixed>>
     */
    protected static function selected(array $ofType, ?string $group): array
    {
        return $group === null ? $ofType : array_intersect_key($ofType, [$group => true]);
    }

    /**
     * Get a side with whether it holds no record of the type at all, the records its selected groups hold and when the first and the last of them started.
     *
     * @param  Side  $side
     * @param  array<string, array<string, mixed>>  $ofType
     * @param  array<string, array<string, mixed>>  $groups
     * @return Side
     */
    protected static function counted(array $side, array $ofType, array $groups): array
    {
        $side['empty'] = $ofType === [];

        $firsts = array_column($groups, 'wfirst');
        $lasts = array_column($groups, 'last');

        if ($firsts === [] || $lasts === []) {
            return $side;
        }

        return [
            ...$side,
            'records' => array_sum(array_column($groups, 'occurrences')),
            'first' => min($firsts),
            'last' => max($lasts),
        ];
    }

    /**
     * Get the observed span of a side in whole microseconds, from the first to the last start among its records, or null below two records.
     *
     * @param  Side  $side
     */
    protected static function span(array $side): ?int
    {
        if ($side['records'] < 2 || $side['first'] === null || $side['last'] === null) {
            return null;
        }

        // A difference of two float instants carries their rounding error, which tips a ratio of exactly 2.
        return (int) round($side['last'] * Microseconds::PER_SECOND) - (int) round($side['first'] * Microseconds::PER_SECOND);
    }

    /**
     * Determine if the observed spans of the two sides are known and the longer is at most twice the shorter, so that a volume measure can be judged.
     *
     * @param  Side  $before
     * @param  Side  $after
     */
    protected static function likeSpans(array $before, array $after): bool
    {
        $spans = [self::span($before), self::span($after)];

        if (in_array(null, $spans, true)) {
            return false;
        }

        return max($spans) <= self::SPAN_RATIO * min($spans);
    }

    /**
     * Get the row of every group on either side, in the order of the answer.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @return list<Row>
     */
    protected static function rows(Measure $by, array $before, array $after, bool $likeSpans): array
    {
        $hashes = array_keys($before + $after);
        $rows = array_map(fn (string $hash) => self::row($by, $before[$hash] ?? null, $after[$hash] ?? null, $likeSpans), $hashes);

        usort($rows, self::compare(...));

        return $rows;
    }

    /**
     * Get the row of one group, its change decided by the change rule from the statistic both sides have records enough for.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return Row
     */
    protected static function row(Measure $by, ?array $before, ?array $after, bool $likeSpans): array
    {
        /** @var array<string, mixed> $present */
        $present = $after ?? $before;

        $row = [
            'hash' => $present['hash'],
            'label' => $present['label'],
            'method' => $present['method'],
            'beforeRecords' => $before['occurrences'] ?? 0,
            'afterRecords' => $after['occurrences'] ?? 0,
            'before' => null,
            'after' => null,
            'change' => Change::NOT_EVALUATED,
            'steppedDown' => false,
            'reason' => null,
            'have' => null,
            'needed' => null,
        ];

        if ($before === null || $after === null) {
            return [...$row, ...self::oneSided($by, $present, $before === null ? 'after' : 'before')];
        }

        $have = min(self::have($before, $by), self::have($after, $by));
        $measure = self::measured($by, $have);
        $beforeValue = Ranking::value($before, $measure);
        $afterValue = Ranking::value($after, $measure);

        if ($beforeValue === null || $afterValue === null) {
            return [
                ...$row,
                'reason' => ComparisonReason::SAMPLE_TOO_SMALL,
                'have' => $have,
                'needed' => self::needed($measure),
            ];
        }

        $row = [
            ...$row,
            'before' => $beforeValue,
            'after' => $afterValue,
            'steppedDown' => $measure !== $by,
        ];

        if ($by->isVolume() && ! $likeSpans) {
            $row['reason'] = ComparisonReason::UNEQUAL_SPANS;

            return $row;
        }

        $row['change'] = Change::of($measure, $beforeValue, $afterValue);

        return $row;
    }

    /**
     * Get the values and the change of a group on one side only: new or gone, with the present side's value when it has records enough for one.
     *
     * @param  array<string, mixed>  $present
     * @return array{before: int|float|null, after: int|float|null, change: Change, steppedDown: bool}
     */
    protected static function oneSided(Measure $by, array $present, string $side): array
    {
        $measure = self::measured($by, self::have($present, $by));
        $value = Ranking::value($present, $measure);

        return [
            'before' => $side === 'before' ? $value : null,
            'after' => $side === 'after' ? $value : null,
            'change' => $side === 'after' ? Change::NEW : Change::GONE,
            'steppedDown' => $value !== null && $measure !== $by,
        ];
    }

    /**
     * Get the statistic a row is measured on: the measure, or its median when the records had fall below the 95th percentile's floor.
     */
    protected static function measured(Measure $by, int $have): Measure
    {
        $stepDown = $by->stepDown();

        return $stepDown !== null && $have < self::needed($by) ? $stepDown : $by;
    }

    /**
     * Get the fewest records each side needs for the measure.
     */
    protected static function needed(Measure $measure): int
    {
        return $measure->percentile()?->floor() ?? self::ONE_RECORD;
    }

    /**
     * Get how many records of a group have the quantity a measure is computed from.
     *
     * @param  array<string, mixed>  $group
     */
    protected static function have(array $group, Measure $measure): int
    {
        return match ($measure) {
            Measure::P95_DURATION, Measure::P50_DURATION, Measure::MAX_DURATION, Measure::TOTAL_DURATION => $group['timed'],
            Measure::P95_MEMORY, Measure::P50_MEMORY, Measure::MAX_MEMORY => $group['mem_timed'],
            Measure::QUERIES => ($group['queries'] ?? null) === null ? 0 : $group['occurrences'],
            default => $group['occurrences'],
        };
    }

    /**
     * Get the exact change of a value in percent, or null when the before value is 0 or either value is missing.
     */
    protected static function percent(int|float|null $before, int|float|null $after): ?float
    {
        if ($before === null || $after === null || $before <= 0) {
            return null;
        }

        return ($after - $before) / $before * Ranking::PERCENT;
    }

    /**
     * Order two rows as the answer lists them: moved groups by the size of their change, then new, then gone, then the rest by their after value, ties by group hash.
     *
     * @param  Row  $a
     * @param  Row  $b
     */
    protected static function compare(array $a, array $b): int
    {
        return (self::position($a) <=> self::position($b))
            ?: (self::magnitude($b) <=> self::magnitude($a))
            ?: strcmp($a['hash'], $b['hash']);
    }

    /**
     * Get the place of a row's change in the order of the answer.
     *
     * @param  Row  $row
     */
    protected static function position(array $row): int
    {
        return match (true) {
            $row['change']->moved() => 0,
            $row['change'] === Change::NEW => 1,
            $row['change'] === Change::GONE => 2,
            default => 3,
        };
    }

    /**
     * Get what orders a row among those of its place: the size of a moved group's change, else the value of its present or after side, a missing one last.
     *
     * @param  Row  $row
     */
    protected static function magnitude(array $row): float
    {
        $value = match (true) {
            $row['change']->moved() => abs((float) self::percent($row['before'], $row['after'])),
            $row['change'] === Change::GONE => $row['before'],
            default => $row['after'],
        };

        return $value === null ? -INF : (float) $value;
    }

    /**
     * Get a side that counts them with the records of the type, of its deploy alone on a deploy pair, that started before the coverage start it begins at, which it leaves out, up to a cap.
     *
     * @param  Side  $side
     * @return Side
     */
    protected static function earlier(SQLite3 $connection, RecordType $type, array $side, ?float $coverageStart): array
    {
        if ($side['earlier'] === null || $coverageStart === null || $side['since'] !== $coverageStart) {
            return $side;
        }

        $rows = Stored::rows($connection, 'SELECT count(*) AS earlier FROM (SELECT 1 FROM '.$type->view().' WHERE started_at < :start AND (:deploy IS NULL OR deploy = :deploy) LIMIT '.Rows::fetch(self::EARLIER_COUNTED).')', [
            'start' => $coverageStart,
            'deploy' => $side['deploy'],
        ]);
        $counted = is_int($rows[0]['earlier']) ? $rows[0]['earlier'] : 0;

        $side['earlier'] = [
            'records' => min($counted, self::EARLIER_COUNTED),
            'more' => $counted > self::EARLIER_COUNTED,
        ];

        return $side;
    }

    /**
     * Read the deploys the records of the type carry in the window, with their records, in the order they were first seen, up to the most an answer lists.
     *
     * @return Rows<array{deploy: string, records: int, first_at: float}>
     */
    protected static function deploys(SQLite3 $connection, RecordType $type, Window $window, ?string $group): Rows
    {
        $rows = Stored::rows($connection, 'SELECT deploy, count(*) AS records, min(started_at) AS first_at FROM '.$type->view().' WHERE '.$window->condition()." AND deploy IS NOT NULL AND deploy <> '' AND (:group IS NULL OR group_hash = :group) GROUP BY deploy ORDER BY first_at, deploy LIMIT ".Rows::fetch(self::DEPLOYS_LISTED), ['group' => $group], $window);

        /** @var list<array{deploy: string, records: int, first_at: float}> $rows */
        return Rows::bound($rows, self::DEPLOYS_LISTED);
    }
}
