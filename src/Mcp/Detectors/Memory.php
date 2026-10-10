<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class Memory implements Detector
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::MEMORY;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'peak', unit: 'mb', default: 64, minimum: 1, whole: false);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return ExecutionType::records();
    }

    /**
     * Judge the executions that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $described = $this->threshold()->describe($threshold);
        $caveats = [__('firewatch::messages.detect_caveat_memory')];

        $executions = Executions::table($window, $group);
        $groups = $this->groups($connection, $window, $executions, [
            ...$executions->bindings,
            'bytes' => Stored::bytes($threshold),
        ]);

        $examined = array_sum(array_column($groups, 'executions'));
        $heavy = array_values(array_filter($groups, fn (array $row) => $row['executions_over'] > 0));
        $findings = array_map($this->finding(...), $heavy);

        usort($findings, fn (array $a, array $b) => [$b['count'], $b['evidence']['executions_over'], $a['group']] <=> [$a['count'], $a['evidence']['executions_over'], $b['group']]);

        return Judgement::of($this->name(), $described, examined: $examined, total: count($findings), findings: array_slice($findings, 0, $limit), caveats: $caveats);
    }

    /**
     * Get what the executions that have a peak say of each group.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, Fragment $executions, array $bindings): array
    {
        $rank = Ranking::nearestRank(Percentile::MEDIAN->share());

        return Stored::rows($connection, "{$executions->sql}, measured AS (
            SELECT *, peak >= :bytes AS reached FROM executions WHERE peak IS NOT NULL
        ), ranked AS (
            SELECT *, count(*) OVER (PARTITION BY group_hash) AS n,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY peak, id) AS by_peak,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY peak DESC, started_at DESC, id DESC) AS by_worst,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY reached DESC, started_at DESC, id DESC) AS by_latest
            FROM measured
        )
        SELECT group_hash, count(*) AS executions, count(*) FILTER (WHERE reached) AS executions_over, max(peak) AS peak,
            max(CASE WHEN by_peak = {$rank} THEN peak END) AS median,
            max(CASE WHEN by_worst = 1 THEN execution_id END) AS worst_execution_id,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN label END) AS label,
            min(started_at) FILTER (WHERE reached) AS first_seen, max(started_at) FILTER (WHERE reached) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) FILTER (WHERE reached) AS actors,
            count(*) FILTER (WHERE reached AND NULLIF(user_id, '') IS NULL) AS anonymous
        FROM ranked GROUP BY group_hash", $bindings, $window);
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        $peak = Stored::megabytes($row['peak']);
        $median = $row['executions'] >= Percentile::MEDIAN->floor() ? Stored::megabytes($row['median']) : null;

        return [
            'group' => $row['group_hash'],
            'name' => Stored::blank($row['label']) ?? __('firewatch::messages.rank_no_route'),
            'count' => $peak,
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'worst_execution_id' => $row['worst_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'peak_mb' => $peak,
                'executions_over' => $row['executions_over'],
                'executions' => $row['executions'],
                'p50_mb' => $median,
            ],
        ];
    }
}
