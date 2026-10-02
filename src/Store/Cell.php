<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
class Cell
{
    /**
     * Read a value SQLite or the filesystem gave as a whole number, or zero for one that is not.
     */
    public static function integer(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /**
     * Read a value SQLite gave as a number of seconds, or zero for one that is not.
     */
    public static function float(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
