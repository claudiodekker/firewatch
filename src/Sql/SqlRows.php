<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class SqlRows
{
    /**
     * Create a new SQL rows instance.
     *
     * @param  list<string>  $columns  in statement order, duplicate names kept
     * @param  list<list<int|float|string|CutText|null>>  $rows  positional to the columns
     * @param  list<RecordType>  $typesRead  the record types the authorizer saw the statement read
     * @param  int  $elapsedMilliseconds  from spawn to the last line
     * @param  string|null  $detail  the child's stderr of an aborted stop, SQLite's message of an error stop
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly QueryStop $stop,
        public readonly array $typesRead,
        public readonly int $elapsedMilliseconds,
        public readonly ?string $detail = null,
    ) {
        //
    }
}
