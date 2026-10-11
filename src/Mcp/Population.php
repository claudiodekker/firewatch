<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Stage;
use LogicException;
use SQLite3;

/**
 * @internal
 */
class Population
{
    /**
     * Create a new population instance.
     *
     * @param  string|null  $group  the one group whose records are keyed by their deploy instead of their group
     * @param  list<string>|null  $hashes  the groups the records are narrowed to, or null for every group
     */
    public function __construct(
        public readonly RecordType $type,
        public readonly Window $window,
        public readonly ?string $deploy = null,
        public readonly ?string $group = null,
        public readonly ?array $hashes = null,
    ) {
        //
    }

    /**
     * Get the population narrowed to the records of the groups with these hashes.
     *
     * @param  list<string>  $hashes
     */
    public function only(array $hashes): self
    {
        return new self($this->type, $this->window, $this->deploy, $this->group, $hashes);
    }

    /**
     * Read the value of a measure for every group, with its records and the records that have a duration, keyed by group hash.
     *
     * @return array<string, array{value: int|float|null, occurrences: int, hash: string, timed: int}>
     */
    public function measured(SQLite3 $connection, Measure $measure): array
    {
        $sql = match ($measure) {
            Measure::P95_DURATION => $this->percentile('d', Percentile::P95),
            Measure::P50_DURATION => $this->percentile('d', Percentile::MEDIAN),
            Measure::P95_MEMORY => $this->percentile('m', Percentile::P95),
            Measure::P50_MEMORY => $this->percentile('m', Percentile::MEDIAN),
            Measure::MAX_DURATION => $this->aggregated('max(d)'),
            Measure::AVG_DURATION => $this->aggregated('avg(d)'),
            Measure::TOTAL_DURATION => $this->aggregated('sum(d)'),
            Measure::OCCURRENCES => $this->aggregated('count(*)'),
            Measure::MAX_MEMORY => $this->aggregated('max(m)'),
            Measure::LAST_SEEN => $this->aggregated('max(started_at)'),
            Measure::QUERIES => $this->aggregated('sum(q)'),
        };

        $measured = [];

        foreach ($this->query($connection, $sql) as $row) {
            $measured[$row['group_hash']] = [
                'value' => $row['value'],
                'occurrences' => $row['occurrences'],
                'hash' => $row['group_hash'],
                'timed' => $row['timed'],
            ];
        }

        return $measured;
    }

    /**
     * Read the label of the latest record of every group, with its method for a type shown with one.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(SQLite3 $connection): array
    {
        $method = $this->hasMethod() ? ', method' : '';

        return $this->query($connection, "SELECT group_hash, label{$method} FROM (SELECT group_hash, label{$method}, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL) WHERE rn = 1");
    }

    /**
     * Read when every group was first seen in the store, outside the window and under any deploy, keyed by group hash.
     *
     * @return array<string, float>
     */
    public function firstSeen(SQLite3 $connection): array
    {
        $rows = Stored::rows($connection, 'SELECT group_hash, min(started_at) AS first FROM '.$this->source().' WHERE group_hash IS NOT NULL GROUP BY group_hash', $this->narrowing());

        return array_column($rows, 'first', 'group_hash');
    }

    /**
     * Find when the first and the last record the filters select started, or null when they select none.
     *
     * @return array{float, float}|null
     */
    public function extent(SQLite3 $connection): ?array
    {
        $row = $this->query($connection, 'SELECT min(started_at) AS first, max(started_at) AS last FROM base')[0];

        if ($row['first'] === null) {
            return null;
        }

        return [$row['first'], $row['last']];
    }

