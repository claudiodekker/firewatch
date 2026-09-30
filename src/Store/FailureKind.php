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
     * Classify why a batch could not be stored, by its SQLite primary result code or the kind the store gave it.
     */
    public static function of(Throwable $exception): self
    {
        if ($exception instanceof StoreFailure) {
            return $exception->kind;
        }

        if (! $exception instanceof SQLite3Exception) {
            return self::OTHER;
        }

        // An extended result code keeps its primary code in its low byte.
        return match ($exception->getCode() & 0xFF) {
            5 => self::BUSY,
            13 => self::FULL,
            11 => self::CORRUPT,
            26 => self::FOREIGN,
            10, 14 => self::IO,
            default => self::OTHER,
        };
    }
}
