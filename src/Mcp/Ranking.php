<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Stage;
use SQLite3;

/**
 * @internal
 */
class Ranking
{
    /**
     * The key a record without a deploy has among the deploys of a breakdown, which no deploy string is.
     */
    protected const NO_DEPLOY = "\x01";

    /**
     * Create a new ranking instance.
     */
    public function __construct(
        protected Population $population,
        protected Measure $by,
        protected ?string $matching = null,
        protected ?Configuration $configuration = null,
    ) {
        //
    }

    /**
     * Read one page of the groups of the type in the window, worst first by the measure: at most the limit, after the key of the last row of the page before.
     *
     * @param  array{value: int|float|null, occurrences: int, hash: string}|null  $after
     * @return array{page: Rows<array{value: int|float|null, occurrences: int, hash: string}>, rows: list<array<string, mixed>>, ranked: int, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}
     */
    public function read(SQLite3 $connection, ?array $after, int $limit): array
    {
        [$records, $withoutGroup] = array_values($this->population->query($connection, 'SELECT count(*) AS records, count(*) FILTER (WHERE group_hash IS NULL) AS without FROM base')[0]);

        $measured = $this->population->measured($connection, $this->by);
        $measured = $this->matched($connection, $measured);

        [$orderedBy, $measured] = $this->ordering($connection, $measured);

        $untimed = $this->population->hasDuration() ? array_sum(array_map(fn (array $group) => $group['occurrences'] - $group['timed'], $measured)) : 0;
        $keys = array_map(fn (array $group) => [
            'value' => $group['value'],
            'occurrences' => $group['occurrences'],
            'hash' => $group['hash'],
        ], array_values($measured));

        usort($keys, self::compare(...));

        $following = array_filter($keys, fn (array $key) => $after === null || self::compare($key, $after) > 0);
        $fetched = array_slice(array_values($following), 0, Rows::fetch($limit));
        $page = Rows::bound($fetched, $limit);
        $groups = $this->page($connection, array_column($page->rows, 'hash'));

        return [
            'page' => $page,
            'rows' => array_map(fn (array $key) => $this->row($groups[$key['hash']]), $page->rows),
            'ranked' => count($keys),
            'records' => $records,
            'withoutGroup' => $withoutGroup,
            'untimed' => $untimed,
            'orderedBy' => $orderedBy,
        ];
    }

    /**
     * Get the measure the groups are ordered by and their values of it, which is the fallback of the measure when no group has a value of the measure itself.
     *
     * @param  array<string, array{value: int|float|null, occurrences: int, hash: string, timed: int}>  $measured
     * @return array{Measure, array<string, array{value: int|float|null, occurrences: int, hash: string, timed: int}>}
     */
    protected function ordering(SQLite3 $connection, array $measured): array
    {
        $fallback = $this->by->fallback();
        $valued = array_filter(array_column($measured, 'value'), fn (int|float|null $value) => $value !== null);

        if ($fallback === null || $measured === [] || $valued !== []) {
            return [$this->by, $measured];
        }

        $values = $this->population->measured($connection, $fallback);

        return [$fallback, array_intersect_key($values, $measured)];
    }

    /**
     * Keep the groups whose label contains the text the ranking matches, or every group when it matches no text.
     *
     * @template TGroup
     *
     * @param  array<string, TGroup>  $groups
     * @return array<string, TGroup>
     */
    protected function matched(SQLite3 $connection, array $groups): array
    {
        if ($this->matching === null) {
            return $groups;
        }

        $latest = $this->population->latest($connection);
        $labels = array_column($latest, 'label', 'group_hash');

        return array_filter($groups, fn (string $hash) => mb_stripos($this->population->type->groupLabel($labels[$hash] ?? null), $this->matching) !== false, ARRAY_FILTER_USE_KEY);
    }

    /**
     * Read the deploys one group was recorded under in the window, in the order they were first seen, and only the most recent ones when there are more than the limit, with the group's stage view.
     *
     * @return array{page: Rows<array<string, mixed>>, rows: list<array<string, mixed>>, matched: int, records: int, label: string, stages: StageView|null}
     */
    public function breakdown(SQLite3 $connection, int $limit): array
    {
        [$records] = array_values($this->population->query($connection, 'SELECT count(*) AS records FROM base')[0]);

        $deploys = array_values($this->statistics($connection, $this->population));
        $label = '';

        if ($deploys !== []) {
            usort($deploys, fn (array $a, array $b) => $b['last'] <=> $a['last']);
            $label = $this->label($deploys[0]);
        }

        $page = Rows::bound($deploys, $limit);
        $shown = $page->rows;

        usort($shown, fn (array $a, array $b) => ($a['wfirst'] <=> $b['wfirst']) ?: strcmp($a['hash'], $b['hash']));

        $stages = $this->stages($connection, $records);

        return [
            'page' => $page,
            'rows' => array_map($this->deployRow(...), $shown),
            'matched' => count($deploys),
            'records' => $records,
            'label' => $label,
            'stages' => $stages,
        ];
    }