    /**
     * Read the records and the value of the measure of each bucket of an equal grid that holds a record, keyed by bucket index; the last bucket takes every record at or past its start.
     *
     * @return array<int, array{samples: int, value: int|float|null}>
     */
    public function buckets(SQLite3 $connection, Measure $by, float $origin, float $width, int $count): array
    {
        $value = match ($by) {
            Measure::OCCURRENCES => 'count(*)',
            Measure::MAX_DURATION => 'max(d)',
            Measure::AVG_DURATION => 'avg(d)',
            Measure::TOTAL_DURATION => 'sum(d)',
            Measure::MAX_MEMORY => 'max(m)',
            default => throw new LogicException("No trend states {$by->value}."),
        };

        $rows = $this->query($connection, "SELECT CASE WHEN :width = 0 THEN 0 ELSE min(:last, CAST((started_at - :origin) / :width AS INTEGER)) END AS bucket, count(*) AS samples, {$value} AS value FROM base GROUP BY bucket", bindings: [
            'origin' => $origin,
            'width' => $width,
            'last' => $count - 1,
        ]);

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row['bucket']] = [
                'samples' => $row['samples'],
                'value' => $row['value'],
            ];
        }

        return $buckets;
    }

    /**
     * Read what a budget judges of every group, keyed like the statistics.
     *
     * The figure of each quantity is the nearest-rank 95th percentile when every quantity that has a value has at least 20 of them, else the maximum.
     *
     * @return array<string, array{
     *     ran: int,
     *     duration: int|float|null,
     *     memory: int|float|null,
     *     measured_on: string,
     *     label: mixed,
     *     route_methods: mixed,
     *     route_path: mixed,
     *     name: mixed,
     * }>
     */
    public function budgets(SQLite3 $connection): array
    {
        $matchers = $this->type === RecordType::REQUEST ? ', route_methods, route_path' : ', name';

        $latest = $this->query($connection, "SELECT group_hash, ran, label{$matchers} FROM (SELECT group_hash, label{$matchers}, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS rn, COUNT(*) OVER (PARTITION BY group_hash) AS n, COUNT(*) FILTER (WHERE NOT skipped) OVER (PARTITION BY group_hash) AS ran FROM base WHERE group_hash IS NOT NULL) WHERE rn = 1");
        $durations = $this->percentiles($connection, 'd', 'NOT skipped');
        $memories = $this->percentiles($connection, 'm', 'NOT skipped');
        $budgets = [];

        foreach ($latest as $row) {
            $hash = $row['group_hash'];
            $counts = array_filter([$durations[$hash]['n'] ?? null, $memories[$hash]['n'] ?? null]);
            $percentile = $counts !== [] && min($counts) >= Percentile::P95->floor() ? 'p95' : 'max';

            $budgets[$hash] = [
                'ran' => $row['ran'],
                'duration' => ($durations[$hash] ?? null)[$percentile] ?? null,
                'memory' => ($memories[$hash] ?? null)[$percentile] ?? null,
                'measured_on' => $percentile,
                'label' => $row['label'],
                'route_methods' => $row['route_methods'] ?? null,
                'route_path' => $row['route_path'] ?? null,
                'name' => $row['name'] ?? null,
            ];
        }

        return $budgets;
    }

    /**
     * Read the unrounded statistics of every group, keyed by group hash.
     *
     * @return array<string, array<string, mixed>>
     */
    public function statistics(SQLite3 $connection): array
    {
        $executions = $this->type->isExecution();
        $failure = Failure::expression($this->type) !== null;

        $aggregates = $this->query($connection, 'SELECT group_hash, count(*) AS occurrences, count(d) AS timed, min(d) AS min, avg(d) AS avg, max(d) AS max, sum(d) AS total, max(started_at) AS last, min(started_at) AS wfirst, count(DISTINCT deploy) AS deploys'
            .($executions ? ', max(m) AS mem_max, sum(q) AS queries' : '')
            .($failure ? ', sum(f) AS failed, count(f) AS failed_of' : '')
            .' FROM base WHERE group_hash IS NOT NULL GROUP BY group_hash');

        $groups = [];

        foreach ($aggregates as $aggregate) {
            $groups[$aggregate['group_hash']] = [
                ...$aggregate,
                'hash' => $aggregate['group_hash'],
                'p50' => null,
                'p95' => null,
                'raw' => null,
                'mem_p95' => null,
                'mem_p50' => null,
                'mem_timed' => 0,
                'first' => null,
                'slowest' => null,
                'label' => '',
                'method' => null,
            ];
        }

        foreach ($this->percentiles($connection, 'd') as $hash => $percentiles) {
            $groups[$hash] = [
                ...$groups[$hash],
                'p50' => $percentiles['p50'],
                'p95' => $percentiles['p95'],
                'raw' => $percentiles['raw'],
            ];
        }

        if ($executions) {
            foreach ($this->percentiles($connection, 'm') as $hash => $percentiles) {
                $groups[$hash] = [
                    ...$groups[$hash],
                    'mem_p95' => $percentiles['p95'],
                    'mem_p50' => $percentiles['p50'],
                    'mem_timed' => $percentiles['n'],
                ];
            }
        }

        foreach ($this->latest($connection) as $row) {
            $groups[$row['group_hash']]['label'] = is_string($row['label']) ? $row['label'] : '';
            $groups[$row['group_hash']]['method'] = $row['method'] ?? null;
        }

        return $groups;
    }

    /**
     * Get the unrounded value of a measure for a group of the statistics, or null when too few of its records have the quantity.
     *
     * @param  array<string, mixed>  $group
     */
    public static function value(array $group, Measure $measure): int|float|null
    {
        return match ($measure) {
            Measure::P95_DURATION => $group['timed'] >= Percentile::P95->floor() ? $group['p95'] : null,
            Measure::P50_DURATION => $group['timed'] >= Percentile::MEDIAN->floor() ? $group['p50'] : null,
            Measure::MAX_DURATION => $group['max'],
            Measure::AVG_DURATION => $group['avg'],
            Measure::TOTAL_DURATION => $group['total'],
            Measure::OCCURRENCES => $group['occurrences'],
            Measure::P95_MEMORY => $group['mem_timed'] >= Percentile::P95->floor() ? $group['mem_p95'] : null,
            Measure::P50_MEMORY => $group['mem_timed'] >= Percentile::MEDIAN->floor() ? $group['mem_p50'] : null,
            Measure::MAX_MEMORY => $group['mem_max'] ?? null,
            Measure::LAST_SEEN => $group['last'],
            Measure::QUERIES => $group['queries'] ?? null,
        };
    }

    /**
     * Read the nearest-rank median and 95th percentile of one quantity of every group, and the values of a group too small to have a 95th percentile.
     *
     * @return array<string, array{n: int, p50: int|float|null, p95: int|float|null, max: int|float|null, raw: list<int|float>|null}>
     */
    protected function percentiles(SQLite3 $connection, string $column, string $where = '1'): array
    {
        $percentiles = [];

        $p50Rank = Percentile::MEDIAN->nearestRank();
        $p95Rank = Percentile::P95->nearestRank();
        $listFloor = Percentile::P95->floor();

        $rows = $this->query($connection, "SELECT group_hash, n, max(CASE WHEN rn = {$p50Rank} THEN v END) AS p50, max(CASE WHEN rn = {$p95Rank} THEN v END) AS p95, max(CASE WHEN rn = n THEN v END) AS max, group_concat(CASE WHEN n < {$listFloor} THEN v END) AS raw FROM (SELECT group_hash, {$column} AS v, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY {$column}, id) AS rn, COUNT(*) OVER (PARTITION BY group_hash) AS n FROM base WHERE group_hash IS NOT NULL AND {$column} IS NOT NULL AND {$where}) GROUP BY group_hash");

        foreach ($rows as $row) {
            /** @var list<int|float>|null $raw */
            $raw = is_string($row['raw']) ? json_decode("[{$row['raw']}]", associative: true, flags: JSON_THROW_ON_ERROR) : null;

            if ($raw !== null) {
                sort($raw);
            }

            $percentiles[$row['group_hash']] = [
                'n' => $row['n'],
                'p50' => $row['p50'],
                'p95' => $row['p95'],
                'max' => $row['max'],
                'raw' => $raw,
            ];
        }

        return $percentiles;
    }

    /**
     * Determine if the type has a duration.
     */
    public function hasDuration(): bool
    {
        return $this->type !== RecordType::EXCEPTION;
    }

    /**
     * Determine if a group of the type is shown with the method of its latest record.
     */
    public function hasMethod(): bool
    {
        return in_array($this->type, [RecordType::REQUEST, RecordType::OUTGOING_REQUEST], true);
    }

    /**
     * Run a query over the records of the type that the window, the deploy and the group leave, as the table `base`.
     *
     * @param  array<string, int|float>  $bindings  bound by name after the population's own filters
     * @return list<array<string, mixed>>
     */
    public function query(SQLite3 $connection, string $sql, array $bindings = []): array
    {
        $deploy = "NULLIF(deploy, '')";
        $duration = $this->hasDuration() ? Stored::duration($this->type) : 'NULL';
        $label = $this->type->labelColumn();
        $columns = [$this->group === null ? 'group_hash' : "COALESCE({$deploy}, char(1)) AS group_hash", 'id', 'started_at', "{$deploy} AS deploy", 'execution_id', "{$duration} AS d", "{$label} AS label"];

        if ($this->hasMethod()) {
            $columns[] = 'method';
        }

        foreach (Stage::of($this->type) as $stage) {
            $columns[] = Stored::number($stage->value)." AS {$stage->value}";
        }

        if ($this->type->isExecution()) {
            array_push($columns, Stored::number('peak_memory_usage').' AS m', Stored::number('queries').' AS q', $this->type === RecordType::SCHEDULED_TASK ? "COALESCE(status = '".Outcome::SKIPPED->value."', 0) AS skipped" : '0 AS skipped');
            array_push($columns, ...($this->type === RecordType::REQUEST ? ['route_methods', 'route_path'] : ['name']));
        }

        $failure = Failure::expression($this->type);

        if ($failure !== null) {
            $columns[] = "{$failure} AS f";
        }

        $filters = [
            'deploy' => $this->deploy,
            'group' => $this->group,
        ];

        $sql = 'WITH base AS (SELECT '.implode(', ', $columns).' FROM '.$this->source().' WHERE '.$this->window->condition().' AND (:deploy IS NULL OR deploy = :deploy) AND (:group IS NULL OR group_hash = :group)) '.$sql;

        return Stored::rows($connection, $sql, [...$filters, ...$this->narrowing(), ...$bindings], $this->window);
    }

    /**
     * Get the SQL source of the records of the type, only those of the groups the population is narrowed to when it is.
     */
    protected function source(): ?string
    {
        if ($this->hashes === null) {
            return $this->type->view();
        }

        // Without planner statistics SQLite prefers the type's index over the group's, so the ids come from the group index.
        return "(SELECT id AS page_id FROM records INDEXED BY records_group WHERE group_hash IN (SELECT value FROM json_each(:hashes))) AS page CROSS JOIN {$this->type->view()} ON id = page_id";
    }

    /**
     * Get the bindings of the groups the population is narrowed to, which the source names.
     *
     * @return array<string, string>
     */
    protected function narrowing(): array
    {
        return $this->hashes === null ? [] : ['hashes' => json_encode($this->hashes, JSON_THROW_ON_ERROR)];
    }

    /**
     * Get the SQL that reads a nearest-rank percentile of one quantity of every group, null below its sample floor, with its records and the records that have a duration.
     */
    protected function percentile(string $column, Percentile $percentile): string
    {
        $rank = $percentile->nearestRank();

        return "SELECT group_hash, count(*) AS occurrences, count(d) AS timed, max(CASE WHEN rn = {$rank} AND n >= {$percentile->floor()} THEN v END) AS value FROM (SELECT group_hash, d, {$column} AS v, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY {$column} IS NULL, {$column}, id) AS rn, count({$column}) OVER (PARTITION BY group_hash) AS n FROM base WHERE group_hash IS NOT NULL) GROUP BY group_hash";
    }

    /**
     * Get the SQL that reads an aggregate of the records of every group, with its records and the records that have a duration.
     */
    protected function aggregated(string $aggregate): string
    {
        return "SELECT group_hash, count(*) AS occurrences, count(d) AS timed, {$aggregate} AS value FROM base WHERE group_hash IS NOT NULL GROUP BY group_hash";
    }
}
