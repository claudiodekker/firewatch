<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class TraceRecords
{
    /**
     * Create a new trace records instance.
     *
     * @param  string|null  $trace  the trace asked for, or the one the job asked for started in
     * @param  bool  $held  whether the store holds the id asked for at all
     * @param  list<array<string, mixed>>  $executions  by when they started
     * @param  list<array<string, mixed>>  $dispatches  by when they started
     * @param  list<array<string, mixed>>  $attempts  by when they started
     * @param  array<string, int>  $carrying  the records that carry the trace by type, the most first, when no execution of it is held
     */
    public function __construct(
        public ?string $trace,
        public bool $held,
        public array $executions,
        public array $dispatches,
        public array $attempts,
        public array $carrying,
    ) {
        //
    }

    /**
     * Read what the store holds for a trace.
     */
    public static function ofTrace(SQLite3 $connection, string $trace): static
    {
        $jobs = 'job_id IN (SELECT job_id FROM queued_jobs WHERE trace_id = :trace UNION SELECT job_id FROM job_attempts WHERE trace_id = :trace)';

        return static::build($connection, $trace, static::hasTrace($connection, $trace), $jobs, ['trace' => $trace]);
    }

    /**
     * Read what the store holds for a job.
     */
    public static function ofJob(SQLite3 $connection, string $job): static
    {
        [$trace, $held] = static::start($connection, $job);

        return static::build($connection, $trace, $held, 'job_id = :job', ['job' => $job]);
    }

    /**
     * Read the executions of the trace and the lineage records the condition picks out.
     *
     * @param  array<string, string>  $bindings
     */
    protected static function build(SQLite3 $connection, ?string $trace, bool $held, string $condition, array $bindings): static
    {
        $executions = $trace === null ? [] : static::executions($connection, $trace);

        return new static(
            trace: $trace,
            held: $held,
            executions: $executions,
            dispatches: Stored::rows($connection, "SELECT id, started_at, duration, ended_at, execution_id, job_id, name, connection, queue FROM queued_jobs WHERE {$condition} ORDER BY started_at, id", $bindings),
            attempts: Stored::rows($connection, "SELECT id, started_at, duration, ended_at, trace_id, execution_id, job_id, attempt, name, status FROM job_attempts WHERE {$condition} ORDER BY started_at, attempt, id", $bindings),
            carrying: $executions === [] && $trace !== null ? static::carrying($connection, $trace) : [],
        );
    }

    /**
     * Get the trace a job started in and whether the store holds the job.
     *
     * @return array{string|null, bool}
     */
    protected static function start(SQLite3 $connection, string $job): array
    {
        $dispatch = Stored::rows($connection, 'SELECT trace_id FROM queued_jobs WHERE job_id = :job ORDER BY started_at, id LIMIT 1', ['job' => $job])[0] ?? null;
        $attempt = Stored::rows($connection, 'SELECT trace_id FROM job_attempts WHERE job_id = :job ORDER BY started_at, attempt, id LIMIT 1', ['job' => $job])[0] ?? null;

        return [Stored::blank($dispatch['trace_id'] ?? $attempt['trace_id'] ?? null), $dispatch !== null || $attempt !== null];
    }

    /**
     * Determine if any record in the store carries the value as its trace id.
     */
    protected static function hasTrace(SQLite3 $connection, string $trace): bool
    {
        return Stored::rows($connection, 'SELECT 1 AS held FROM records WHERE trace_id = :trace LIMIT 1', ['trace' => $trace]) !== [];
    }

    /**
     * Read the executions of the trace, in the order they started.
     *
     * @return list<array<string, mixed>>
     */
    protected static function executions(SQLite3 $connection, string $trace): array
    {
        $executions = [];

        foreach (ExecutionType::cases() as $type) {
            $view = RecordType::from($type->value)->view();
            $fields = match ($type) {
                ExecutionType::REQUEST => 'route_path, status_code',
                ExecutionType::COMMAND => 'name, exit_code',
                ExecutionType::JOB_ATTEMPT, ExecutionType::SCHEDULED_TASK => 'name, status',
            };

            foreach (Stored::rows($connection, "SELECT id, started_at, duration, execution_id, source, {$fields} FROM {$view} WHERE trace_id = :trace", ['trace' => $trace]) as $row) {
                $executions[] = [
                    ...$row,
                    'type' => $type->value,
                ];
            }
        }

        usort($executions, fn (array $a, array $b) => [$a['started_at'], $a['id']] <=> [$b['started_at'], $b['id']]);

        return $executions;
    }

    /**
     * Count the records that carry a trace no execution is held for, by type, the most first.
     *
     * @return array<string, int>
     */
    protected static function carrying(SQLite3 $connection, string $trace): array
    {
        $counts = [];

        foreach (Stored::rows($connection, 'SELECT type, count(*) AS records FROM records WHERE trace_id = :trace GROUP BY type ORDER BY records DESC, type', ['trace' => $trace]) as $row) {
            $counts[$row['type']] = $row['records'];
        }

        return $counts;
    }
}
