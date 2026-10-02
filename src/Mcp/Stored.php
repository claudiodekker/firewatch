<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\Microseconds;
use JsonException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Stored
{
    /**
     * The bytes in a megabyte.
     */
    protected const MEGABYTE = 1048576;

    /**
     * The decimals of a millisecond in an answer.
     */
    protected const MILLISECOND_DECIMALS = 2;

    /**
     * The decimals of a megabyte in an answer.
     */
    protected const MEGABYTE_DECIMALS = 1;

    /**
     * Read the rows of a query in the snapshot of the connection.
     *
     * @param  array<string, int|float|string>  $bindings
     * @return list<array<string, mixed>>
     */
    public static function rows(SQLite3 $connection, string $sql, array $bindings = []): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        foreach ($bindings as $name => $value) {
            $statement->bindValue(":{$name}", $value);
        }

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $rows = [];

        while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get a stored value, or null for the empty string a wire field is sent as when it has no value.
     */
    public static function blank(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    /**
     * Get a stored boolean, which SQLite returns as 1 or 0, or null for one that was not recorded.
     */
    public static function flag(mixed $value): ?bool
    {
        return is_int($value) ? $value === 1 : null;
    }

    /**
     * Get microseconds as milliseconds to two decimals, or null for no number.
     */
    public static function milliseconds(mixed $microseconds): ?float
    {
        return is_numeric($microseconds) ? round($microseconds / Microseconds::PER_MILLISECOND, self::MILLISECOND_DECIMALS) : null;
    }

    /**
     * Get bytes as megabytes to one decimal, or null for no number.
     */
    public static function megabytes(mixed $bytes): ?float
    {
        return is_numeric($bytes) ? round($bytes / self::MEGABYTE, self::MEGABYTE_DECIMALS) : null;
    }

    /**
     * Get the place in a file a record names, or null for a record with no file.
     */
    public static function location(mixed $file, mixed $line): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        return is_int($line) ? "{$file}:{$line}" : $file;
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
