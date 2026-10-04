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
        $requests = Stored::rows($connection, 'SELECT group_hash, id, execution_id, started_at, NULLIF(user_id, \'\') AS user_id, '.Stored::number('duration').' AS duration, route_path,
            (SELECT total(queries.duration) FROM queries WHERE queries.execution_id = requests.execution_id) AS query_micros
            FROM requests WHERE '.$selected.' AND (:from IS NULL OR started_at >= :from)', $bindings, $window);

        $judged = array_values(array_filter($requests, fn (array $request) => is_numeric($request['duration']) && $request['duration'] > 0));
        $saw = count($requests) === count($judged) ? [] : ['without_duration' => count($requests) - count($judged)];
        $findings = $this->findings($judged, $percent);

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
     * Get the findings of the groups of the requests that have a duration, worst first.
     *
     * @param  list<array<string, mixed>>  $judged
     * @return list<array<string, mixed>>
     */
    protected function findings(array $judged, int|float $percent): array
    {
        $groups = [];

        foreach ($judged as $request) {
            $groups[$request['group_hash']][] = $request;
        }

        $findings = [];

        foreach ($groups as $requests) {
            $typical = $this->typical($requests);

            if ($typical['share']['query_micros'] * 100 >= $percent * $typical['share']['duration'] && $typical['microseconds'] >= self::TYPICAL_FLOOR_MICROSECONDS) {
                $findings[] = $this->finding($requests[0]['group_hash'], $requests, $typical);
            }
        }

        usort($findings, fn (array $a, array $b) => [$b['count'], $b['evidence']['typical_ms'], $a['group']] <=> [$a['count'], $a['evidence']['typical_ms'], $b['group']]);

        return $findings;
    }

    /**
     * Get the typical share and duration of the requests of a group: the medians from three requests, otherwise the aggregate share and the mean duration.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return array{basis: string, share: array{query_micros: float, duration: float}, microseconds: float}
     */
    protected function typical(array $requests): array
    {
        if (count($requests) < Percentile::MEDIAN->floor()) {
            return [
                'basis' => 'aggregate',
                'share' => [
                    'query_micros' => array_sum(array_column($requests, 'query_micros')),
                    'duration' => array_sum(array_column($requests, 'duration')),
                ],
                'microseconds' => array_sum(array_column($requests, 'duration')) / count($requests),
            ];
        }

        usort($requests, fn (array $a, array $b) => $a['query_micros'] / $a['duration'] <=> $b['query_micros'] / $b['duration']);
        $durations = array_column($requests, 'duration');
        sort($durations);

        return [
            'basis' => 'median',
            'share' => [
                'query_micros' => $requests[$this->rank(count($requests))]['query_micros'],
                'duration' => $requests[$this->rank(count($requests))]['duration'],
            ],
            'microseconds' => $durations[$this->rank(count($durations))],
        ];
    }

    /**
     * Get the position of the nearest-rank median of a number of values, counted from zero.
     */
    protected function rank(int $count): int
    {
        return max(1, (int) ceil($count * Percentile::MEDIAN->share() / 100)) - 1;
    }

    /**
     * Get the finding of a group of requests.
     *
     * @param  non-empty-list<array<string, mixed>>  $requests
     * @param  array{basis: string, share: array{query_micros: float, duration: float}, microseconds: float}  $typical
     * @return array<string, mixed>
     */
    protected function finding(string $group, array $requests, array $typical): array
    {
        usort($requests, fn (array $a, array $b) => [$b['started_at'], $b['id']] <=> [$a['started_at'], $a['id']]);

        $share = round(100 * $typical['share']['query_micros'] / $typical['share']['duration'], self::PERCENT_DECIMALS);
        $aggregate = round(100 * array_sum(array_column($requests, 'query_micros')) / array_sum(array_column($requests, 'duration')), self::PERCENT_DECIMALS);
        $route = Stored::blank($requests[0]['route_path']);
        $users = array_filter(array_column($requests, 'user_id'), fn (mixed $user) => $user !== null);

        return [
            'group' => $group,
            'name' => $route ?? __('firewatch::messages.rank_no_route'),
            'count' => $share,
            'first_seen_at' => $requests[count($requests) - 1]['started_at'],
            'last_seen_at' => $requests[0]['started_at'],
            'latest_execution_id' => $requests[0]['execution_id'],
            'reaches' => [
                'signed_in_actors' => count(array_unique($users)),
                'without_actor' => count($requests) - count($users),
            ],
            'evidence' => [
                'share_pct' => $share,
                'basis' => $typical['basis'],
                'aggregate_share_pct' => $aggregate,
                'typical_ms' => Stored::milliseconds($typical['microseconds']),
                'requests' => count($requests),
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
