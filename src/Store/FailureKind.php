<?php

namespace ClaudioDekker\Firewatch\Store;

use SQLite3Exception;
use Throwable;

/**
 * @internal
 */
enum FailureKind: string
{
    case BUSY = 'busy';
    case FULL = 'full';
    case CORRUPT = 'corrupt';
    case FOREIGN = 'foreign';
    case SCHEMA = 'schema';
    case IO = 'io';
    case OTHER = 'other';

    /**
     * The mask that keeps the primary result code of an extended one, which holds it in its low byte.
     */
    public const PRIMARY_CODE_MASK = 0xFF;

    /**
     * The SQLite result code of a database that is locked by another connection.
     */
    public const SQLITE_BUSY = 5;

    /**
     * The SQLite result code of a disk I/O error.
     */
    public const SQLITE_IOERR = 10;

    /**
     * The SQLite result code of a database file that is malformed.
     */
    public const SQLITE_CORRUPT = 11;

    /**
     * The SQLite result code of a database that is full.
     */
    public const SQLITE_FULL = 13;

    /**
     * The SQLite result code of a database file that can't be opened.
     */
    public const SQLITE_CANTOPEN = 14;

    /**
     * The SQLite result code of a file that is not a database.
     */
    public const SQLITE_NOTADB = 26;

    /**
     * Classify why a batch could not be stored.
     */
    public static function of(Throwable $exception): self
    {
        if ($exception instanceof StoreFailure) {
            return $exception->kind;
        }

        if (! $exception instanceof SQLite3Exception) {
            return self::OTHER;
        }

        return match ($exception->getCode() & self::PRIMARY_CODE_MASK) {
            self::SQLITE_BUSY => self::BUSY,
            self::SQLITE_FULL => self::FULL,
            self::SQLITE_CORRUPT => self::CORRUPT,
            self::SQLITE_NOTADB => self::FOREIGN,
            self::SQLITE_IOERR, self::SQLITE_CANTOPEN => self::IO,
            default => self::OTHER,
        };
    }
}
