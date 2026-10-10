<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;
use LogicException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Ranking
{
    /**
     * The records a group needs before its median is shown.
     */
    public const P50_FLOOR = 3;

    /**
     * The records a group needs before its 95th percentile is shown.
     */
    public const P95_FLOOR = 20;

    /**
     * The nearest rank of the median, as a percentile.
     */
    protected const P50_RANK = 50;

    /**
     * The nearest rank of the 95th percentile, as a percentile.
     */
    protected const P95_RANK = 95;

    /**
     * The whole of a percentile scale.
     */
    public const PERCENT = 100;

    /**
     * The decimals a failure percentage is rounded to.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * The decimals a duration in milliseconds is rounded to.
     */
    public const MILLISECOND_DECIMALS = 2;

    /**
     * The key a record without a deploy has among the deploys of a breakdown, which no deploy string is.
     */
    protected const NO_DEPLOY = "\x01";

    /**
     * The bytes in a megabyte of peak memory.
     */
    public const MEGABYTE = 1048576;

    /**
     * Create a new ranking instance.
     */
    public function __construct(
        protected RecordType $type,
        protected Measure $by,
        protected Window $window,
        protected ?string $deploy,
        protected ?string $matching = null,
        protected ?string $group = null,
        protected ?Configuration $configuration = null,
    ) {
        //
    }

    /**
     * Read the groups of the type in the window, worst first by the measure.
     *
     * @return array{rows: list<array<string, mixed>>, keys: list<array{value: int|float|null, occurrences: int, hash: string}>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}
     */
    public function read(SQLite3 $connection): array
    {
        [$records, $withoutGroup] = array_values($this->query($connection, 'SELECT count(*) AS records, count(*) FILTER (WHERE group_hash IS NULL) AS without FROM base')[0]);

        $groups = $this->groups($connection);

        if ($this->matching !== null) {
            $groups = array_values(array_filter($groups, fn (array $group) => mb_stripos($this->label($group), $this->matching) !== false));
        }

        $orderedBy = $this->by;

        if (($fallback = $this->by->fallback()) !== null && $groups !== [] && array_filter($groups, fn (array $group) => self::value($group, $this->by) !== null) === []) {
            $orderedBy = $fallback;
        }

        usort($groups, fn (array $a, array $b) => self::compare($this->key($a, $orderedBy), $this->key($b, $orderedBy)));

        $timedGroups = $this->hasDuration() ? $groups : [];
        $untimedPerGroup = array_map(fn (array $group) => $group['occurrences'] - $group['timed'], $timedGroups);
        $untimed = array_sum($untimedPerGroup);

        return [
            'rows' => array_map($this->row(...), $groups),
            'keys' => array_map(fn (array $group) => $this->key($group, $orderedBy), $groups),
            'records' => $records,
            'withoutGroup' => $withoutGroup,
            'untimed' => $untimed,
            'orderedBy' => $orderedBy,
        ];
    }

    /**
     * Read the deploys one group was recorded under in the window, in the order they were first seen, and only the most recent ones when there are more than the limit.
     *
     * @return array{rows: list<array<string, mixed>>, matched: int, records: int, label: string}
     */
    public function breakdown(SQLite3 $connection, int $limit): array
    {
        [$records] = array_values($this->query($connection, 'SELECT count(*) AS records FROM base')[0]);

        $deploys = $this->groups($connection);
        $label = '';

        if ($deploys !== []) {
            usort($deploys, fn (array $a, array $b) => $b['last'] <=> $a['last']);
            $label = $this->label($deploys[0]);
        }

        $shown = array_slice($deploys, 0, $limit);

        usort($shown, fn (array $a, array $b) => ($a['wfirst'] <=> $b['wfirst']) ?: strcmp($a['hash'], $b['hash']));

        return [
            'rows' => array_map($this->deployRow(...), $shown),
            'matched' => count($deploys),
            'records' => $records,
            'label' => $label,
        ];
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
    public function buckets(SQLite3 $connection, float $origin, float $width, int $count): array
    {
        $value = match ($this->by) {
            Measure::OCCURRENCES => 'count(*)',
            Measure::MAX_DURATION => 'max(d)',
            Measure::AVG_DURATION => 'avg(d)',
            Measure::TOTAL_DURATION => 'sum(d)',
            Measure::MAX_MEMORY => 'max(m)',
            default => throw new LogicException("No trend states {$this->by->value}."),
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
     * Read the types that hold a group in the store, in the order of the types with groups.
     *
     * @return list<RecordType>
     */
    public static function holders(SQLite3 $connection, string $group): array
    {
        $rows = Stored::rows($connection, 'SELECT DISTINCT type FROM records WHERE group_hash = :group', ['group' => $group]);
        $held = array_map(fn (array $row) => is_string($row['type']) ? RecordType::tryFrom($row['type']) : null, $rows);

        return array_values(array_filter(Measure::types(), fn (RecordType $type) => in_array($type, $held, true)));
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
     * Read what a budget judges of every group of the type in the window, keyed like the statistics.
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
            $percentile = $counts !== [] && min($counts) >= self::P95_FLOOR ? 'p95' : 'max';

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
     * Read what the records of every group of the window add up to, with when each was first seen in the store and its slowest execution.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection): array
    {
        $groups = $this->statistics($connection);

        $firsts = $this->group !== null ? [] : $this->query($connection, 'SELECT group_hash, min(started_at) AS first FROM '.$this->type->view().' WHERE group_hash IS NOT NULL GROUP BY group_hash', filtered: false);

        foreach ($firsts as $row) {
            if (isset($groups[$row['group_hash']])) {
                $groups[$row['group_hash']]['first'] = $row['first'];
            }
        }

        if ($this->hasDuration() && $this->group === null) {
            $slowest = $this->query($connection, 'SELECT group_hash, execution_id FROM (SELECT group_hash, execution_id, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY d DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL AND d IS NOT NULL) WHERE rn = 1');

            foreach ($slowest as $row) {
                $groups[$row['group_hash']]['slowest'] = $row['execution_id'];
            }
        }

        if ($this->configuration !== null && self::isExecution($this->type)) {
            $type = ExecutionType::from($this->type->value);

            foreach ($this->budgets($connection) as $hash => $budget) {
                $groups[$hash]['budget'] = BudgetVerdict::ofGroup($this->configuration, $type, $budget)->cell();
            }
        }

        return array_values($groups);
    }

    /**
     * Read the unrounded statistics of every group of the type in the window, keyed by group hash.
     *
     * @return array<string, array<string, mixed>>
     */
    public function statistics(SQLite3 $connection): array
    {
        $executions = self::isExecution($this->type);
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

        $method = $this->hasMethod() ? ', method' : '';

        $latest = $this->query($connection, "SELECT group_hash, label{$method} FROM (SELECT group_hash, label{$method}, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL) WHERE rn = 1");

        foreach ($latest as $row) {
            $groups[$row['group_hash']]['label'] = is_string($row['label']) ? $row['label'] : '';
            $groups[$row['group_hash']]['method'] = $row['method'] ?? null;
        }

        return $groups;
    }

    /**
     * Read the nearest-rank median and 95th percentile of one quantity of every group, and the values of a group too small to have a median.
     *
     * @return array<string, array{n: int, p50: int|float|null, p95: int|float|null, max: int|float|null, raw: list<int|float>|null}>
     */
    protected function percentiles(SQLite3 $connection, string $column, string $where = '1'): array
    {
        $percentiles = [];

        $p50Rank = self::nearestRank(self::P50_RANK);
        $p95Rank = self::nearestRank(self::P95_RANK);
        $p50Floor = self::P50_FLOOR;

        $rows = $this->query($connection, "SELECT group_hash, n, max(CASE WHEN rn = {$p50Rank} THEN v END) AS p50, max(CASE WHEN rn = {$p95Rank} THEN v END) AS p95, max(CASE WHEN rn = n THEN v END) AS max, group_concat(CASE WHEN n < {$p50Floor} THEN v END) AS raw FROM (SELECT group_hash, {$column} AS v, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY {$column}, id) AS rn, COUNT(*) OVER (PARTITION BY group_hash) AS n FROM base WHERE group_hash IS NOT NULL AND {$column} IS NOT NULL AND {$where}) GROUP BY group_hash");

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
     * Get the SQL expression of the row a nearest-rank percentile of `n` values takes.
     */
    public static function nearestRank(int $percentile): string
    {
        return 'max(1, (n * '.$percentile.' + '.(self::PERCENT - 1).') / '.self::PERCENT.')';
    }

    /**
     * Get microseconds as milliseconds rounded for an answer, or null for none.
     */
    protected static function milliseconds(int|float|null $microseconds): ?float
    {
        return $microseconds === null ? null : round($microseconds / Microseconds::PER_MILLISECOND, self::MILLISECOND_DECIMALS);
    }

    /**
     * Get the unrounded value of a measure for a group, or null when too few of its records have the quantity.
     *
     * @param  array<string, mixed>  $group
     */
    public static function value(array $group, Measure $measure): int|float|null
    {
        return match ($measure) {
            Measure::P95_DURATION => $group['timed'] >= self::P95_FLOOR ? $group['p95'] : null,
            Measure::P50_DURATION => $group['timed'] >= self::P50_FLOOR ? $group['p50'] : null,
            Measure::MAX_DURATION => $group['max'],
            Measure::AVG_DURATION => $group['avg'],
            Measure::TOTAL_DURATION => $group['total'],
            Measure::OCCURRENCES => $group['occurrences'],
            Measure::P95_MEMORY => $group['mem_timed'] >= self::P95_FLOOR ? $group['mem_p95'] : null,
            Measure::P50_MEMORY => $group['mem_timed'] >= self::P50_FLOOR ? $group['mem_p50'] : null,
            Measure::MAX_MEMORY => $group['mem_max'] ?? null,
            Measure::LAST_SEEN => $group['last'],
            Measure::QUERIES => $group['queries'] ?? null,
        };
    }

    /**
     * Get the sort key of a group.
     *
     * @param  array<string, mixed>  $group
     * @return array{value: int|float|null, occurrences: int, hash: string}
     */
    protected function key(array $group, Measure $measure): array
    {
        return [
            'value' => self::value($group, $measure),
            'occurrences' => $group['occurrences'],
            'hash' => $group['hash'],
        ];
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
        return self::shownLabel($this->type, $group['label']);
    }

    /**
     * Get a stored label as an answer shows it: a request that no route matched has an empty one or none.
     */
    public static function shownLabel(RecordType $type, mixed $label): string
    {
        $label = is_string($label) ? $label : '';

        return $label === '' && $type === RecordType::REQUEST ? __('firewatch::messages.rank_no_route') : $label;
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

        if ($this->hasMethod()) {
            $row['method'] = $group['method'];
        }

        $row['occurrences'] = $group['occurrences'];

        $withheld = [];

        if ($this->hasDuration()) {
            $p50 = $this->floored($group, 'p50_ms', $group['timed'], self::P50_FLOOR, $withheld);
            $p95 = $this->floored($group, 'p95_ms', $group['timed'], self::P95_FLOOR, $withheld);

            $row += [
                'min_ms' => self::milliseconds($group['min']),
                'p50_ms' => self::milliseconds($p50),
                'avg_ms' => self::milliseconds($group['avg']),
                'p95_ms' => self::milliseconds($p95),
                'max_ms' => self::milliseconds($group['max']),
                'total_ms' => self::milliseconds($group['total']),
            ];
        }

        if (self::isExecution($this->type)) {
            $mb = fn (int|float|null $value) => $value === null ? null : round($value / self::MEGABYTE, 1);
            $memory = $this->floored($group, 'p95_memory_mb', $group['mem_timed'], self::P95_FLOOR, $withheld, 'mem_p95');

            $row += [
                'p95_memory_mb' => $mb($memory),
                'max_memory_mb' => $mb($group['mem_max']),
                'queries' => $group['queries'] ?? null,
            ];
        }

        $failed = $group['failed_of'] ?? 0;

        $row += [
            'failure_pct' => $failed > 0 ? round(self::PERCENT * $group['failed'] / $failed, self::PERCENT_DECIMALS) : null,
            'first_seen_at' => $group['first'],
            'last_seen_at' => $group['last'],
            'deploys' => $group['deploys'],
        ];

        if ($this->hasDuration()) {
            $row += [
                'slowest_execution_id' => $group['slowest'],
                'withheld' => $withheld === [] ? null : $withheld,
                'values_ms' => $group['raw'] === null ? null : array_map(self::milliseconds(...), $group['raw']),
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

        if ($this->hasDuration()) {
            $p50 = $this->floored($deploy, 'p50_ms', $deploy['timed'], self::P50_FLOOR, $withheld);
            $p95 = $this->floored($deploy, 'p95_ms', $deploy['timed'], self::P95_FLOOR, $withheld);

            $row += [
                'p50_ms' => self::milliseconds($p50),
                'p95_ms' => self::milliseconds($p95),
                'max_ms' => self::milliseconds($deploy['max']),
            ];
        }

        $row += [
            'first_at' => $deploy['wfirst'],
            'last_at' => $deploy['last'],
        ];

        if ($this->hasDuration()) {
            $row += [
                'withheld' => $withheld === [] ? null : $withheld,
                'values_ms' => $deploy['raw'] === null ? null : array_map(self::milliseconds(...), $deploy['raw']),
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

    /**
     * Determine if the type has a duration.
     */
    protected function hasDuration(): bool
    {
        return $this->type !== RecordType::EXCEPTION;
    }

    /**
     * Determine if a group of the type is shown with the method of its latest record.
     */
    protected function hasMethod(): bool
    {
        return in_array($this->type, [RecordType::REQUEST, RecordType::OUTGOING_REQUEST], true);
    }

    /**
     * Determine if a type is one of the four executions.
     */
    public static function isExecution(RecordType $type): bool
    {
        return in_array($type, ExecutionType::records(), true);
    }

    /**
     * Get the field a group of the type is labelled by.
     */
    public static function labelField(RecordType $type): string
    {
        return match ($type) {
            RecordType::REQUEST => 'route_path',
            RecordType::QUERY => 'sql',
            RecordType::OUTGOING_REQUEST => 'host',
            RecordType::CACHE_EVENT => 'key',
            RecordType::EXCEPTION, RecordType::MAIL, RecordType::NOTIFICATION => 'class',
            default => 'name',
        };
    }

    /**
     * Run a query over the records of the type that the window and the deploy leave, as the table `base`.
     *
     * @param  array<string, int|float>  $bindings  bound by name after the window, the deploy and the group
     * @return list<array<string, mixed>>
     */
    protected function query(SQLite3 $connection, string $sql, bool $filtered = true, array $bindings = []): array
    {
        if ($filtered) {
            $deploy = "NULLIF(deploy, '')";
            $duration = $this->hasDuration() ? Stored::duration($this->type) : 'NULL';
            $label = self::labelField($this->type);
            $columns = [$this->group === null ? 'group_hash' : "COALESCE({$deploy}, char(1)) AS group_hash", 'id', 'started_at', "{$deploy} AS deploy", 'execution_id', "{$duration} AS d", "{$label} AS label"];

            if ($this->hasMethod()) {
                $columns[] = 'method';
            }

            if (self::isExecution($this->type)) {
                array_push($columns, Stored::number('peak_memory_usage').' AS m', Stored::number('queries').' AS q', $this->type === RecordType::SCHEDULED_TASK ? "COALESCE(status = '".Outcome::SKIPPED->value."', 0) AS skipped" : '0 AS skipped');
                array_push($columns, ...($this->type === RecordType::REQUEST ? ['route_methods', 'route_path'] : ['name']));
            }

            $failure = Failure::expression($this->type);

            if ($failure !== null) {
                $columns[] = "{$failure} AS f";
            }

            $sql = 'WITH base AS (SELECT '.implode(', ', $columns).' FROM '.$this->type->view().' WHERE '.$this->window->condition().' AND (:deploy IS NULL OR deploy = :deploy) AND (:group IS NULL OR group_hash = :group)) '.$sql;
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        if ($filtered) {
            $this->window->bind($statement);
            $statement->bindValue(':deploy', $this->deploy, $this->deploy === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $statement->bindValue(':group', $this->group, $this->group === null ? SQLITE3_NULL : SQLITE3_TEXT);
        }

        foreach ($bindings as $name => $value) {
            $statement->bindValue(":{$name}", $value);
        }

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $rows = [];

        while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }
}
