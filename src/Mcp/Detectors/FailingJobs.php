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
class FailingJobs implements Detector
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::FAILING_JOBS;
    }

    /**
     * Get the threshold: the failed and released attempts at which a group is a finding.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'attempts', unit: 'attempts', default: 1, minimum: 1);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::JOB_ATTEMPT];
    }

    /**
     * Judge the job attempts that started in the window: a group is a finding when its failed and released attempts number at least the threshold.
     */
    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $attempts = $threshold ?? $this->threshold()->default;
        $described = $this->threshold()->describe($threshold);
        $bindings = [
            'group' => $group ?? '',
            'failed' => Outcome::FAILED->value,
            'released' => Outcome::RELEASED->value,
        ];

        $groups = $this->groups($connection, $window, $bindings);

        $examined = array_sum(array_column($groups, 'attempts'));
        $failing = array_values(array_filter($groups, fn (array $row) => $row['failing_attempts'] >= $attempts));

        if ($failing === []) {
            return Judgement::of($this->name(), $described, examined: $examined, total: 0, findings: []);
        }

        usort($failing, fn (array $a, array $b) => [$b['jobs_failed'], $b['failing_attempts'], $b['last_seen'], $a['group_hash']] <=> [$a['jobs_failed'], $a['failing_attempts'], $a['last_seen'], $b['group_hash']]);

        $shown = array_slice($failing, 0, $limit);
        $exceptions = $this->exceptions($connection, $window, $bindings);
        $findings = array_map(fn (array $row) => $this->finding($row, $this->ofGroup($exceptions, $row['group_hash'])), $shown);

        return Judgement::of($this->name(), $described, examined: $examined, total: count($failing), findings: $findings);
    }

    /**
     * Get what the attempts say of each group: how many failed and were released, how its jobs ended, and when and whom the failed and released ones reached.
     *
     * A job is a job id within a group. It ended as its last attempt in the window did, and it recovered when that was processed after a release.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, array $bindings): array
    {
        $attempts = $this->attempts($window);

        return Stored::rows($connection, "{$attempts}, placed AS (
            SELECT *, job IS NOT NULL AND ROW_NUMBER() OVER (PARTITION BY group_hash, job ORDER BY started_at DESC, attempt DESC, id DESC) = 1 AS is_last,
                max(released) OVER (PARTITION BY group_hash, job) AS was_released,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY failing DESC, started_at DESC, id DESC) AS by_latest
            FROM attempts
        )
        SELECT group_hash, count(*) AS attempts, count(*) FILTER (WHERE failing) AS failing_attempts,
            count(*) FILTER (WHERE failed) AS failed_attempts, count(*) FILTER (WHERE released) AS retried_attempts,
            count(DISTINCT job) AS jobs,
            count(*) FILTER (WHERE is_last AND failed) AS jobs_failed,
            count(*) FILTER (WHERE is_last AND status = :processed AND was_released) AS jobs_recovered,
            count(*) FILTER (WHERE is_last AND released) AS jobs_retrying,
            max(attempt) AS max_attempt,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN name END) AS name,
            min(started_at) FILTER (WHERE failing) AS first_seen, max(started_at) FILTER (WHERE failing) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) FILTER (WHERE failing) AS actors,
            count(*) FILTER (WHERE failing AND NULLIF(user_id, '') IS NULL) AS anonymous
        FROM placed GROUP BY group_hash", [
            ...$bindings,
            'processed' => Outcome::PROCESSED->value,
        ], $window);
    }

    /**
     * Get the latest exception of each group, among those recorded in the execution of a failed or released attempt.
     *
     * An exception is read by its execution, wherever it falls itself.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function exceptions(SQLite3 $connection, Window $window, array $bindings): array
    {
        $attempts = $this->attempts($window);

        return Stored::rows($connection, "{$attempts}, thrown AS (
            SELECT attempts.group_hash, exceptions.class, exceptions.message, exceptions.file, exceptions.line,
                ROW_NUMBER() OVER (PARTITION BY attempts.group_hash ORDER BY exceptions.started_at DESC, exceptions.id DESC) AS position
            FROM attempts JOIN exceptions ON exceptions.execution_id = attempts.execution_id
            WHERE attempts.failing
        )
        SELECT group_hash, class, message, file, line FROM thrown WHERE position = 1", $bindings, $window);
    }

    /**
     * Get the SQL of the attempts that started in the window and belong to the group bound as `:group`, as the table `attempts`, each with whether it failed or was released.
     *
     * An attempt with no status did neither, and one with no job id belongs to no job.
     */
    protected function attempts(Window $window): string
    {
        $attempt = Stored::number('attempt');

        return "WITH attempts AS (
            SELECT id, execution_id, started_at, group_hash, user_id, name, status, NULLIF(job_id, '') AS job, {$attempt} AS attempt,
                status = :failed AS failed, status = :released AS released, status IN (:failed, :released) AS failing
            FROM job_attempts WHERE {$window->condition()} AND (:group = '' OR group_hash = :group)
        )";
    }

    /**
     * Get the finding of a group whose attempts failed or were released.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $exception  the latest exception of a failed or released attempt
     * @return array<string, mixed>
     */
    protected function finding(array $row, ?array $exception): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => Stored::blank($row['name']) ?? __('firewatch::messages.rank_no_route'),
            'count' => $row['failing_attempts'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'failed_attempts' => $row['failed_attempts'],
                'retried_attempts' => $row['retried_attempts'],
                'jobs_failed' => $row['jobs_failed'],
                'jobs_recovered' => $row['jobs_recovered'],
                'jobs_retrying' => $row['jobs_retrying'],
                'jobs' => $row['jobs'],
                'max_attempt' => $row['max_attempt'],
                'last_exception' => $exception === null ? null : [
                    'class' => $exception['class'],
                    'message' => $exception['message'],
                    'location' => Stored::location(file: $exception['file'], line: $exception['line']),
                ],
            ],
        ];
    }

    /**
     * Get the row of one group, by its hash compared as text, so that two hashes that read as the same number stay apart.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function ofGroup(array $rows, ?string $group): ?array
    {
        return array_values(array_filter($rows, fn (array $row) => $row['group_hash'] === $group))[0] ?? null;
    }
}
