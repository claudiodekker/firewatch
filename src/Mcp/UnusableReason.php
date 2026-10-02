<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum UnusableReason: string
{
    case FOREIGN_FILE = 'foreign_file';
    case NEWER_SCHEMA = 'newer_schema';
    case OLDER_SCHEMA = 'older_schema';
    case SQLITE_TOO_OLD = 'sqlite_too_old';
    case UNREADABLE = 'unreadable';
}