    /**
     * Read the stage view of the records, over the executions that have every stage of their type, or null for a type that records no stages.
     */
    protected function stages(SQLite3 $connection, int $records): ?StageView
    {
        $stages = Stage::of($this->population->type);

        if ($stages === []) {
            return null;
        }

        $complete = implode(' AND ', array_map(fn (Stage $stage) => "{$stage->value} IS NOT NULL", $stages));
        $sumColumns = implode(', ', array_map(fn (Stage $stage) => "sum({$stage->value}) AS {$stage->value}", $stages));

        $totals = $this->population->query($connection, "SELECT count(*) AS executions, {$sumColumns} FROM base WHERE {$complete}")[0];
        $row = $this->population->query($connection, "SELECT * FROM base WHERE {$complete} AND d IS NOT NULL ORDER BY d DESC, id DESC LIMIT 1")[0] ?? null;

        $sums = [];

        foreach ($stages as $stage) {
            $sums[$stage->value] = $totals[$stage->value] ?? 0;
        }

        $executions = $totals['executions'];
        $slowest = $row === null ? null : new SlowestExecution($row['execution_id'], $row['d'], array_intersect_key($row, $sums));

        return new StageView($this->population->type, $executions, $records - $executions, $sums, $slowest);
    }

    /**
     * Pick the type of a group that no type was asked for: a job group is held by job attempts and dispatches, and the attempts carry the execution measures.
     *
     * @param  list<RecordType>  $held
     */
    public static function preferred(array $held): ?RecordType
    {
        return in_array(RecordType::JOB_ATTEMPT, $held, true) ? RecordType::JOB_ATTEMPT : ($held[0] ?? null);
    }

    /**
     * Read what the records of the groups of a page add up to, with when each was first seen in the store and its slowest execution, keyed by group hash.
     *
     * @param  list<string>  $hashes
     * @return array<string, array<string, mixed>>
     */
    protected function page(SQLite3 $connection, array $hashes): array
    {
        $population = $this->population->only($hashes);
        $groups = $this->statistics($connection, $population);

        foreach ($population->firstSeen($connection) as $hash => $first) {
            $groups[$hash]['first'] = $first;
        }

        if (! $population->hasDuration()) {
            return $groups;
        }

        $slowest = $population->query($connection, 'SELECT group_hash, execution_id FROM (SELECT group_hash, execution_id, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY d DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL AND d IS NOT NULL) WHERE rn = 1');

        foreach ($slowest as $row) {
            $groups[$row['group_hash']]['slowest'] = $row['execution_id'];
        }

        return $groups;
    }

    /**
     * Read the statistics of every group of a population, with the budget verdict of each when the type has budgets, keyed by group hash.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function statistics(SQLite3 $connection, Population $population): array
    {
        $groups = $population->statistics($connection);

        if ($this->configuration === null || ! $population->type->isExecution()) {
            return $groups;
        }

        $type = ExecutionType::from($population->type->value);

        foreach ($population->budgets($connection) as $hash => $budget) {
            $groups[$hash]['budget'] = BudgetVerdict::ofGroup($this->configuration, $type, $budget)->cell();
        }

        return $groups;
    }

    /**
     * Order two keys worst first; a group without the value comes last, and ties go to the group with more records, then to the lower hash.
     *
     * @param  array{value: int|float|null, occurrences: int, hash: string}  $a
     * @param  array{value: int|float|null, occurrences: int, hash: string}  $b
     */
    public static function compare(array $a, array $b): int
    {
        return match (true) {
            $a['value'] === null && $b['value'] !== null => 1,
            $a['value'] !== null && $b['value'] === null => -1,
            default => ($b['value'] <=> $a['value']) ?: ($b['occurrences'] <=> $a['occurrences']) ?: strcmp($a['hash'], $b['hash']),
        };
    }

