<?php

namespace ClaudioDekker\Firewatch\Store;

use JsonException;

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

    /**
     * Get a stored value, or null for the empty string a wire field is sent as when it has no value.
     */
    public static function blank(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * Decode the JSON text a column holds, or keep what is not JSON as it is.
     */
    public static function json(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return self::blank($value);
        }

        try {
            return json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }
}
