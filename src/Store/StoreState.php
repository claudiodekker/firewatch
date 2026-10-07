<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum StoreState: string
{
    case ABSENT = 'absent';
    case SCHEMA_MISMATCH = 'schema_mismatch';
    case CORRUPT = 'corrupt';
    case FOREIGN = 'foreign';
    case BUSY = 'busy';
    case UNAVAILABLE = 'unavailable';

    /**
     * Determine if a drop can rebuild a store in this state.
     */
    public function isRebuildable(): bool
    {
        return match ($this) {
            self::SCHEMA_MISMATCH, self::CORRUPT => true,
            default => false,
        };
    }
}
