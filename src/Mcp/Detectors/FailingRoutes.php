<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Failure;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class FailingRoutes implements Detector
{
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
     * Get what the detector judges: routes by their requests that failed.
     */
    public function judges(): Subject
    {
        return Subject::EXECUTIONS;
    }

    /**
     * Judge the requests of the window.
     */
    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $selected = Fragment::selecting($window, $group);
        $bindings = [
            ...$selected->bindings,
            'status' => $threshold,
        ];
        $status = Failure::requestStatus();
        $failed = "{$selected->sql} AND {$status} >= :status";
        $serverError = Failure::serverError($status);

        $reach = Fragment::reach("{$status} >= :status");

        $groups = Stored::rows($connection, "SELECT group_hash, count(*) AS requests, count({$status}) AS with_status,
            count(*) FILTER (WHERE {$status} >= :status) AS failed,
            count(*) FILTER (WHERE {$status} >= :status AND {$serverError}) AS server_errors,
            count(*) FILTER (WHERE {$status} >= :status AND invalid_fields IS NOT NULL) AS failed_validations,
            count(*) FILTER (WHERE {$status} >= :status AND EXISTS (SELECT 1 FROM exceptions WHERE exceptions.execution_id = requests.execution_id)) AS with_exception,
            {$reach}
            FROM requests WHERE {$selected->sql} GROUP BY group_hash", $bindings, $window);

        $examined = array_sum(array_column($groups, 'requests'));
        $failing = array_values(array_filter($groups, fn (array $row) => $row['failed'] > 0));

        if ($failing === []) {
            return Judgement::of($this->name(), $this->threshold()->describe($threshold), examined: $examined, total: 0, findings: []);
        }

        $latestRows = Stored::rows($connection, "SELECT group_hash, execution_id, route_path, method FROM (
            SELECT group_hash, execution_id, route_path, method, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS position
            FROM requests WHERE {$failed}) WHERE position = 1", $bindings, $window);
        $statusRows = Stored::rows($connection, "SELECT group_hash, status_code, count(*) AS requests FROM requests WHERE {$failed} GROUP BY group_hash, status_code ORDER BY status_code", $bindings, $window);
        $latest = Stored::byGroup($latestRows);
        $statuses = Stored::byGroup($statusRows);

        $shown = Judgement::worst($failing, fn (array $a, array $b) => [$b['server_errors'], $b['failed'], $this->failurePct($b), $a['group_hash']] <=> [$a['server_errors'], $a['failed'], $this->failurePct($a), $b['group_hash']], $limit);
        $caveats = array_sum(array_column($failing, 'failed_validations')) > 0 ? [__('firewatch::messages.detect_caveat_validation')] : [];
        $findings = array_map(fn (array $row) => $this->finding(
            row: $row,
            latest: $latest[$row['group_hash']][0] ?? [],
            statuses: $statuses[$row['group_hash']] ?? [],
        ), $shown);

        return Judgement::of($this->name(), $this->threshold()->describe($threshold), examined: $examined, total: count($failing), findings: $findings, caveats: $caveats);
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $latest
     * @param  list<array<string, mixed>>  $statuses
     */
    protected function finding(array $row, array $latest, array $statuses): Finding
    {
        $route = $latest['route_path'] ?? '';

        $reached = [
            ...$row,
            'latest_execution_id' => $latest['execution_id'] ?? null,
        ];

        return Finding::from($reached, name: RecordType::REQUEST->groupLabel($route), count: $row['failed'], evidence: [
            'failed' => $row['failed'],
            'requests' => $row['requests'],
            'failure_pct' => $this->failurePct($row),
            'server_errors' => $row['server_errors'],
            'failed_validations' => $row['failed_validations'],
            'status_counts' => array_column($statuses, 'requests', 'status_code'),
            'with_exception' => $row['with_exception'],
            'unmatched' => $route === '',
            'method' => $latest['method'] ?? null,
        ]);
    }

    /**
     * Get the share of the answered requests of a group that failed, in percent, or null for a group with no answered request.
     *
     * @param  array<string, mixed>  $row
     */
    protected function failurePct(array $row): ?float
    {
        return Percentile::percent($row['failed'], $row['with_status']);
    }
}
