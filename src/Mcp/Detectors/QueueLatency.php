<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Lineage;
use ClaudioDekker\Firewatch\Mcp\Percentile;
use ClaudioDekker\Firewatch\Mcp\Ranking;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Microseconds;
use SQLite3;

/**
 * @internal
 */
class QueueLatency implements Detector
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::QUEUE_LATENCY;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'ms', unit: 'ms', default: 5000, minimum: 1);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::QUEUED_JOB, RecordType::JOB_ATTEMPT];
    }

    /**
     * Judge the dispatches that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $milliseconds = $threshold ?? $this->threshold()->default;
        $described = $this->threshold()->describe($threshold);
        $bindings = ['group' => $group ?? ''];

        $groups = $this->groups($connection, $window, [
            ...$bindings,
            'now' => Instant::now(),
            'per_second' => Microseconds::PER_SECOND,
            'microseconds' => $milliseconds * Microseconds::PER_MILLISECOND,
        ]);

        $examined = array_sum(array_column($groups, 'jobs'));
        $inline = array_sum(array_column($groups, 'inline'));
        $pending = array_sum(array_column($groups, 'pending'));
        $orphans = $this->attemptsWithoutDispatch($connection, $window, $bindings);

        $saw = [
            'inline_excluded' => $inline,
            'attempts_without_dispatch' => $orphans,
            'without_first_attempt' => array_sum(array_column($groups, 'without_first_attempt')),
        ];

        $caveats = [
            __('firewatch::messages.detect_caveat_wait'),
            ...($pending > 0 ? [__('firewatch::messages.detect_caveat_pending')] : []),
        ];

        if ($examined + $inline === 0 && $orphans > 0 && ! $this->hasDispatches($connection, $window)) {
            $removed = History::removedThrough(Markers::read($connection), [RecordType::QUEUED_JOB]);
            $reason = $removed !== null && ($window->since() === null || $window->since() < $removed) ? Reason::OUTSIDE_COVERAGE : Reason::PREREQUISITE_MISSING;

            return Judgement::notEvaluated($this->name(), $described, $reason, saw: $saw, caveats: $caveats);
        }

        $late = array_values(array_filter($groups, fn (array $row) => $row['qualifying'] > 0));

        usort($late, fn (array $a, array $b) => [$b['worst'], $a['group_hash']] <=> [$a['worst'], $b['group_hash']]);

        $findings = array_map($this->finding(...), array_slice($late, 0, $limit));

        return Judgement::of($this->name(), $described, examined: $examined, total: count($late), findings: $findings, saw: $saw, caveats: $caveats);
    }

    /**
     * Get what the dispatches say of each group.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, array $bindings): array
    {
        $dispatches = $this->dispatches($window);
        $rank = Ranking::nearestRank(Percentile::MEDIAN->share());

        return Stored::rows($connection, "{$dispatches}, judged AS (
            SELECT *, coalesce(wait >= :microseconds, 0) AS waited_long, coalesce(wait >= :microseconds OR age >= :microseconds, 0) AS late FROM dispatches
        ), ranked AS (
            SELECT *, count(wait) OVER (PARTITION BY group_hash) AS n,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY wait IS NULL, wait, id) AS by_wait,
                ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY late DESC, started_at DESC, id DESC) AS by_latest
            FROM judged
        )
        SELECT group_hash, count(*) FILTER (WHERE state = 'inline') AS inline, count(*) FILTER (WHERE state != 'inline') AS jobs,
            count(*) FILTER (WHERE state = 'pending') AS pending,
            count(*) FILTER (WHERE state = 'without_first_attempt') AS without_first_attempt,
            count(wait) AS waits, count(*) FILTER (WHERE waited_long) AS waits_over_threshold, count(*) FILTER (WHERE late) AS qualifying,
            max(wait) AS worst_wait, max(age) AS oldest_pending_age, max(coalesce(wait, age)) AS worst,
            max(CASE WHEN by_wait = {$rank} THEN wait END) AS median_wait,
            max(CASE WHEN by_latest = 1 THEN coalesce(attempt_execution_id, dispatch_execution_id) END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN name END) AS name,
            json_group_array(DISTINCT queue) FILTER (WHERE state != 'inline') AS queues,
            json_group_array(DISTINCT connection) FILTER (WHERE state != 'inline') AS connections,
            min(started_at) FILTER (WHERE late) AS first_seen, max(started_at) FILTER (WHERE late) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) FILTER (WHERE late) AS actors,
            count(*) FILTER (WHERE late AND NULLIF(user_id, '') IS NULL) AS anonymous
        FROM ranked GROUP BY group_hash", $bindings, $window);
    }

    /**
     * Get the SQL of the dispatches that started in the window.
     */
    protected function dispatches(Window $window): string
    {
        $inline = Lineage::inlineCondition('queued.connection');

        return "WITH queued AS (
            SELECT id, execution_id, started_at, coalesce(ended_at, started_at) AS queued_at, group_hash, user_id, name, connection, queue, NULLIF(job_id, '') AS job
            FROM queued_jobs WHERE {$window->condition()} AND (:group = '' OR group_hash = :group)
        ), firsts AS (
            SELECT job_id AS job, execution_id, started_at, attempt,
                ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY attempt = 1 DESC, started_at, id) AS position
            FROM job_attempts WHERE job_id IN (SELECT job FROM queued)
        ), placed AS (
            SELECT queued.*, NULLIF(firsts.execution_id, '') AS attempt_execution_id, firsts.started_at AS first_started_at,
                CASE
                    WHEN {$inline} THEN 'inline'
                    WHEN firsts.attempt = 1 THEN 'waited'
                    WHEN firsts.job IS NULL THEN 'pending'
                    ELSE 'without_first_attempt'
                END AS state
            FROM queued LEFT JOIN firsts ON firsts.job = queued.job AND firsts.position = 1
        ), dispatches AS (
            SELECT id, started_at, group_hash, user_id, name, connection, queue, state, NULLIF(execution_id, '') AS dispatch_execution_id,
                CASE WHEN state = 'waited' THEN attempt_execution_id END AS attempt_execution_id,
                CASE WHEN state = 'waited' THEN max(0, CAST(round((first_started_at - queued_at) * :per_second) AS INTEGER)) END AS wait,
                CASE WHEN state = 'pending' THEN max(0, CAST(round((:now - queued_at) * :per_second) AS INTEGER)) END AS age
            FROM placed
        )";
    }

    /**
     * Count the attempts that started in the window whose job has no stored dispatch.
     *
     * @param  array<string, int|float|string|null>  $bindings
     */
    protected function attemptsWithoutDispatch(SQLite3 $connection, Window $window, array $bindings): int
    {
        [$row] = Stored::rows($connection, "SELECT count(*) AS attempts FROM job_attempts
            WHERE {$window->condition()} AND (:group = '' OR group_hash = :group)
            AND NOT EXISTS (SELECT 1 FROM queued_jobs WHERE queued_jobs.job_id = NULLIF(job_attempts.job_id, ''))", $bindings, $window);

        return $row['attempts'];
    }

    /**
     * Determine if the window holds a dispatch of any group, on any connection.
     */
    protected function hasDispatches(SQLite3 $connection, Window $window): bool
    {
        [$row] = Stored::rows($connection, "SELECT EXISTS (SELECT 1 FROM queued_jobs WHERE {$window->condition()}) AS dispatched", [], $window);

        return (bool) $row['dispatched'];
    }

    /**
     * Get the finding of a group.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => Stored::blank($row['name']) ?? __('firewatch::messages.rank_no_route'),
            'count' => Stored::milliseconds($row['worst']),
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'jobs' => $row['jobs'],
                'waits_over_threshold' => $row['waits_over_threshold'],
                'pending' => $row['pending'],
                'worst_wait_ms' => Stored::milliseconds($row['worst_wait']),
                'median_wait_ms' => $row['waits'] >= Percentile::MEDIAN->floor() ? Stored::milliseconds($row['median_wait']) : null,
                'oldest_pending_age_ms' => Stored::milliseconds($row['oldest_pending_age']),
                'queues' => $this->seen($row['queues']),
                'connections' => $this->seen($row['connections']),
            ],
        ];
    }

    /**
     * Get the distinct names a group's dispatches carry.
     *
     * @return list<string>
     */
    protected function seen(string $names): array
    {
        /** @var list<mixed> $decoded */
        $decoded = json_decode($names, associative: true, flags: JSON_THROW_ON_ERROR);
        $named = array_values(array_filter($decoded, fn (mixed $name) => is_string($name) && $name !== ''));

        sort($named);

        return $named;
    }
}
