<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum ErrorCode: string
{
    case MISSING_ARGUMENT = 'missing_argument';
    case INVALID_ARGUMENT = 'invalid_argument';
    case CONFLICTING_ARGUMENTS = 'conflicting_arguments';
    case UNREADABLE_TIME = 'unreadable_time';
    case EMPTY_WINDOW = 'empty_window';
    case SPLIT_OUTSIDE_WINDOW = 'split_outside_window';
    case NOT_FOUND = 'not_found';
    case BAD_CURSOR = 'bad_cursor';
    case INTERNAL = 'internal';
    case NOT_ALLOWED = 'not_allowed';
    case INVALID_SQL = 'invalid_sql';
    case ABORTED = 'aborted';
    case ROW_TOO_LARGE = 'row_too_large';
    case UNAVAILABLE = 'unavailable';
    case FAILED = 'failed';
}
