<?php

namespace ClaudioDekker\Firewatch\Sql\Child;

/**
 * @internal
 */
enum Unavailable: string
{
    case PROC_OPEN_MISSING = 'proc_open_missing';
    case SQLITE3_MISSING = 'sqlite3_missing';
    case PHP_BINARY = 'php_binary';
    case SQLITE_TOO_OLD = 'sqlite_too_old';
    case SPAWN_FAILED = 'spawn_failed';
    case AUTHORIZER = 'authorizer';
    case HEAP_LIMIT = 'heap_limit';
}
