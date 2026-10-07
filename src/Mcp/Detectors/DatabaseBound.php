<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\History;
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
     * The percent a share is of its whole.
     */
    protected const PERCENT = 100;

    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

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
        return new Threshold(name: 'percent', unit: 'percent', default: 60, minimum: 1, maximum: 100, whole: false);
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
    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $percent = $threshold ?? $this->threshold()->default;
        $meta = Markers::read($connection);
        $bindings = [
            'group' => $group ?? '',
            'from' => History::removedThrough($meta, [RecordType::QUERY]),
            'share' => Percentile::MEDIAN->share(),
        ];

        $selected = $window->condition().' AND (:group = \'\' OR group_hash = :group)';
        $described = $this->threshold()->describe($threshold);
        $population = $this->population($connection, $window, $selected, $bindings);

        if ($population['examined'] === 0) {
            $reason = $population['excluded'] > 0 ? Reason::OUTSIDE_COVERAGE : Reason::NO_RECORDS;

            return Judgement::notEvaluated($this->name(), $described, $reason);
        }

        $caveats = $this->caveats($population['incomplete']);
        $groups = $this->groups($connection, $window, $selected, $bindings);
        $judged = array_sum(array_column($groups, 'requests'));
        $saw = $judged === $population['examined'] ? [] : ['without_duration' => $population['examined'] - $judged];

        $bound = $this->bound($groups, $percent);
        if ($bound === []) {
            return Judgement::of($this->name(), $described, examined: $population['examined'], total: 0, findings: [], saw: $saw, caveats: $caveats);
        }

        $shown = array_slice($bound, 0, $limit);
        $shownGroups = array_column($shown, 'group');
        $queries = $this->topQueries($connection, $window, $selected, $bindings, array_column($shownGroups, 'group_hash'));
        $findings = array_map(
            fn (array $entry) => $this->finding($entry['group'], $entry['typical'], $queries[$entry['group']['group_hash']] ?? []),
            $shown,
        );

        return Judgement::of($this->name(), $described, examined: $population['examined'], total: count($bound), findings: $findings, saw: $saw, caveats: $caveats);
    }

    /**
     * Get what the window holds.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return array{examined: int, excluded: int, incomplete: int}
     */
    protected function population(SQLite3 $connection, Window $window, string $selected, array $bindings): array
    {
        $counted = Stored::number('queries');

        $row = Stored::rows($connection, "SELECT count(*) FILTER (WHERE eligible) AS examined, count(*) FILTER (WHERE NOT eligible) AS excluded,
            count(*) FILTER (WHERE eligible AND counted > captured) AS incomplete
            FROM (SELECT counted, (:from IS NULL OR started_at >= :from) AS eligible,
                CASE WHEN :from IS NULL OR started_at >= :from THEN (SELECT count(*) FROM queries WHERE queries.execution_id = requests.execution_id) ELSE 0 END AS captured
                FROM (SELECT started_at, execution_id, {$counted} AS counted FROM requests WHERE {$selected}) AS requests)", $bindings, $window)[0];

        return [
            'examined' => $row['examined'],
            'excluded' => $row['excluded'],
            'incomplete' => $row['incomplete'],
        ];
    }

    /**
     * Get what the requests that have a duration say of each group.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, string $selected, array $bindings): array
    {
        $rank = 'max(1, (n * :share + '.(self::PERCENT - 1).') / '.self::PERCENT.')';
        $durationMicros = Stored::number('duration');

        return Stored::rows($connection, "WITH judged AS (
            SELECT group_hash, id, started_at, execution_id, route_path, NULLIF(user_id, '') AS user_id, duration_micros, query_micros FROM (
                SELECT group_hash, id, started_at, execution_id, route_path, user_id, {$durationMicros} AS duration_micros,
                    (SELECT total(queries.duration) FROM queries WHERE queries.execution_id = requests.execution_id) AS query_micros
                FROM requests WHERE {$selected} AND (:from IS NULL OR started_at >= :from)) WHERE duration_micros > 0
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
            min(started_at) AS first_seen, max(started_at) AS last_seen,
            count(DISTINCT user_id) AS actors, count(*) FILTER (WHERE user_id IS NULL) AS anonymous
        FROM ranked GROUP BY group_hash", $bindings, $window);
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

        $bound = array_values(array_filter($entries, fn (array $entry) => $this->isBound($entry['typical'], $percent)));

        $worst = fn (array $entry) => [$this->share($entry['typical']), Stored::milliseconds($entry['typical']['microseconds'])];

        usort($bound, fn (array $a, array $b) => [...$worst($b), $a['group']['group_hash']] <=> [...$worst($a), $b['group']['group_hash']]);

        return $bound;
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

        return $percent * $typical['duration_micros'] <= $typical['query_micros'] * self::PERCENT;
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
        return round(self::PERCENT * $typical['query_micros'] / $typical['duration_micros'], self::PERCENT_DECIMALS);
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $group
     * @param  array{basis: string, query_micros: int|float, duration_micros: int|float, microseconds: int|float}  $typical
     * @param  list<array<string, mixed>>  $queries
     * @return array<string, mixed>
     */
    protected function finding(array $group, array $typical, array $queries): array
    {
        $share = $this->share($typical);
        $aggregate = round(self::PERCENT * $group['query_micros'] / $group['duration_micros'], self::PERCENT_DECIMALS);
        $route = Stored::blank($group['route_path']);

        return [
            'group' => $group['group_hash'],
            'name' => $route ?? __('firewatch::messages.rank_no_route'),
            'count' => $share,
            'first_seen_at' => $group['first_seen'],
            'last_seen_at' => $group['last_seen'],
            'latest_execution_id' => $group['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $group['actors'],
                'without_actor' => $group['anonymous'],
            ],
            'evidence' => [
                'share_pct' => $share,
                'basis' => $typical['basis'],
                'aggregate_share_pct' => $aggregate,
                'typical_ms' => Stored::milliseconds($typical['microseconds']),
                'requests' => $group['requests'],
                'top_queries' => $queries,
            ],
        ];
    }

    /**
     * Get the queries that took longest in the requests of each group, by the group.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @param  list<string>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    protected function topQueries(SQLite3 $connection, Window $window, string $selected, array $bindings, array $groups): array
    {
        $names = array_map(fn (int $index) => "member{$index}", array_keys($groups));
        $members = implode(', ', array_map(fn (string $name) => ":{$name}", $names));
        $bindings = [...$bindings, ...array_combine($names, $groups)];
        $top = self::TOP_QUERIES;
        $durationMicros = Stored::number('duration');

        $rows = Stored::rows($connection, "SELECT eg, qg, sql, calls, micros FROM (
            SELECT judged.group_hash AS eg, queries.group_hash AS qg, min(queries.sql) AS sql, count(*) AS calls, total(queries.duration) AS micros,
                ROW_NUMBER() OVER (PARTITION BY judged.group_hash ORDER BY total(queries.duration) DESC, queries.group_hash) AS position
            FROM (SELECT group_hash, execution_id FROM requests WHERE {$selected} AND (:from IS NULL OR started_at >= :from) AND {$durationMicros} > 0 AND group_hash IN ({$members})) AS judged
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

    /**
     * Get the caveats of the answer.
     *
     * @return list<string>
     */
    protected function caveats(int $incomplete): array
    {
        return $incomplete > 0 ? [trans_choice('firewatch::messages.detect_caveat_incomplete', $incomplete, ['count' => $incomplete])] : [];
    }
}