    /**
     * Get the label of a group as it is shown and matched.
     *
     * @param  array<string, mixed>  $group
     */
    protected function label(array $group): string
    {
        return $this->population->type->groupLabel($group['label']);
    }

    /**
     * Get the row of the answer for a group.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    protected function row(array $group): array
    {
        $row = [
            'group' => $group['hash'],
            'label' => $this->label($group),
        ];

        if ($this->population->hasMethod()) {
            $row['method'] = $group['method'];
        }

        $row['occurrences'] = $group['occurrences'];

        $withheld = [];

        if ($this->population->hasDuration()) {
            $p50 = $this->floored($group, 'p50_ms', $group['timed'], Percentile::MEDIAN->floor(), $withheld);
            $p95 = $this->floored($group, 'p95_ms', $group['timed'], Percentile::P95->floor(), $withheld);

            $row += [
                'min_ms' => Stored::milliseconds($group['min']),
                'p50_ms' => Stored::milliseconds($p50),
                'avg_ms' => Stored::milliseconds($group['avg']),
                'p95_ms' => Stored::milliseconds($p95),
                'max_ms' => Stored::milliseconds($group['max']),
                'total_ms' => Stored::milliseconds($group['total']),
            ];
        }

        if ($this->population->type->isExecution()) {
            $memory = $this->floored($group, 'p95_memory_mb', $group['mem_timed'], Percentile::P95->floor(), $withheld, 'mem_p95');

            $row += [
                'p95_memory_mb' => Stored::megabytes($memory),
                'max_memory_mb' => Stored::megabytes($group['mem_max']),
                'queries' => $group['queries'] ?? null,
            ];
        }

        $failed = $group['failed_of'] ?? 0;

        $row += [
            'failure_pct' => Percentile::percent($group['failed'] ?? 0, $failed),
            'first_seen_at' => $group['first'],
            'last_seen_at' => $group['last'],
            'deploys' => $group['deploys'],
        ];

        if ($this->population->hasDuration()) {
            $row += [
                'slowest_execution_id' => $group['slowest'],
                'withheld' => $withheld === [] ? null : $withheld,
                'values_ms' => $group['raw'] === null ? null : array_map(Stored::milliseconds(...), $group['raw']),
            ];
        }

        if (isset($group['budget'])) {
            $row['budget'] = $group['budget'];
        }

        return $row;
    }

    /**
     * Get the row of the breakdown for a deploy.
     *
     * @param  array<string, mixed>  $deploy
     * @return array<string, mixed>
     */
    protected function deployRow(array $deploy): array
    {
        $row = [
            'deploy' => $deploy['hash'] === self::NO_DEPLOY ? __('firewatch::messages.rank_no_deploy') : $deploy['hash'],
            'occurrences' => $deploy['occurrences'],
        ];
        $withheld = [];

        if ($this->population->hasDuration()) {
            $p50 = $this->floored($deploy, 'p50_ms', $deploy['timed'], Percentile::MEDIAN->floor(), $withheld);
            $p95 = $this->floored($deploy, 'p95_ms', $deploy['timed'], Percentile::P95->floor(), $withheld);

            $row += [
                'p50_ms' => Stored::milliseconds($p50),
                'p95_ms' => Stored::milliseconds($p95),
                'max_ms' => Stored::milliseconds($deploy['max']),
            ];
        }

        $row += [
            'first_at' => $deploy['wfirst'],
            'last_at' => $deploy['last'],
        ];

        if ($this->population->hasDuration()) {
            $row += [
                'withheld' => $withheld === [] ? null : $withheld,
                'values_ms' => $deploy['raw'] === null ? null : array_map(Stored::milliseconds(...), $deploy['raw']),
            ];
        }

        if (isset($deploy['budget'])) {
            $row['budget'] = $deploy['budget'];
        }

        return $row;
    }

    /**
     * Get a percentile of a group, or null and the reason it is withheld when too few records support it.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $withheld
     */
    protected function floored(array $group, string $name, int $have, int $needed, array &$withheld, ?string $key = null): int|float|null
    {
        $key ??= str_starts_with($name, 'p50') ? 'p50' : 'p95';

        if ($have >= $needed) {
            return $group[$key];
        }

        if ($have > 0) {
            $withheld[$name] = [
                'reason' => WithheldReason::SAMPLE_TOO_SMALL->value,
                'have' => $have,
                'needed' => $needed,
            ];
        }

        return null;
    }
}
