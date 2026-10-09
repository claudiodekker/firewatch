<?php

namespace ClaudioDekker\Firewatch\Sql\Child;

use SQLite3;

/**
 * The closed policy of the SQL child: what a statement may be, read and call.
 *
 * @internal
 */
class Policy
{
    /**
     * The most bytes of SQL a statement may have.
     */
    public const SQL_BYTES = 16384;

    /**
     * The name a denied action is refused with, by code; a code missing here is refused by its number.
     */
    protected const ACTION_NAMES = [
        SQLite3::CREATE_INDEX => 'CREATE INDEX',
        SQLite3::CREATE_TABLE => 'CREATE TABLE',
        SQLite3::CREATE_TEMP_INDEX => 'CREATE TEMP INDEX',
        SQLite3::CREATE_TEMP_TABLE => 'CREATE TEMP TABLE',
        SQLite3::CREATE_TEMP_TRIGGER => 'CREATE TEMP TRIGGER',
        SQLite3::CREATE_TEMP_VIEW => 'CREATE TEMP VIEW',
        SQLite3::CREATE_TRIGGER => 'CREATE TRIGGER',
        SQLite3::CREATE_VIEW => 'CREATE VIEW',
        SQLite3::DELETE => 'DELETE',
        SQLite3::DROP_INDEX => 'DROP INDEX',
        SQLite3::DROP_TABLE => 'DROP TABLE',
        SQLite3::DROP_TEMP_INDEX => 'DROP TEMP INDEX',
        SQLite3::DROP_TEMP_TABLE => 'DROP TEMP TABLE',
        SQLite3::DROP_TEMP_TRIGGER => 'DROP TEMP TRIGGER',
        SQLite3::DROP_TEMP_VIEW => 'DROP TEMP VIEW',
        SQLite3::DROP_TRIGGER => 'DROP TRIGGER',
        SQLite3::DROP_VIEW => 'DROP VIEW',
        SQLite3::INSERT => 'INSERT',
        SQLite3::PRAGMA => 'PRAGMA',
        SQLite3::TRANSACTION => 'TRANSACTION',
        SQLite3::UPDATE => 'UPDATE',
        SQLite3::ATTACH => 'ATTACH',
        SQLite3::DETACH => 'DETACH',
        SQLite3::ALTER_TABLE => 'ALTER TABLE',
        SQLite3::REINDEX => 'REINDEX',
        SQLite3::ANALYZE => 'ANALYZE',
        SQLite3::CREATE_VTABLE => 'CREATE VIRTUAL TABLE',
        SQLite3::DROP_VTABLE => 'DROP VIRTUAL TABLE',
        SQLite3::SAVEPOINT => 'SAVEPOINT',
    ];

    /**
     * The objects a statement may read: the record views, the raw tables and the JSON table-valued functions.
     */
    public const READABLE = [
        'requests',
        'commands',
        'job_attempts',
        'scheduled_tasks',
        'queries',
        'exceptions',
        'logs',
        'cache_events',
        'mail',
        'notifications',
        'outgoing_requests',
        'queued_jobs',
        'records',
        'users',
        'drift',
        'meta',
        'json_each',
        'json_tree',
    ];

    /**
     * The functions a statement may call, lowercased, with the operators SQLite reports as functions.
     */
    public const FUNCTIONS = [
        'json', 'json_array', 'json_array_length', 'json_extract', 'json_insert', 'json_object', 'json_patch', 'json_remove',
        'json_replace', 'json_set', 'json_type', 'json_valid', 'json_quote', 'json_group_array', 'json_group_object',
        'json_each', 'json_tree', '->', '->>',
        'count', 'sum', 'avg', 'total', 'min', 'max', 'group_concat', 'string_agg',
        'abs', 'round', 'sign', 'ceil', 'ceiling', 'floor', 'trunc', 'mod', 'pow', 'power', 'sqrt', 'exp', 'ln', 'log', 'log2', 'log10',
        'length', 'lower', 'upper', 'trim', 'ltrim', 'rtrim', 'substr', 'substring', 'instr', 'replace', 'like', 'glob',
        'unicode', 'concat', 'concat_ws',
        'coalesce', 'ifnull', 'iif', 'nullif', 'typeof', 'likely', 'unlikely', 'likelihood',
        'row_number', 'rank', 'dense_rank', 'percent_rank', 'cume_dist', 'ntile', 'lag', 'lead', 'first_value', 'last_value', 'nth_value',
        'date', 'time', 'datetime', 'julianday', 'unixepoch', 'strftime',
    ];

