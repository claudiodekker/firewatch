<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
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
     * Get what the detector judges: each execution by its peak memory.
     */
    public function judges(): Subject
    {
        return Subject::EXECUTIONS;
    }

    /**
     * Judge the executions that started in the window.
     */
    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $described = $this->threshold()->describe($threshold);
        $caveats = [__('firewatch::messages.detect_caveat_memory')];

        $executions = Executions::withoutCoverage($window, $group);
        $groups = $this->groups($connection, $window, $executions, $threshold);

        $examined = array_sum(array_column($groups, 'executions'));
        $heavy = array_values(array_filter($groups, fn (array $row) => $row['executions_over'] > 0));
        $shown = Judgement::worst($heavy, fn (array $a, array $b) => [Stored::megabytes($b['peak']), $b['executions_over'], $a['group_hash']] <=> [Stored::megabytes($a['peak']), $a['executions_over'], $b['group_hash']], $limit);
        $findings = array_map($this->finding(...), $shown);

        return Judgement::of($this->name(), $described, examined: $examined, total: count($heavy), findings: $findings, caveats: $caveats);
    }

    /**
     * Get what the executions that have a peak say of each group.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, Fragment $executions, int|float $megabytes): array
    {
        $rank = Percentile::MEDIAN->nearestRank();

        $reach = Fragment::reach('reached');

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
            max(CASE WHEN by_latest = 1 THEN type END) AS type,
            max(CASE WHEN by_latest = 1 THEN label END) AS label,
            {$reach}
        FROM ranked GROUP BY group_hash", [
            ...$executions->bindings,
            'bytes' => Stored::bytes($megabytes),
        ], $window);
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $row
     */
    protected function finding(array $row): Finding
    {
        $peak = Stored::megabytes($row['peak']);
        $median = $row['executions'] >= Percentile::MEDIAN->floor() ? Stored::megabytes($row['median']) : null;

        return Finding::from($row, name: RecordType::from($row['type'])->groupLabel($row['label']), count: $peak, evidence: [
            'peak_mb' => $peak,
            'executions_over' => $row['executions_over'],
            'executions' => $row['executions'],
            'p50_mb' => $median,
        ], worstExecutionId: $row['worst_execution_id']);
    }
}
