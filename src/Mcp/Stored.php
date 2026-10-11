<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Fetch;
use ClaudioDekker\Firewatch\Store\Microseconds;
use SQLite3;

/**
 * @internal
 */
class Stored
{
    /**
     * The bytes in a megabyte.
     */
    public const MEGABYTE = 1048576;

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
     * @param  array<string, int|float|string|null>  $bindings
     * @param  Window|null  $window  bound as `:since` and `:until`, for a query that names its condition
     * @return list<array<string, mixed>>
     */
    public static function rows(SQLite3 $connection, string $sql, array $bindings = [], ?Window $window = null): array
    {
        return Fetch::rows($connection, $sql, $bindings, $window === null ? null : $window->bind(...));
    }

    /**
     * Read the types that hold a group in the store, in the order of the types with groups.
     *
     * @return list<RecordType>
     */
    public static function holders(SQLite3 $connection, string $group): array
    {
        $rows = self::rows($connection, 'SELECT DISTINCT type FROM records WHERE group_hash = :group', ['group' => $group]);
        $held = array_map(fn (array $row) => is_string($row['type']) ? RecordType::tryFrom($row['type']) : null, $rows);

        return array_values(array_filter(Measure::types(), fn (RecordType $type) => in_array($type, $held, true)));
    }

    /**
     * Get the SQL of a stored value that is a number, which is NULL for any other value.
     */
    public static function number(string $expression): string
    {
        return "CASE WHEN typeof({$expression}) IN ('integer', 'real') THEN {$expression} END";
    }

    /**
     * Get the SQL of the duration a record of the type counts with, which is NULL for a scheduled task that was skipped and never ran.
     */
    public static function duration(RecordType $type): string
    {
        $number = self::number('duration');

        return $type === RecordType::SCHEDULED_TASK ? "CASE WHEN status = '".Outcome::SKIPPED->value."' THEN NULL ELSE {$number} END" : $number;
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
     *
     * @return ($microseconds is int|float ? float : float|null)
     */
    public static function milliseconds(mixed $microseconds): ?float
    {
        return is_numeric($microseconds) ? round($microseconds / Microseconds::PER_MILLISECOND, self::MILLISECOND_DECIMALS) : null;
    }

    /**
     * Get rows by the hash of their group, in the order they came.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    public static function byGroup(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row['group_hash']][] = $row;
        }

        return $groups;
    }

    /**
     * Get bytes as megabytes to one decimal, or null for no number.
     */
    public static function megabytes(mixed $bytes): ?float
    {
        return is_numeric($bytes) ? round($bytes / self::MEGABYTE, self::MEGABYTE_DECIMALS) : null;
    }

    /**
     * Get the bytes of a number of megabytes.
     */
    public static function bytes(int|float $megabytes): int|float
    {
        return $megabytes * self::MEGABYTE;
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
}
