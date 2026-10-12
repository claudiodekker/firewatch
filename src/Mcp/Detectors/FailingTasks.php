<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Outcome;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class FailingTasks implements Detector
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::FAILING_TASKS;
    }

    /**
     * Get the threshold the detector takes, which is none.
     */
    public function threshold(): ?Threshold
    {
        return null;
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::SCHEDULED_TASK];
    }

    /**
     * Get what the detector judges: scheduled tasks by their runs that failed.
     */
    public function judges(): Subject
    {
        return Subject::EXECUTIONS;
    }

    /**
     * Judge the scheduled tasks that started in the window.
     */
    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $caveats = [
            __('firewatch::messages.detect_caveat_skipped'),
            __('firewatch::messages.detect_caveat_not_fired'),
        ];

        $groups = $this->groups($connection, $window, $group);

        $examined = array_sum(array_column($groups, 'runs'));
        $failing = array_values(array_filter($groups, fn (array $row) => $row['failed'] + $row['skipped'] > 0));

        // Only a group of the failed kind has a failure, so the failures order the kinds as well.
        $shown = Judgement::worst($failing, fn (array $a, array $b) => [$b['failed'], $b['skipped']] <=> [$a['failed'], $a['skipped']] ?: strcmp($a['group_hash'], $b['group_hash']), $limit);
        $findings = array_map($this->finding(...), $shown);

        return Judgement::of($this->name(), null, examined: $examined, total: count($failing), findings: $findings, caveats: $caveats);
    }

    /**
     * Get what the scheduled tasks say of each group.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, ?string $group): array
    {
        $selected = Fragment::selecting($window, $group);

        $reach = Fragment::reach('failing');

        return Stored::rows($connection, "WITH tasks AS (
            SELECT id, execution_id, started_at, group_hash, user_id, name,
                status = :failed AS failed, status = :skipped AS skipped, status IN (:failed, :skipped) AS failing
            FROM scheduled_tasks WHERE {$selected->sql}
        ), placed AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY failing DESC, started_at DESC, id DESC) AS by_latest FROM tasks
        )
        SELECT group_hash, count(*) AS runs, count(*) FILTER (WHERE failed) AS failed, count(*) FILTER (WHERE skipped) AS skipped,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN name END) AS name,
            {$reach}
        FROM placed GROUP BY group_hash", [
            ...$selected->bindings,
            'failed' => Outcome::FAILED->value,
            'skipped' => Outcome::SKIPPED->value,
        ], $window);
    }

    /**
     * Get the finding of a group with a scheduled task that failed or was skipped.
     *
     * @param  array<string, mixed>  $row
     */
    protected function finding(array $row): Finding
    {
        return Finding::from($row, name: RecordType::SCHEDULED_TASK->groupLabel($row['name']), count: $row['failed'] + $row['skipped'], evidence: [
            'kind' => TaskKind::of($row['failed'])->value,
            'failed' => $row['failed'],
            'skipped' => $row['skipped'],
            'runs' => $row['runs'],
        ]);
    }
}
