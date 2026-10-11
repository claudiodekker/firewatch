<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\History;
use ClaudioDekker\Firewatch\Store\Markers;

/**
 * @internal
 */
class Executions
{
    /**
     * Get the SQL of the executions that started in the window, of one group when the call names it.
     */
    public static function withoutCoverage(Window $window, ?string $group): Fragment
    {
        return self::union(Fragment::selecting($window, $group), ExecutionType::records());
    }

    /**
     * Get the SQL of the executions of the types that started in the window, leaving out those whose children of the type lie outside coverage.
     *
     * @param  list<RecordType>  $types
     */
    public static function covered(Markers $meta, RecordType $children, Window $window, ?string $group, array $types): Fragment
    {
        $selected = Fragment::selecting($window, $group);
        $from = History::removedThrough($meta, [$children]);

        return self::union($from === null ? $selected : new Fragment("{$selected->sql} AND started_at >= :covered_from", [
            ...$selected->bindings,
            'covered_from' => $from,
        ]), $types);
    }

    /**
     * Get the SQL of the executions of the types that meet a condition.
     *
     * @param  list<RecordType>  $types
     */
    protected static function union(Fragment $condition, array $types): Fragment
    {
        $branches = array_map(fn (RecordType $type) => sprintf(
            "SELECT id, execution_id, started_at, group_hash, '%s' AS type, '%s' AS source, user_id, %s AS duration, %s AS counted, %s AS peak, %s AS label FROM %s WHERE %s",
            $type->value,
            $type->source(),
            Stored::number('duration'),
            Stored::number('queries'),
            Stored::number('peak_memory_usage'),
            $type->labelColumn(),
            $type->view(),
            $condition->sql,
        ), $types);

        return new Fragment('WITH executions AS ('.implode(' UNION ALL ', $branches).')', $condition->bindings);
    }

    /**
     * Get the SQL of the label of every execution, whenever it started.
     */
    public static function labels(): string
    {
        $branches = array_map(fn (RecordType $type) => sprintf(
            "SELECT id, execution_id, started_at, '%s' AS type, %s AS label FROM %s",
            $type->value,
            $type->labelColumn(),
            $type->view(),
        ), ExecutionType::records());

        return 'WITH labels AS ('.implode(' UNION ALL ', $branches).')';
    }

    /**
     * Get the label of an execution as a finding shows it, or null when the store holds no execution to label.
     */
    public static function label(mixed $type, mixed $label): ?string
    {
        return is_string($type) ? RecordType::from($type)->groupLabel($label) : null;
    }
}
