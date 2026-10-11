<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class DatabaseBound implements Detector
{
    /**
     * The fewest microseconds of typical duration a group needs, so that a fast request that is mostly one query is not called bound.
     */
    protected const TYPICAL_FLOOR_MICROSECONDS = 5_000;

    /**
     * The most queries a finding lists.
     */
    protected const TOP_QUERIES = 3;

    /**
     * The basis of a typical share taken over all the requests of a group.
     */
    protected const AGGREGATE = 'aggregate';

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::DATABASE_BOUND;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'percent', unit: 'percent', default: 60, minimum: 1, maximum: Percentile::PERCENT, whole: false);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::QUERY, RecordType::REQUEST];
    }

    /**
     * Judge the requests that started in the window.
     */
    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $examination = Examination::every($connection, $meta, $window, $group, [RecordType::REQUEST]);
        $requests = $examination->executions;
        $described = $this->threshold()->describe($threshold);

        if ($examination->examined === 0) {
            return Judgement::notEvaluated($this->name(), $described, Reason::NO_RECORDS);
        }

        $caveats = $examination->caveats();
        $groups = $this->groups($connection, $window, $requests);
        $judged = array_sum(array_column($groups, 'requests'));
        $saw = $judged === $examination->examined ? [] : ['without_duration' => $examination->examined - $judged];

        $bound = $this->bound($groups, $threshold);
        if ($bound === []) {
            return Judgement::of($this->name(), $described, examined: $examination->examined, total: 0, findings: [], saw: $saw, caveats: $caveats);
        }

        $shown = Judgement::worst($bound, $this->worse(...), $limit);
        $shownGroups = array_column($shown, 'group');
        $queries = $this->topQueries($connection, $window, $requests, array_column($shownGroups, 'group_hash'));
        $findings = array_map(
            fn (array $entry) => $this->finding($entry['group'], $entry['typical'], $queries[$entry['group']['group_hash']] ?? []),
            $shown,
        );

        return Judgement::of($this->name(), $described, examined: $examination->examined, total: count($bound), findings: $findings, saw: $saw, caveats: $caveats);
    }

    /**
     * Get what the requests that have a duration say of each group.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, Fragment $requests): array
    {
        $rank = Percentile::MEDIAN->nearestRank();
        $reach = Fragment::reach();

        return Stored::rows($connection, "{$requests->sql}, judged AS (
            SELECT group_hash, id, started_at, execution_id, label AS route_path, NULLIF(user_id, '') AS user_id, duration AS duration_micros,
                (SELECT total(queries.duration) FROM queries WHERE queries.execution_id = executions.execution_id) AS query_micros
            FROM executions WHERE duration > 0
        ), ranked AS (
            SELECT *, count(*) OVER (PARTITION BY group_hash) AS n,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY query_micros * 1.0 / duration_micros, id) AS by_share,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY duration_micros, id) AS by_duration,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS by_latest
            FROM judged
        )
        SELECT group_hash, count(*) AS requests, total(query_micros) AS query_micros, total(duration_micros) AS duration_micros,
            max(CASE WHEN by_share = {$rank} THEN query_micros END) AS median_query_micros,
            max(CASE WHEN by_share = {$rank} THEN duration_micros END) AS median_share_duration_micros,
            max(CASE WHEN by_duration = {$rank} THEN duration_micros END) AS median_duration_micros,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN route_path END) AS route_path,
            {$reach}
        FROM ranked GROUP BY group_hash", $requests->bindings, $window);
    }

    /**
     * Get the groups that are database-bound.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array{group: array<string, mixed>, typical: array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}}>
     */
    protected function bound(array $groups, int|float $percent): array
    {
        $entries = array_map(fn (array $group) => [
            'group' => $group,
            'typical' => $this->typical($group),
        ], $groups);

        return array_values(array_filter($entries, fn (array $entry) => $this->isBound($entry['typical'], $percent)));
    }

    /**
     * Compare two bound groups, the one with the greater share and then the greater typical duration first.
     *
     * @param  array{group: array<string, mixed>, typical: array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}}  $a
     * @param  array{group: array<string, mixed>, typical: array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}}  $b
     */
    protected function worse(array $a, array $b): int
    {
        $worst = fn (array $entry) => [$this->share($entry['typical']), Stored::milliseconds($entry['typical']['microseconds'])];

        return [...$worst($b), $a['group']['group_hash']] <=> [...$worst($a), $b['group']['group_hash']];
    }

    /**
     * Determine if the typical figures of a group are database-bound.
     *
     * @param  array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}  $typical
     */
    protected function isBound(array $typical, int|float $percent): bool
    {
        if ($typical['microseconds'] < self::TYPICAL_FLOOR_MICROSECONDS) {
            return false;
        }

        return $percent * $typical['duration_micros'] <= $typical['query_micros'] * Percentile::PERCENT;
    }

    /**
     * Get the typical share and duration of a group.
     *
     * @param  array<string, mixed>  $group
     * @return array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}
     */
    protected function typical(array $group): array
    {
        if ($group['requests'] < Percentile::MEDIAN->floor()) {
            return [
                'basis' => self::AGGREGATE,
                'query_micros' => $group['query_micros'],
                'duration_micros' => $group['duration_micros'],
                'microseconds' => $group['duration_micros'] / $group['requests'],
            ];
        }

        return [
            'basis' => Percentile::MEDIAN->value,
            'query_micros' => $group['median_query_micros'],
            'duration_micros' => $group['median_share_duration_micros'],
            'microseconds' => $group['median_duration_micros'],
        ];
    }

    /**
     * Get the share of its time in queries that a group typically spends, in percent.
     *
     * @param  array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}  $typical
     */
    protected function share(array $typical): float
    {
        return Percentile::percent($typical['query_micros'], $typical['duration_micros']) ?? 0.0;
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $group
     * @param  array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}  $typical
     * @param  list<array<string, mixed>>  $queries
     */
    protected function finding(array $group, array $typical, array $queries): Finding
    {
        $share = $this->share($typical);
        $aggregate = Percentile::percent($group['query_micros'], $group['duration_micros']);

        return Finding::from($group, name: RecordType::REQUEST->groupLabel($group['route_path']), count: $share, evidence: [
            'share_pct' => $share,
            'basis' => $typical['basis'],
            'aggregate_share_pct' => $aggregate,
            'typical_ms' => Stored::milliseconds($typical['microseconds']),
            'requests' => $group['requests'],
            'top_queries' => $queries,
        ]);
    }

    /**
     * Get the queries that took longest in the requests of each group, by the group.
     *
     * @param  list<string>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    protected function topQueries(SQLite3 $connection, Window $window, Fragment $requests, array $groups): array
    {
        $names = array_map(fn (int $index) => "member{$index}", array_keys($groups));
        $members = implode(', ', array_map(fn (string $name) => ":{$name}", $names));
        $bindings = [...$requests->bindings, ...array_combine($names, $groups)];
        $top = self::TOP_QUERIES;

        $rows = Stored::rows($connection, "{$requests->sql} SELECT eg, qg, sql, calls, micros FROM (
            SELECT judged.group_hash AS eg, queries.group_hash AS qg, min(queries.sql) AS sql, count(*) AS calls, total(queries.duration) AS micros,
                ROW_NUMBER() OVER (PARTITION BY judged.group_hash ORDER BY total(queries.duration) DESC, queries.group_hash) AS position
            FROM (SELECT group_hash, execution_id FROM executions WHERE duration > 0 AND group_hash IN ({$members})) AS judged
            JOIN queries ON queries.execution_id = judged.execution_id
            GROUP BY judged.group_hash, queries.group_hash) WHERE position <= {$top}", $bindings, $window);

        $queries = [];

        foreach ($rows as $row) {
            $queries[$row['eg']][] = [
                'group' => $row['qg'],
                'sql' => $row['sql'],
                'calls' => $row['calls'],
                'total_ms' => Stored::milliseconds($row['micros']),
            ];
        }

        return $queries;
    }
}
