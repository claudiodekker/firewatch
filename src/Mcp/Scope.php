<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\TestRecord;
use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
enum Scope: string
{
    case EXECUTIONS = 'executions';
    case BETWEEN = 'between';
    case ALL = 'all';

    /**
     * Get the SQL condition that holds for the records of the scope, read from the raw table or from the view of a type.
     */
    public function condition(?RecordType $from = null): string
    {
        $column = $from === null || $from->source() !== null ? 'source' : 'execution_source';
        $between = "'".TestRecord::BETWEEN_SOURCE."'";

        return match ($this) {
            self::EXECUTIONS => "{$column} IS NOT {$between}",
            self::BETWEEN => "{$column} = {$between}",
            self::ALL => '1',
        };
    }

    /**
     * Get the SQL of the side a record is on, as the value of its scope, read from the raw table or from the view of a type.
     */
    public static function side(?RecordType $from = null): string
    {
        return 'CASE WHEN '.self::BETWEEN->condition($from)." THEN '".self::BETWEEN->value."' ELSE '".self::EXECUTIONS->value."' END";
    }

    /**
     * Determine if records of the type can be in the scope, which an execution never is between executions.
     */
    public function holds(RecordType $type): bool
    {
        return $this !== self::BETWEEN || ! $type->isExecution();
    }
}
