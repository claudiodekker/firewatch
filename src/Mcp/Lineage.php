<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\Microseconds;

/**
 * @internal
 */
class Lineage
{
    /**
     * The queue drivers whose jobs leave no attempt of their own.
     *
     * @var list<string>
     */
    protected const INLINE_DRIVERS = [
        'sync',
        'deferred',
        'background',
        'null',
    ];

    /**
     * The connection Nightwatch records no job attempt on, whatever its driver.
     */
    protected const UNRECORDED_CONNECTION = 'sync';

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
     * Determine if a dispatch or an attempt is on the unrecorded connection or on one of an inline driver.
     *
     * @param  array<string, mixed>|null  $record
     */
    protected static function isInline(?array $record): bool
    {
        if (($record['connection'] ?? null) === static::UNRECORDED_CONNECTION) {
            return true;
        }

        return in_array($record['driver'] ?? null, static::INLINE_DRIVERS, true);
    }

    /**
     * Determine if a job ran on a connection that records its dispatch, yet has none.
     */
    public static function lacksDispatch(TraceRecords $records): bool
    {
        $dispatched = array_column($records->dispatches, 'job_id');

        foreach ($records->attempts as $attempt) {
            if (! static::isInline($attempt) && ! in_array($attempt['job_id'], $dispatched, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the SQL condition that holds for a dispatch on an inline connection, given the alias of the rows that hold its driver and connection.
     */
    public static function inlineCondition(string $table): string
    {
        $drivers = implode(', ', array_map(fn (string $driver) => "'{$driver}'", static::INLINE_DRIVERS));
        $unrecorded = static::UNRECORDED_CONNECTION;

        return "({$table}.driver IN ({$drivers}) OR {$table}.connection = '{$unrecorded}')";
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
