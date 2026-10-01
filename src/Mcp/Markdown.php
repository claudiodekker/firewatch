<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\RecordMapper;

/**
 * @internal
 */
class Markdown
{
    /**
     * Render a value as one table cell or one line: null as n/a, a boolean as yes or no, a list or object as compact JSON, with pipes escaped and line breaks flattened.
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
     * Render same-shaped rows as a table, which states each column name once.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function table(array $rows): string
    {
        $columns = array_keys($rows[0]);

        $lines = [
            '| '.implode(' | ', array_map(self::cell(...), $columns)).' |',
            '|'.str_repeat(' --- |', count($columns)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn (string $column) => self::cell($row[$column] ?? null), $columns)).' |';
        }

        return implode("\n", $lines);
    }
}
