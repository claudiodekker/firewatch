<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * @internal
 */
enum SqlFailureKind: string
{
    case NOT_ALLOWED = 'not_allowed';
    case INVALID_SQL = 'invalid_sql';
    case ABORTED = 'aborted';
    case ROW_TOO_LARGE = 'row_too_large';
    case DEADLINE = 'deadline';
    case MEMORY = 'memory';
    case UNAVAILABLE = 'unavailable';
    case FAILED = 'failed';
}
