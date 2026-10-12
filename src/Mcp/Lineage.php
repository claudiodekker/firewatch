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
    protected const INLINE_CONNECTIONS = [
        'sync',
        'deferred',
        'background',
        'null',
    ];

    /**
     * Get the lineage of each queued job, in the order the lineages began.
     *
     * @return list<array<string, mixed>>
     */
    public static function jobs(TraceRecords $records): array
    {
        $dispatched = [];
        $ran = [];

        foreach ($records->dispatches as $dispatch) {
            $dispatched[$dispatch['job_id']] ??= $dispatch;
        }

        foreach ($records->attempts as $attempt) {
            $ran[$attempt['job_id']][] = $attempt;
        }

        $jobs = array_map(strval(...), array_unique([...array_keys($dispatched), ...array_keys($ran)]));
        $began = fn (string $job) => ($dispatched[$job] ?? $ran[$job][0])['started_at'];

        usort($jobs, fn (string $a, string $b) => [$began($a), $a] <=> [$began($b), $b]);

        return array_map(fn (string $job) => static::job($job, $dispatched[$job] ?? null, $ran[$job] ?? [], $records->trace), $jobs);
    }

    /**
     * Get the lineage of one job, named after its dispatch, or its first attempt when it has none.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    protected static function job(string $job, ?array $dispatch, array $attempts, ?string $trace): array
    {
        $state = match (true) {
            $dispatch === null => LineageState::NO_DISPATCH,
            $attempts === [] => LineageState::NO_ATTEMPTS,
            default => LineageState::COMPLETE,
        };

        return [
            'job_id' => $job,
            'name' => ($dispatch ?? $attempts[0])['name'],
            'lineage' => $state->value,
            'outcome' => static::outcome($dispatch, $attempts)?->value,
            'dispatch' => $dispatch === null ? null : static::dispatch($dispatch),
            'attempts' => static::attempts($dispatch, $attempts, $trace),
        ];
    }

    /**
     * Get how the job ended, or null for a dispatch that runs inline.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     */
    protected static function outcome(?array $dispatch, array $attempts): ?JobOutcome
    {
        if ($attempts !== []) {
            return JobOutcome::of($attempts[array_key_last($attempts)]['status']);
        }

        return static::isInline($dispatch) ? null : JobOutcome::PENDING;
    }

    /**
     * Determine if a dispatch is on a connection that runs the job inside the dispatching execution.
     *
     * @param  array<string, mixed>|null  $dispatch
     */
    protected static function isInline(?array $dispatch): bool
    {
        return in_array($dispatch['connection'] ?? null, static::INLINE_CONNECTIONS, true);
    }

    /**
     * Get the SQL condition that holds for a dispatch whose connection, as the given column, runs the job inside the dispatching execution.
     */
    public static function inlineCondition(string $column): string
    {
        $connections = implode(', ', array_map(fn (string $connection) => "'{$connection}'", static::INLINE_CONNECTIONS));

        return "{$column} IN ({$connections})";
    }

    /**
     * Get what a dispatch shows.
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
     * Get the attempts in order, each with the wait before it.
     *
     * @param  array<string, mixed>|null  $dispatch
     * @param  list<array<string, mixed>>  $attempts
     * @param  string|null  $trace  the trace an attempt is not named for
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
                'wait_ms' => static::wait($attempt['started_at'], $waitingFrom),
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
