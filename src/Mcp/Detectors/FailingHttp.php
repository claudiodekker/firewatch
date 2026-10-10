<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class FailingHttp implements Detector
{
    /**
     * The most URLs and the most execution groups a finding lists.
     */
    protected const LISTED = 3;

    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * The percent a share is of its whole.
     */
    protected const PERCENT = 100;

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::FAILING_HTTP;
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
        return [RecordType::OUTGOING_REQUEST];
    }

    /**
     * Judge the hosts of the outgoing requests that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $described = $this->threshold()->describe($threshold);
        $caveats = [__('firewatch::messages.detect_caveat_unanswered')];

        $examined = $this->examined($connection, $window, $group);

        if ($examined === 0) {
            return Judgement::of($this->name(), $described, examined: 0, total: 0, findings: [], caveats: $caveats);
        }

        $hosts = $this->hosts($connection, $window, $threshold, $group, $limit);
        $findings = array_map($this->finding(...), $hosts);

        return Judgement::of($this->name(), $described, examined: $examined, total: $hosts[0]['total'] ?? 0, findings: $findings, caveats: $caveats);
    }

    /**
     * Count the outgoing requests that started in the window, with a status or without.
     */
    protected function examined(SQLite3 $connection, Window $window, ?string $group): int
    {
        $selected = Fragment::selecting($window, $group);

        [$row] = Stored::rows($connection, "SELECT count(*) AS examined FROM outgoing_requests WHERE {$selected->sql}", $selected->bindings, $window);

        return $row['examined'];
    }

    /**
     * Get the hosts with an outgoing request at the status or above, worst first and at most the limit, each row with the total before the cut.
     *
     * @return list<array<string, mixed>>
     */
    protected function hosts(SQLite3 $connection, Window $window, int|float $status, ?string $group, int $limit): array
    {
        $selected = Fragment::selecting($window, $group);
        $labels = Executions::labels();
        $code = Stored::number('status_code');
        $listed = self::LISTED;
        $worstFirst = 'failures DESC, CAST(failures AS REAL) / answered DESC, last_seen DESC, group_hash ASC';

        // A group hash is compared with IS, so that the requests the wire sent without one stay one group.
        return Stored::rows($connection, "{$labels->sql}, calls AS MATERIALIZED (
            SELECT id, started_at, group_hash, host, execution_source AS source,
                CASE WHEN instr(url, '?') > 0 THEN substr(url, 1, instr(url, '?') - 1) ELSE url END AS url,
                NULLIF(execution_id, '') AS execution_id, NULLIF(user_id, '') AS user_id,
                {$code} AS status, {$code} >= :status AS failed
            FROM outgoing_requests WHERE {$selected->sql}
        ), placed AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY failed DESC, started_at DESC, id DESC) AS by_latest FROM calls
        ), hosts AS (
            SELECT group_hash, count(*) AS calls, count(status) AS answered, count(*) FILTER (WHERE failed) AS failures,
                max(CASE WHEN by_latest = 1 THEN host END) AS host,
                max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
                min(started_at) FILTER (WHERE failed) AS first_seen, max(started_at) FILTER (WHERE failed) AS last_seen,
                count(DISTINCT user_id) FILTER (WHERE failed) AS actors,
                count(*) FILTER (WHERE failed AND user_id IS NULL) AS anonymous
            FROM placed GROUP BY group_hash HAVING count(*) FILTER (WHERE failed) > 0
        ), ranked AS (
            SELECT *, count(*) OVER () AS total FROM hosts
            ORDER BY {$worstFirst} LIMIT :limit
        ), statuses AS (
            SELECT group_hash, status, count(*) AS calls FROM calls WHERE failed GROUP BY group_hash, status
        ), urls AS (
            SELECT group_hash, url, count(*) AS failures,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY count(*) DESC, url) AS position
            FROM calls WHERE failed GROUP BY group_hash, url
        ), labelled AS (
            SELECT execution_id, COALESCE(label, '') AS label, ROW_NUMBER() OVER (PARTITION BY execution_id ORDER BY started_at DESC, id DESC) AS position
            FROM (SELECT DISTINCT execution_id FROM calls WHERE failed AND execution_id IS NOT NULL) AS carried JOIN labels USING (execution_id)
        ), units AS (
            SELECT calls.group_hash, calls.source, labelled.label, count(*) AS calls,
                ROW_NUMBER() OVER (PARTITION BY calls.group_hash ORDER BY count(*) DESC, calls.source, labelled.label) AS position
            FROM calls LEFT JOIN labelled ON labelled.execution_id = calls.execution_id AND labelled.position = 1
            WHERE calls.failed GROUP BY calls.group_hash, calls.source, labelled.label
        )
        SELECT group_hash, host, calls, answered, failures, latest_execution_id, first_seen, last_seen, actors, anonymous, total,
            (SELECT json_group_object(status, calls) FROM (
                SELECT status, calls FROM statuses WHERE statuses.group_hash IS ranked.group_hash ORDER BY status
            )) AS status_counts,
            (SELECT json_group_array(json_object('url', url, 'failures', failures)) FROM (
                SELECT url, failures FROM urls WHERE urls.group_hash IS ranked.group_hash AND position <= {$listed} ORDER BY position
            )) AS top_urls,
            (SELECT json_group_array(json_object('source', source, 'label', label, 'calls', calls)) FROM (
                SELECT source, label, calls FROM units WHERE units.group_hash IS ranked.group_hash AND position <= {$listed} ORDER BY position
            )) AS ran_in
        FROM ranked ORDER BY {$worstFirst}", [
            ...$selected->bindings,
            'status' => $status,
            'limit' => $limit,
        ], $window);
    }

    /**
     * Get the finding of a host.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => is_string($row['host']) ? $row['host'] : '',
            'count' => $row['failures'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'host' => $row['host'],
                'calls' => $row['calls'],
                'failures' => $row['failures'],
                'failure_pct' => round(self::PERCENT * $row['failures'] / $row['answered'], self::PERCENT_DECIMALS),
                'status_counts' => Stored::json($row['status_counts']),
                'top_urls' => Stored::json($row['top_urls']),
                'ran_in' => array_map($this->unit(...), Stored::json($row['ran_in'])),
            ],
        ];
    }

    /**
     * Get an execution group as a finding lists it, without a label when the store holds no execution of its outgoing requests.
     *
     * @param  array<string, mixed>  $unit
     * @return array{source: mixed, label: mixed, calls: int}
     */
    protected function unit(array $unit): array
    {
        return [
            'source' => $unit['source'],
            'label' => Executions::label($unit['label']),
            'calls' => $unit['calls'],
        ];
    }
}
