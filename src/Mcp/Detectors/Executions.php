<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class Executions
{
    /**
     * The four execution types.
     *
     * @var list<RecordType>
     */
    public const TYPES = [RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK];

    /**
     * Get the SQL of the executions that started in the window.
     */
    public static function table(Window $window): string
    {
        $selected = $window->condition().' AND (:group = \'\' OR group_hash = :group)';
        $branches = array_map(fn (RecordType $type) => sprintf(
            "SELECT id, execution_id, started_at, group_hash, '%s' AS source, user_id, %s AS duration, %s AS counted, %s AS peak, %s AS label FROM %s WHERE %s",
            $type->source(),
            Stored::number('duration'),
            Stored::number('queries'),
            Stored::number('peak_memory_usage'),
            $type === RecordType::REQUEST ? 'route_path' : 'name',
            $type->view(),
            $selected,
        ), self::TYPES);

        return 'WITH executions AS ('.implode(' UNION ALL ', $branches).')';
    }
}
