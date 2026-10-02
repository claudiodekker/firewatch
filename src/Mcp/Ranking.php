<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
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
     * The bytes in a megabyte of peak memory.
     */
    protected const MEGABYTE = 1048576;

    /**
     * The four execution types, the only ones that carry memory and a query counter.
     *
     * @var list<RecordType>
     */
    protected const EXECUTIONS = [RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK];

    /**
     * Create a new ranking instance.
     */
    public function __construct(
        protected RecordType $type,
        protected Measure $by,
        protected Window $window,
        protected ?string $deploy,
    ) {
        //
    }

    /**
     * Get what the failure_pct of a type counts as failed, or null for a type that has no such rule.
     */
    public static function failureDefinition(RecordType $type): ?string
    {
        return match ($type) {
            RecordType::REQUEST, RecordType::OUTGOING_REQUEST => 'status >= 400',
            RecordType::COMMAND => 'exit_code <> 0',
            RecordType::JOB_ATTEMPT => 'status is failed or released',
            RecordType::SCHEDULED_TASK => 'status is failed',
            default => null,
        };
    }

    /**
     * Read the groups of the type in the window, worst first by the measure.
     *
     * When no group has enough records for the percentile, the order falls back to the maximum of the same quantity, and the answer says which measure ordered the rows.
     *
     * @return array{rows: list<array<string, mixed>>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}
     */
    public function read(SQLite3 $connection): array
    {
        [$records, $withoutGroup] = array_values($this->query($connection, 'SELECT count(*) AS records, count(*) FILTER (WHERE group_hash IS NULL) AS without FROM base')[0]);

        $groups = $this->groups($connection);
        $orderedBy = $this->by;

        if (($fallback = $this->by->fallback()) !== null && $groups !== [] && array_filter($groups, fn (array $group) => $this->value($group, $this->by) !== null) === []) {
            $orderedBy = $fallback;
        }

        usort($groups, fn (array $a, array $b) => $this->compare($a, $b, $orderedBy));

        return [
            'rows' => array_map($this->row(...), $groups),
            'records' => $records,
            'withoutGroup' => $withoutGroup,
            'untimed' => array_sum(array_map(fn (array $group) => $group['occurrences'] - $group['timed'], $this->hasDuration() ? $groups : [])),
            'orderedBy' => $orderedBy,
        ];
    }

    /**
     * Read what the records of every group of the window add up to.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection): array
    {
        $executions = $this->isExecution();
        $failure = $this->failure() !== null;

        $aggregates = $this->query($connection, 'SELECT group_hash, count(*) AS occurrences, count(d) AS timed, min(d) AS min, avg(d) AS avg, max(d) AS max, sum(d) AS total, max(started_at) AS last, count(DISTINCT deploy) AS deploys'
            .($executions ? ', max(m) AS mem_max, sum(q) AS queries' : '')
            .($failure ? ', sum(f) AS failed, count(f) AS failed_of' : '')
            .' FROM base WHERE group_hash IS NOT NULL GROUP BY group_hash');

        $groups = [];

        foreach ($aggregates as $aggregate) {
            $groups[$aggregate['group_hash']] = [...$aggregate, 'hash' => $aggregate['group_hash'], 'p50' => null, 'p95' => null, 'raw' => null, 'mem_p95' => null, 'mem_timed' => 0, 'first' => null, 'slowest' => null, 'label' => '', 'method' => null];
        }

        foreach ($this->percentiles($connection, 'd') as $hash => $percentiles) {
            $groups[$hash] = [...$groups[$hash], 'p50' => $percentiles['p50'], 'p95' => $percentiles['p95'], 'raw' => $percentiles['raw']];
        }

        if ($executions) {
            foreach ($this->percentiles($connection, 'm') as $hash => $percentiles) {
                $groups[$hash] = [...$groups[$hash], 'mem_p95' => $percentiles['p95'], 'mem_timed' => $percentiles['n']];
            }
        }

        foreach ($this->query($connection, 'SELECT group_hash, min(started_at) AS first FROM '.$this->type->view().' WHERE group_hash IS NOT NULL GROUP BY group_hash', filtered: false) as $row) {
            if (isset($groups[$row['group_hash']])) {
                $groups[$row['group_hash']]['first'] = $row['first'];
            }
        }

        if ($this->hasDuration()) {
            foreach ($this->query($connection, 'SELECT group_hash, execution_id FROM (SELECT group_hash, execution_id, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY d DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL AND d IS NOT NULL) WHERE rn = 1') as $row) {
                $groups[$row['group_hash']]['slowest'] = $row['execution_id'];
            }
        }

        $method = $this->hasMethod() ? ', method' : '';

        foreach ($this->query($connection, "SELECT group_hash, label{$method} FROM (SELECT group_hash, label{$method}, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS rn FROM base WHERE group_hash IS NOT NULL) WHERE rn = 1") as $row) {
            $groups[$row['group_hash']]['label'] = is_string($row['label']) ? $row['label'] : '';
            $groups[$row['group_hash']]['method'] = $row['method'] ?? null;
        }

        return array_values($groups);
    }

    /**
     * Read the nearest-rank median and 95th percentile of one quantity of every group, and the values of a group too small to have a median.
     *
     * @return array<string, array{n: int, p50: int|float|null, p95: int|float|null, raw: list<int|float>|null}>
     */
    protected function percentiles(SQLite3 $connection, string $column): array
    {
        $percentiles = [];

        $rows = $this->query($connection, 'SELECT group_hash, n, max(CASE WHEN rn = max(1, (n * 50 + 99) / 100) THEN v END) AS p50, max(CASE WHEN rn = max(1, (n * 95 + 99) / 100) THEN v END) AS p95, group_concat(CASE WHEN n < '.self::P50_FLOOR." THEN v END) AS raw FROM (SELECT group_hash, {$column} AS v, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY {$column}, id) AS rn, COUNT(*) OVER (PARTITION BY group_hash) AS n FROM base WHERE group_hash IS NOT NULL AND {$column} IS NOT NULL) GROUP BY group_hash");

        foreach ($rows as $row) {
            /** @var list<int|float>|null $raw */
            $raw = is_string($row['raw']) ? json_decode("[{$row['raw']}]", associative: true, flags: JSON_THROW_ON_ERROR) : null;

            if ($raw !== null) {
                sort($raw);
            }

            $percentiles[$row['group_hash']] = ['n' => $row['n'], 'p50' => $row['p50'], 'p95' => $row['p95'], 'raw' => $raw];
        }

        return $percentiles;
    }

    /**
     * Get the value a group is ranked by, or null when the group has none: below the floor of a percentile, or without the field.
     *
     * @param  array<string, mixed>  $group
     */
    protected function value(array $group, Measure $measure): int|float|null
    {
        return match ($measure) {
            Measure::P95_DURATION => $group['timed'] >= self::P95_FLOOR ? $group['p95'] : null,
            Measure::P50_DURATION => $group['timed'] >= self::P50_FLOOR ? $group['p50'] : null,
            Measure::MAX_DURATION => $group['max'],
            Measure::TOTAL_DURATION => $group['total'],
            Measure::OCCURRENCES => $group['occurrences'],
            Measure::P95_MEMORY => $group['mem_timed'] >= self::P95_FLOOR ? $group['mem_p95'] : null,
            Measure::MAX_MEMORY => $group['mem_max'] ?? null,
            Measure::LAST_SEEN => $group['last'],
            Measure::QUERIES => $group['queries'] ?? null,
        };
    }

    /**
     * Order two groups worst first; a group without the value comes last, and ties go to the group with more records, then to the lower hash.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function compare(array $a, array $b, Measure $measure): int
    {
        $left = $this->value($a, $measure);
        $right = $this->value($b, $measure);

        return match (true) {
            $left === null && $right !== null => 1,
            $left !== null && $right === null => -1,
            default => ($right <=> $left) ?: ($b['occurrences'] <=> $a['occurrences']) ?: strcmp($a['hash'], $b['hash']),
        };
    }

    /**
     * Get the row of the answer for a group.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    protected function row(array $group): array
    {
        $row = ['group' => $group['hash'], 'label' => $group['label'] === '' && $this->type === RecordType::REQUEST ? __('firewatch::messages.rank_no_route') : $group['label']];

        if ($this->hasMethod()) {
            $row['method'] = $group['method'];
        }

        $row['occurrences'] = $group['occurrences'];

        $withheld = [];

        if ($this->hasDuration()) {
            $ms = fn (int|float|null $value) => $value === null ? null : round($value / 1000, 2);
            $p50 = $this->floored($group, 'p50_ms', $group['timed'], self::P50_FLOOR, $withheld);
            $p95 = $this->floored($group, 'p95_ms', $group['timed'], self::P95_FLOOR, $withheld);

            $row += ['min_ms' => $ms($group['min']), 'p50_ms' => $ms($p50), 'avg_ms' => $ms($group['avg']), 'p95_ms' => $ms($p95), 'max_ms' => $ms($group['max']), 'total_ms' => $ms($group['total'])];
        }

        if ($this->isExecution()) {
            $mb = fn (int|float|null $value) => $value === null ? null : round($value / self::MEGABYTE, 1);
            $memory = $this->floored($group, 'p95_memory_mb', $group['mem_timed'], self::P95_FLOOR, $withheld, 'mem_p95');

            $row += ['p95_memory_mb' => $mb($memory), 'max_memory_mb' => $mb($group['mem_max']), 'queries' => $group['queries'] ?? null];
        }

        $failed = $group['failed_of'] ?? 0;

        $row += [
            'failure_pct' => $failed > 0 ? round(100 * $group['failed'] / $failed, 1) : null,
            'first_seen_at' => $group['first'],
            'last_seen_at' => $group['last'],
            'deploys' => $group['deploys'],
        ];

        if ($this->hasDuration()) {
            $row += ['slowest_execution_id' => $group['slowest'], 'withheld' => $withheld === [] ? null : $withheld, 'values_ms' => $group['raw'] === null ? null : array_map(fn (int|float $value) => round($value / 1000, 2), $group['raw'])];
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
            $withheld[$name] = ['reason' => 'sample_too_small', 'have' => $have, 'needed' => $needed];
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
     * Determine if the type is one of the four executions.
     */
    protected function isExecution(): bool
    {
        return in_array($this->type, self::EXECUTIONS, true);
    }

    /**
     * Get the field a group is labelled by.
     */
    protected function label(): string
    {
        return match ($this->type) {
            RecordType::REQUEST => 'route_path',
            RecordType::QUERY => 'sql',
            RecordType::OUTGOING_REQUEST => 'host',
            RecordType::CACHE_EVENT => 'key',
            RecordType::EXCEPTION, RecordType::MAIL, RecordType::NOTIFICATION => 'class',
            default => 'name',
        };
    }

    /**
     * Get the expression that is 1 for a failed record, 0 for one that did not fail and null for one without the field it is judged by, or null for a type with no such rule.
     */
    protected function failure(): ?string
    {
        return match ($this->type) {
            RecordType::REQUEST, RecordType::OUTGOING_REQUEST => 'CASE WHEN status_code IS NULL THEN NULL WHEN status_code >= 400 THEN 1 ELSE 0 END',
            RecordType::COMMAND => 'CASE WHEN exit_code IS NULL THEN NULL WHEN exit_code <> 0 THEN 1 ELSE 0 END',
            RecordType::JOB_ATTEMPT => "CASE WHEN status IS NULL THEN NULL WHEN status IN ('failed', 'released') THEN 1 ELSE 0 END",
            RecordType::SCHEDULED_TASK => "CASE WHEN status IS NULL THEN NULL WHEN status = 'failed' THEN 1 ELSE 0 END",
            default => null,
        };
    }

    /**
     * Run a query over the records of the type that the window and the deploy leave, as the table `base`.
     *
     * @return list<array<string, mixed>>
     */
    protected function query(SQLite3 $connection, string $sql, bool $filtered = true): array
    {
        if ($filtered) {
            // A skipped scheduled task has no duration of its own: it never ran.
            $duration = $this->type === RecordType::SCHEDULED_TASK ? "CASE WHEN status = 'skipped' THEN NULL ELSE duration END" : ($this->hasDuration() ? 'duration' : 'NULL');
            $columns = ['group_hash', 'id', 'started_at', 'deploy', 'execution_id', "{$duration} AS d", "{$this->label()} AS label"];

            if ($this->hasMethod()) {
                $columns[] = 'method';
            }

            if ($this->isExecution()) {
                array_push($columns, 'peak_memory_usage AS m', 'queries AS q');
            }

            if ($this->failure() !== null) {
                $columns[] = "{$this->failure()} AS f";
            }

            $sql = 'WITH base AS (SELECT '.implode(', ', $columns).' FROM '.$this->type->view().' WHERE '.$this->window->condition().' AND (:deploy IS NULL OR deploy = :deploy)) '.$sql;
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        if ($filtered) {
            $this->window->bind($statement);
            $statement->bindValue(':deploy', $this->deploy, $this->deploy === null ? SQLITE3_NULL : SQLITE3_TEXT);
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
