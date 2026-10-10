<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Detectors\Concerns\JudgesAtItsDefault;
use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class FailingRoutes implements Thresholded
{
    use JudgesAtItsDefault;

    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::FAILING_ROUTES;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'status', unit: 'status', default: 400, minimum: 100, maximum: 599);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::REQUEST];
    }

    /**
     * Judge the requests of the window.
     */
    public function judgeAt(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $selected = Fragment::selecting($window, $group);
        $bindings = [
            ...$selected->bindings,
            'status' => $threshold,
        ];
        $failed = "{$selected->sql} AND status_code >= :status";
        $serverError = Failure::serverError('status_code');

        $groups = Stored::rows($connection, "SELECT group_hash, count(*) AS requests, count(status_code) AS with_status,
            count(*) FILTER (WHERE status_code >= :status) AS failed,
            count(*) FILTER (WHERE status_code >= :status AND {$serverError}) AS server_errors,
            count(*) FILTER (WHERE status_code >= :status AND EXISTS (SELECT 1 FROM exceptions WHERE exceptions.execution_id = requests.execution_id)) AS with_exception,
            min(started_at) FILTER (WHERE status_code >= :status) AS first_seen,
            max(started_at) FILTER (WHERE status_code >= :status) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) FILTER (WHERE status_code >= :status) AS actors,
            count(*) FILTER (WHERE status_code >= :status AND NULLIF(user_id, '') IS NULL) AS anonymous
            FROM requests WHERE {$selected->sql} GROUP BY group_hash", $bindings, $window);

        $examined = array_sum(array_column($groups, 'requests'));
        $failing = array_values(array_filter($groups, fn (array $row) => $row['failed'] > 0));

        if ($failing === []) {
            return Judgement::of($this->name(), $this->threshold()->describe($threshold), examined: $examined, total: 0, findings: []);
        }

        $latest = Stored::rows($connection, "SELECT group_hash, execution_id, route_path, method FROM (
            SELECT group_hash, execution_id, route_path, method, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS position
            FROM requests WHERE {$failed}) WHERE position = 1", $bindings, $window);
        $statuses = Stored::rows($connection, "SELECT group_hash, status_code, count(*) AS requests FROM requests WHERE {$failed} GROUP BY group_hash, status_code ORDER BY status_code", $bindings, $window);

        $shown = Judgement::worst($failing, fn (array $a, array $b) => [$b['server_errors'], $b['failed'], $this->failurePct($b), $a['group_hash']] <=> [$a['server_errors'], $a['failed'], $this->failurePct($a), $b['group_hash']], $limit);
        $findings = array_map(fn (array $row) => $this->finding(
            row: $row,
            latest: $this->ofGroup($latest, $row['group_hash'])[0] ?? [],
            statuses: $this->ofGroup($statuses, $row['group_hash']),
        ), $shown);

        return Judgement::of($this->name(), $this->threshold()->describe($threshold), examined: $examined, total: count($failing), findings: $findings);
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $latest
     * @param  list<array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    protected function finding(array $row, array $latest, array $statuses): array
    {
        $group = $row['group_hash'];
        $route = $latest['route_path'] ?? '';
        $unmatched = $route === '';

        return [
            'group' => $group,
            'name' => $unmatched ? __('firewatch::messages.rank_no_route') : $route,
            'count' => $row['failed'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $latest['execution_id'] ?? null,
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'failed' => $row['failed'],
                'requests' => $row['requests'],
                'failure_pct' => $this->failurePct($row),
                'server_errors' => $row['server_errors'],
                'status_counts' => array_column($statuses, 'requests', 'status_code'),
                'with_exception' => $row['with_exception'],
                'unmatched' => $unmatched,
                'method' => $latest['method'] ?? null,
            ],
        ];
    }

    /**
     * Get the share of the answered requests of a group that failed, in percent.
     *
     * @param  array<string, mixed>  $row
     */
    protected function failurePct(array $row): float
    {
        return round(Ranking::PERCENT * $row['failed'] / $row['with_status'], self::PERCENT_DECIMALS);
    }

    /**
     * Get the rows of one group, by its hash compared as text, so that two hashes that read as the same number stay apart.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function ofGroup(array $rows, ?string $group): array
    {
        return array_values(array_filter($rows, fn (array $row) => $row['group_hash'] === $group));
    }
}
