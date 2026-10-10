<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class Executions
{
    /**
     * Get the SQL of the executions that started in the window, of one group when the call names it.
     */
    public static function table(Window $window, ?string $group): Fragment
    {
        $selected = Fragment::selecting($window, $group);
        $branches = array_map(fn (RecordType $type) => sprintf(
            "SELECT id, execution_id, started_at, group_hash, '%s' AS source, user_id, %s AS duration, %s AS counted, %s AS peak, %s AS label FROM %s WHERE %s",
            $type->source(),
            Stored::number('duration'),
            Stored::number('queries'),
            Stored::number('peak_memory_usage'),
            self::labelField($type),
            $type->view(),
            $selected->sql,
        ), ExecutionType::records());

        return new Fragment('WITH executions AS ('.implode(' UNION ALL ', $branches).')', $selected->bindings);
    }

    /**
     * Get the SQL of the label of every execution, whenever it started.
     */
    public static function labels(): string
    {
        $branches = array_map(fn (RecordType $type) => sprintf(
            'SELECT id, execution_id, started_at, %s AS label FROM %s',
            self::labelField($type),
            $type->view(),
        ), ExecutionType::records());

        return 'WITH labels AS ('.implode(' UNION ALL ', $branches).')';
    }

    /**
     * Get the label of an execution as a finding shows it, or null when the store holds no execution to label.
     */
    public static function label(mixed $label): mixed
    {
        return $label === null ? null : (Stored::blank($label) ?? __('firewatch::messages.rank_no_route'));
    }

    /**
     * Get the column the executions of a type are labelled by.
     */
    protected static function labelField(RecordType $type): string
    {
        return $type === RecordType::REQUEST ? 'route_path' : 'name';
    }
}