    /**
     * Get why the SQL text is refused before anything compiles it, or null when it holds one statement of an acceptable size.
     */
    public static function screen(string $sql): ?Denied
    {
        if (strlen($sql) > self::SQL_BYTES) {
            return Denied::TOO_LONG;
        }

        if (str_contains($sql, "\0")) {
            return Denied::NUL;
        }

        return match (self::statements($sql)) {
            0 => Denied::NO_COLUMNS,
            1 => null,
            default => Denied::SECOND_STATEMENT,
        };
    }

    /**
     * Decide one call of the authorizer: null allows it, otherwise what is denied and the name it is refused by.
     *
     * @param  string|null  $first  the table of a read
     * @param  string|null  $second  the column of a read, the function of a call
     * @param  list<string>  $objects  the lowercased names of the tables, views and virtual table modules the store knows
     * @return array{Denied, string}|null
     */
    public static function authorize(int $action, ?string $first, ?string $second, array $objects = []): ?array
    {
        $table = strtolower((string) $first);

        return match ($action) {
            SQLite3::SELECT, SQLite3::RECURSIVE => null,
            SQLite3::READ => in_array($table, self::READABLE, true) || self::isOwnTable($table, $second, $objects) ? null : [Denied::TABLE, (string) $first],
            SQLite3::FUNCTION => in_array(strtolower((string) $second), self::FUNCTIONS, true) ? null : [Denied::FUNCTION, strtolower((string) $second)],
            default => [Denied::ACTION, self::ACTION_NAMES[$action] ?? (string) $action],
        };
    }

    /**
     * Determine if a read is of a common table expression of the statement itself.
     *
     * SQLite asks about one only when it counts the rows and reads no column, under the name the statement gave it.
     *
     * @param  list<string>  $objects
     */
    protected static function isOwnTable(string $table, ?string $column, array $objects): bool
    {
        return $column === ''
            && ! in_array($table, $objects, true)
            && ! str_starts_with($table, 'sqlite_')
            && ! str_starts_with($table, 'pragma_');
    }

    /**
     * Count the statements of the text, up to two, skipping what can hide a `;`.
     */
    protected static function statements(string $sql): int
    {
        $statements = 0;
        $inside = false;
        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            $byte = $sql[$offset];
            $pair = substr($sql, $offset, 2);

            if ($pair === '--' || $pair === '/*') {
                $offset = self::after($sql, $offset + 2, $pair === '--' ? "\n" : '*/');

                continue;
            }

            if ($byte === ';') {
                $inside = false;
                $offset++;

                continue;
            }

            if (ctype_space($byte)) {
                $offset++;

                continue;
            }

            if (! $inside && ++$statements === 2) {
                return $statements;
            }

            $inside = true;

            $offset = match ($byte) {
                "'", '"', '`' => self::after($sql, $offset + 1, $byte),
                '[' => self::after($sql, $offset + 1, ']'),
                default => $offset + 1,
            };
        }

        return $statements;
    }

    /**
     * Get the offset just past the next closer at or after the offset, or the length of the text when there is none.
     */
    protected static function after(string $sql, int $offset, string $closer): int
    {
        $found = strpos($sql, $closer, min($offset, strlen($sql)));

        return $found === false ? strlen($sql) : $found + strlen($closer);
    }

    /**
     * Get a value as the protocol carries it: raw, except a blob and an infinite float, which JSON can't hold.
     */
    public static function cell(mixed $value, bool $blob): int|float|string|null
    {
        if ($blob) {
            return '<blob '.strlen((string) $value).' bytes>';
        }

        if (is_float($value) && is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }
}
