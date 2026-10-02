<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\Microseconds;

/**
 * @internal
 */
class Lineage
{
    /**
     * The connections whose jobs run inside the dispatching execution, and so leave no attempt.
     *
     * @var list<string>
     */
    public const INLINE_CONNECTIONS = ['sync'];

    /**
     * Get the lineage of each queued job: its dispatch, its attempts in order with the wait before each, and how it ended, in the order the lineages began.
     *
     * @param  list<array<string, mixed>>  $dispatches  by when they started
     * @param  list<array<string, mixed>>  $attempts  by when they started
     * @param  string|null  $trace  the trace an attempt is not named for
     * @return list<array<string, mixed>>
     */
    public static function jobs(array $dispatches, array $attempts, ?string $trace): array
    {
        $dispatched = [];
        $ran = [];

        foreach ($dispatches as $dispatch) {
            $dispatched[$dispatch['job_id']] ??= $dispatch;
        }

        foreach ($attempts as $attempt) {
            $ran[$attempt['job_id']][] = $attempt;
        }

        $jobs = [];

        foreach (array_unique([...array_keys($dispatched), ...array_keys($ran)]) as $job) {
            $jobs[] = self::job((string) $job, $dispatched[$job] ?? null, $ran[$job] ?? [], $dispatched[$job] ?? $ran[$job][0], $trace);
        }

        usort($jobs, fn (array $a, array $b) => [$a['began'], $a['job_id']] <=> [$b['began'], $b['job_id']]);

        return array_map(fn (array $job) => array_diff_key($job, ['began' => 0]), $jobs);
    }

    /**
     * Get the lineage of one job, with when it began so that the jobs can be put in order.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     * @param  array<string, mixed>  $first  the dispatch, or the first attempt of a job with none
     * @return array<string, mixed>
     */
    protected static function job(string $job, ?array $dispatch, array $attempts, array $first, ?string $trace): array
    {
        $state = match (true) {
            $dispatch === null => LineageState::NO_DISPATCH,
            $attempts === [] => LineageState::NO_ATTEMPTS,
            default => LineageState::COMPLETE,
        };

        return [
            'job_id' => $job,
            'name' => $first['name'],
            'lineage' => $state->value,
            'outcome' => self::outcome($dispatch, $attempts)?->value,
            'dispatch' => $dispatch === null ? null : self::dispatch($dispatch),
            'attempts' => self::attempts($dispatch, $attempts, $trace),
            'began' => $first['started_at'],
        ];
    }

    /**
     * Get how the job ended: by its last attempt, as pending while a dispatch has none, and as none for a dispatch that runs inline and so never has one.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     */
    protected static function outcome(?array $dispatch, array $attempts): ?JobOutcome
    {
        if ($attempts !== []) {
            return JobOutcome::of($attempts[array_key_last($attempts)]['status']);
        }

        return self::inline($dispatch) ? null : JobOutcome::PENDING;
    }

    /**
     * Determine if a dispatch is on a connection that runs the job inside the dispatching execution.
     *
     * @param  array<string, mixed>|null  $dispatch
     */
    public static function inline(?array $dispatch): bool
    {
        return in_array($dispatch['connection'] ?? null, self::INLINE_CONNECTIONS, true);
    }

    /**
     * Get what a dispatch shows: the execution that caused it, where it went and how long it took.
     *
     * @param  array<string, mixed>  $dispatch
     * @return array<string, mixed>
     */
    protected static function dispatch(array $dispatch): array
    {
        return [
            'execution_id' => $dispatch['execution_id'],
            'connection' => $dispatch['connection'],
            'queue' => $dispatch['queue'],
            'started_at' => $dispatch['started_at'],
            'duration_ms' => Stored::milliseconds($dispatch['duration']),
        ];
    }

    /**
     * Get the attempts in order, each with the wait before it: from the end of the dispatch for the first and from the end of the attempt before for the others.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    protected static function attempts(?array $dispatch, array $attempts, ?string $trace): array
    {
        $waitingFrom = $dispatch['ended_at'] ?? null;
        $shown = [];

        foreach ($attempts as $attempt) {
            $shown[] = [
                'attempt' => $attempt['attempt'],
                'execution_id' => $attempt['execution_id'],
                'status' => $attempt['status'],
                'started_at' => $attempt['started_at'],
                'duration_ms' => Stored::milliseconds($attempt['duration']),
                'trace_id' => $attempt['trace_id'] === $trace ? null : $attempt['trace_id'],
                'wait_ms' => self::wait($attempt['started_at'], $waitingFrom),
            ];

            $waitingFrom = $attempt['ended_at'];
        }

        return $shown;
    }

    /**
     * Get the milliseconds from the end of what a job waited for to the start of its attempt, or null when that end is not known.
     */
    protected static function wait(float $startedAt, ?float $waitingFrom): ?float
    {
        return $waitingFrom === null ? null : Stored::milliseconds(round(($startedAt - $waitingFrom) * Microseconds::PER_SECOND));
    }
}
