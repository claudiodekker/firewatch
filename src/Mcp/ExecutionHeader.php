<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class ExecutionHeader
{
    /**
     * Get the header of an execution.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function of(RecordType $type, array $row): array
    {
        return [
            'type' => $type->value,
            'execution_id' => $row['execution_id'],
            'trace_id' => $row['trace_id'],
            'source' => $row['source'],
            'group' => $row['group_hash'],
            'label' => self::label($type, $row),
            'outcome' => self::outcome($type, $row),
            'started_at' => $row['started_at'],
            'duration_ms' => Stored::milliseconds($row['duration']),
            'stages' => self::stages($type, $row),
            'peak_memory_mb' => Stored::megabytes($row['peak_memory_usage'] ?? null),
            'user_id' => Stored::blank($row['user_id']),
            'deploy' => Stored::blank($row['deploy']),
            'server' => Stored::blank($row['server']),
        ];
    }

    /**
     * Get the stage names of the types that record them, in the order they run.
     *
     * @return list<string>
     */
    protected static function stageNames(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => ['bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating'],
            RecordType::COMMAND => ['bootstrap', 'action', 'terminating'],
            default => [],
        };
    }

    /**
     * Get the display field a group is labelled by.
     *
     * @param  array<string, mixed>  $row
     */
    public static function label(RecordType $type, array $row): mixed
    {
        if ($type !== RecordType::REQUEST) {
            return $row['name'] ?? null;
        }

        return Stored::blank($row['route_path'] ?? null) ?? __('firewatch::messages.rank_no_route');
    }

    /**
     * Get how the execution ended.
     *
     * @param  array<string, mixed>  $row
     */
    public static function outcome(RecordType $type, array $row): mixed
    {
        return match ($type) {
            RecordType::REQUEST => $row['status_code'] ?? null,
            RecordType::COMMAND => $row['exit_code'] ?? null,
            default => $row['status'] ?? null,
        };
    }

    /**
     * Get the milliseconds each stage took by its name, or null for a type that records no stages.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float|null>|null
     */
    protected static function stages(RecordType $type, array $row): ?array
    {
        $names = self::stageNames($type);

        if ($names === []) {
            return null;
        }

        return array_combine($names, array_map(fn (string $name) => Stored::milliseconds($row[$name] ?? null), $names));
    }
}
