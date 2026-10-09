<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Configuration\DurationUnit;

/**
 * @internal
 */
class Markdown
{
    /**
     * Format a number of seconds in the largest of days, hours, minutes and seconds that holds it whole.
     */
    public static function duration(int $seconds): string
    {
        foreach ([DurationUnit::DAY, DurationUnit::HOUR, DurationUnit::MINUTE] as $unit) {
            $length = $unit->seconds();

            if ($seconds >= $length && $seconds % $length === 0) {
                return intdiv($seconds, $length).$unit->value;
            }
        }

        return "{$seconds}s";
    }

    /**
     * Render a value as one table cell or one line.
     */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => self::word('cell_null'),
            $value === true => self::word('cell_yes'),
            $value === false => self::word('cell_no'),
            is_string($value) => $value,
            is_array($value) => json_encode($value, RecordMapper::JSON_FLAGS),
            default => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        };

        return str_replace(['|', "\r\n", "\n", "\r"], ['\|', ' ', ' ', ' '], $text);
    }

    /**
     * Get one word of the language file that a cell is written with.
     */
    protected static function word(string $key): string
    {
        $line = __("firewatch::messages.{$key}");

        return is_string($line) ? $line : $key;
    }

    /**
     * Render same-shaped rows as a table.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function table(array $rows): string
    {
        $columns = array_keys($rows[0]);
        $cells = array_map(fn (array $row) => array_map(fn (string $column) => $row[$column] ?? null, $columns), $rows);

        return self::grid($columns, $cells);
    }

    /**
     * Render rows positional to their columns as a table, so that a duplicate column name keeps its column.
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public static function grid(array $columns, array $rows): string
    {
        $lines = [
            '| '.implode(' | ', array_map(self::cell(...), $columns)).' |',
            '|'.str_repeat(' --- |', count($columns)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(self::cell(...), $row)).' |';
        }

        return implode("\n", $lines);
    }
}
