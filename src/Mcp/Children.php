<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class Children
{
    /**
     * Get the record types of the children an execution has.
     *
     * @return list<RecordType>
     */
    public static function types(): array
    {
        return array_map(fn (Counter $counter) => $counter->type(), Counter::cases());
    }

    /**
     * Read every child of an execution, by when it started and then by its store order.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(SQLite3 $connection, string $executionId): array
    {
        $children = [];

        foreach (self::types() as $type) {
            foreach (Stored::rows($connection, "SELECT * FROM {$type->view()} WHERE execution_id = :id", ['id' => $executionId]) as $row) {
                $children[] = [
                    ...$row,
                    'type' => $type->value,
                ];
            }
        }

        usort($children, fn (array $first, array $second) => [$first['started_at'], $first['id']] <=> [$second['started_at'], $second['id']]);

        return $children;
    }
}
