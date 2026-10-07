<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Outcome;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
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
     * Get the threshold the detector takes: none.
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
     * Judge the scheduled tasks that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $caveats = [
            __('firewatch::messages.detect_caveat_skipped'),
            __('firewatch::messages.detect_caveat_not_fired'),
        ];

        $groups = $this->groups($connection, $window, $group);

        $examined = array_sum(array_column($groups, 'runs'));
        $failing = array_values(array_filter($groups, fn (array $row) => $row['failed'] + $row['skipped'] > 0));

        // Only a group of the failed kind has a failure, so the failures order the kinds as well.
        usort($failing, fn (array $a, array $b) => [$b['failed'], $b['skipped']] <=> [$a['failed'], $a['skipped']] ?: strcmp($a['group_hash'], $b['group_hash']));

        $findings = array_map($this->finding(...), array_slice($failing, 0, $limit));

        return Judgement::of($this->name(), null, examined: $examined, total: count($failing), findings: $findings, caveats: $caveats);
    }

    /**
     * Get what the scheduled tasks say of each group.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, ?string $group): array
    {
        return Stored::rows($connection, "WITH tasks AS (
            SELECT id, execution_id, started_at, group_hash, user_id, name,
                status = :failed AS failed, status = :skipped AS skipped, status IN (:failed, :skipped) AS failing
            FROM scheduled_tasks WHERE {$window->condition()} AND (:group = '' OR group_hash = :group)
        ), placed AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY failing DESC, started_at DESC, id DESC) AS by_latest FROM tasks
        )
        SELECT group_hash, count(*) AS runs, count(*) FILTER (WHERE failed) AS failed, count(*) FILTER (WHERE skipped) AS skipped,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN name END) AS name,
            min(started_at) FILTER (WHERE failing) AS first_seen, max(started_at) FILTER (WHERE failing) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) FILTER (WHERE failing) AS actors,
            count(*) FILTER (WHERE failing AND NULLIF(user_id, '') IS NULL) AS anonymous
        FROM placed GROUP BY group_hash", [
            'group' => $group ?? '',
            'failed' => Outcome::FAILED->value,
            'skipped' => Outcome::SKIPPED->value,
        ], $window);
    }

    /**
     * Get the finding of a group with a scheduled task that failed or was skipped.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => Stored::blank($row['name']) ?? __('firewatch::messages.rank_no_route'),
            'count' => $row['failed'] + $row['skipped'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'kind' => TaskKind::of($row['failed'])->value,
                'failed' => $row['failed'],
                'skipped' => $row['skipped'],
                'runs' => $row['runs'],
            ],
        ];
    }
}
