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
     * Get the threshold: the typical share of a request's time spent in queries at which a group is a finding.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'percent', unit: 'percent', default: 60, minimum: 1, maximum: 100);
    }

    /**
     * Get the record types the detector examines: the requests and the queries they ran.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::QUERY, RecordType::REQUEST];
    }

    /**
     * Judge the requests that started in the window: a group is a finding when its typical share of time in queries and its typical duration are high enough.
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

        $population = Stored::rows($connection, 'SELECT count(*) FILTER (WHERE eligible) AS examined, count(*) FILTER (WHERE NOT eligible) AS excluded,
            count(*) FILTER (WHERE eligible AND counted > captured) AS incomplete
            FROM (SELECT counted, (:from IS NULL OR started_at >= :from) AS eligible,
                CASE WHEN :from IS NULL OR started_at >= :from THEN (SELECT count(*) FROM queries WHERE queries.execution_id = requests.execution_id) ELSE 0 END AS captured
                FROM (SELECT started_at, execution_id, '.Stored::number('queries')." AS counted FROM requests WHERE {$selected}) AS requests)", $bindings, $window)[0];

        if ($population['examined'] === 0) {
            $reason = $population['excluded'] > 0 ? Reason::OUTSIDE_COVERAGE : Reason::NO_RECORDS;

            return Judgement::notEvaluated($this->name(), $described, $reason);
        }

        $caveats = $this->caveats($population['incomplete']);
        $groups = $this->groups($connection, $window, $selected, $bindings);
        $judged = array_sum(array_column($groups, 'requests'));
        $saw = $judged === $population['examined'] ? [] : ['without_duration' => $population['examined'] - $judged];
        $findings = $this->findings($groups, $percent);

        if ($findings === []) {
            return Judgement::of($this->name(), $described, examined: $population['examined'], total: 0, findings: [], saw: $saw, caveats: $caveats);
        }

        $shown = array_slice($findings, 0, $limit);
        $queries = $this->topQueries($connection, $window, $selected, $bindings, array_column($shown, 'group'));

        $shown = array_map(function (array $finding) use ($queries) {
            $finding['evidence']['top_queries'] = $queries[$finding['group']] ?? [];

            return $finding;
        }, $shown);

        return Judgement::of($this->name(), $described, examined: $population['examined'], total: count($findings), findings: $shown, saw: $saw, caveats: $caveats);
    }

    /**
     * Get what the requests that have a duration say of each group: the sums of their durations and of the time they spent in queries, the median of their shares and of their durations at the nearest rank, and the latest of them.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, string $selected, array $bindings): array
    {
        $rank = 'max(1, (n * :share + '.(self::PERCENT - 1).') / '.self::PERCENT.')';
        $duration = Stored::number('duration');

        return Stored::rows($connection, "WITH judged AS (
            SELECT group_hash, id, started_at, execution_id, route_path, NULLIF(user_id, '') AS user_id, duration, query_micros FROM (
                SELECT group_hash, id, started_at, execution_id, route_path, user_id, {$duration} AS duration,
                    (SELECT total(queries.duration) FROM queries WHERE queries.execution_id = requests.execution_id) AS query_micros
                FROM requests WHERE {$selected} AND (:from IS NULL OR started_at >= :from)) WHERE duration > 0
        ), ranked AS (
            SELECT *, count(*) OVER (PARTITION BY group_hash) AS n,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY query_micros * 1.0 / duration, id) AS by_share,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY duration, id) AS by_duration,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS by_latest
            FROM judged
        )
        SELECT group_hash, count(*) AS requests, total(query_micros) AS query_micros, total(duration) AS duration,
            max(CASE WHEN by_share = {$rank} THEN query_micros END) AS median_query_micros,
            max(CASE WHEN by_share = {$rank} THEN duration END) AS median_share_duration,
            max(CASE WHEN by_duration = {$rank} THEN duration END) AS median_duration,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN route_path END) AS route_path,
            min(started_at) AS first_seen, max(started_at) AS last_seen,
            count(DISTINCT user_id) AS actors, count(*) FILTER (WHERE user_id IS NULL) AS anonymous
        FROM ranked GROUP BY group_hash", $bindings, $window);
    }

    /**
     * Get the findings of the groups, worst first.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    protected function findings(array $groups, int|float $percent): array
    {
        $findings = [];

        foreach ($groups as $group) {
            $typical = $this->typical($group);

            if ($percent * $typical['duration'] <= $typical['query_micros'] * 100 && $typical['microseconds'] >= self::TYPICAL_FLOOR_MICROSECONDS) {
                $findings[] = $this->finding($group, $typical);
            }
        }

        usort($findings, fn (array $a, array $b) => [$b['count'], $b['evidence']['typical_ms'], $a['group']] <=> [$a['count'], $a['evidence']['typical_ms'], $b['group']]);

        return $findings;
    }

    /**
     * Get the typical share and duration of a group: the medians from three requests, otherwise the aggregate share and the mean duration.
     *
     * @param  array<string, mixed>  $group
     * @return array{basis: string, query_micros: int|float, duration: int|float, microseconds: int|float}
     */
    protected function typical(array $group): array
    {
        if ($group['requests'] < Percentile::MEDIAN->floor()) {
            return [
                'basis' => 'aggregate',
                'query_micros' => $group['query_micros'],
                'duration' => $group['duration'],
                'microseconds' => $group['duration'] / $group['requests'],
            ];
        }

        return [
            'basis' => 'median',
            'query_micros' => $group['median_query_micros'],
            'duration' => $group['median_share_duration'],
            'microseconds' => $group['median_duration'],
        ];
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $group
     * @param  array{basis: string, query_micros: int|float, duration: int|float, microseconds: int|float}  $typical
     * @return array<string, mixed>
     */
    protected function finding(array $group, array $typical): array
    {
        $share = round(100 * $typical['query_micros'] / $typical['duration'], self::PERCENT_DECIMALS);
        $aggregate = round(100 * $group['query_micros'] / $group['duration'], self::PERCENT_DECIMALS);
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

        $rows = Stored::rows($connection, "SELECT eg, qg, sql, calls, micros FROM (
            SELECT judged.group_hash AS eg, queries.group_hash AS qg, min(queries.sql) AS sql, count(*) AS calls, total(queries.duration) AS micros,
                ROW_NUMBER() OVER (PARTITION BY judged.group_hash ORDER BY total(queries.duration) DESC, queries.group_hash) AS position
            FROM (SELECT group_hash, execution_id FROM requests WHERE {$selected} AND (:from IS NULL OR started_at >= :from) AND ".Stored::number('duration')." > 0 AND group_hash IN ({$members})) AS judged
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
     * Get the caveats of the answer: that run counts and shares are lower bounds when requests captured fewer queries than they counted.
     *
     * @return list<string>
     */
    protected function caveats(int $incomplete): array
    {
        return $incomplete > 0 ? [trans_choice('firewatch::messages.detect_caveat_incomplete', $incomplete, ['count' => $incomplete])] : [];
    }
}
