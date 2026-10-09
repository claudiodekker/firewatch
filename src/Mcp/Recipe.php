<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\Truncator;
use ClaudioDekker\Firewatch\RecordType;
use LogicException;

/**
 * Nightwatch's group recipes.
 *
 * @internal
 */
class Recipe
{
    /**
     * The drivers whose SQL Nightwatch normalises before it hashes a query.
     */
    public const NORMALISING_DRIVERS = ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'singlestore'];

    /**
     * The text fields each type's recipe reads.
     */
    protected const FIELDS = [
        'request' => ['route_domain', 'route_path'],
        'command' => ['name'],
        'job-attempt' => ['name'],
        'queued-job' => ['name'],
        'scheduled-task' => ['name', 'cron', 'timezone'],
        'query' => ['connection', 'sql'],
        'cache-event' => ['store', 'key'],
        'outgoing-request' => ['host'],
        'mail' => ['class'],
        'notification' => ['class'],
    ];

    /**
     * The recipe fields Nightwatch stores cut to 255 bytes after it hashed the whole value.
     */
    protected const TINY_TEXT = ['connection', 'store', 'key', 'host', 'class'];

    /**
     * The bytes Nightwatch cuts a tiny text field to.
     */
    protected const TINY_TEXT_BYTES = 255;

    /**
     * Get the group hashes Nightwatch gives a record of the type with these fields, one per reading the fields leave open.
     *
     * @param  array<string, mixed>  $fields
     * @return list<array{group: string, input: string, assumption: string|null}>
     */
    public static function candidates(RecordType $type, array $fields, ?string $driver = null): array
    {
        return match ($type) {
            RecordType::REQUEST => [static::candidate(static::request($fields))],
            RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB => [static::candidate(static::text($fields, 'name'))],
            RecordType::SCHEDULED_TASK => [static::candidate(static::task($fields))],
            RecordType::QUERY => static::query($fields, $driver),
            RecordType::CACHE_EVENT => [static::candidate(static::text($fields, 'store').','.static::text($fields, 'key'))],
            RecordType::OUTGOING_REQUEST => [static::candidate(static::text($fields, 'host'))],
            RecordType::MAIL, RecordType::NOTIFICATION => [static::candidate(static::text($fields, 'class'))],
            RecordType::EXCEPTION, RecordType::LOG, RecordType::USER => throw new LogicException("A {$type->value} has no group recipe."),
        };
    }

    /**
     * Determine if a stored record still holds the whole of every field its recipe hashed.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function isWhole(RecordType $type, array $fields): bool
    {
        foreach (self::FIELDS[$type->value] ?? [] as $field) {
            $value = static::text($fields, $field);

            $tiny = in_array($field, self::TINY_TEXT, true) && strlen($value) >= self::TINY_TEXT_BYTES;

            if ($tiny || Truncator::wasCut($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hash a request by its sorted route methods, its domain and its path.
     *
     * @param  array<string, mixed>  $fields
     */
    protected static function request(array $fields): string
    {
        $methods = is_array($fields['route_methods'] ?? null) ? array_filter($fields['route_methods'], is_string(...)) : [];

        return implode('|', $methods).','.static::text($fields, 'route_domain').','.static::text($fields, 'route_path');
    }

    /**
     * Hash a scheduled task by its name, cron and timezone, and its repeat seconds when it repeats within the minute.
     *
     * @param  array<string, mixed>  $fields
     */
    protected static function task(array $fields): string
    {
        $input = static::text($fields, 'name').','.static::text($fields, 'cron').','.static::text($fields, 'timezone');
        $repeat = $fields['repeat_seconds'] ?? 0;

        return is_int($repeat) && $repeat > 0 ? "{$input},{$repeat}" : $input;
    }

    /**
     * Get the inputs a query hashes to.
     *
     * @param  array<string, mixed>  $fields
     * @return list<array{group: string, input: string, assumption: string|null}>
     */
    protected static function query(array $fields, ?string $driver): array
    {
        $connection = static::text($fields, 'connection');
        $sql = static::text($fields, 'sql');
        $written = "{$connection},{$sql}";
        $normalised = "{$connection},".static::normalise($sql);

        if ($driver !== null) {
            return [static::candidate(in_array($driver, self::NORMALISING_DRIVERS, true) ? $normalised : $written)];
        }

        if ($normalised === $written) {
            return [static::candidate($written)];
        }

        return [
            static::candidate($normalised, __('firewatch::messages.fingerprint_assumption_normalised')),
            static::candidate($written, __('firewatch::messages.fingerprint_assumption_written')),
        ];
    }

    /**
     * Get the candidate an input hashes to, with the assumption it rests on.
     *
     * @return array{group: string, input: string, assumption: string|null}
     */
    protected static function candidate(string $input, mixed $assumption = null): array
    {
        return [
            'group' => hash('xxh128', $input),
            'input' => $input,
            'assumption' => is_string($assumption) ? $assumption : null,
        ];
    }

    /**
     * Normalise SQL as Nightwatch does: a list of placeholders becomes `in (...?)`, and an insert's values `values ...`.
     */
    protected static function normalise(string $sql): string
    {
        $sql = preg_replace('/in \([\d?\s,]+\)/', 'in (...?)', $sql) ?? $sql;

        if (str_contains($sql, 'insert')) {
            $sql = preg_replace('/values [(?,\s)]+/', 'values ...', $sql) ?? $sql;
        }

        return $sql;
    }

    /**
     * Read a field as the text Nightwatch interpolates.
     *
     * @param  array<string, mixed>  $fields
     */
    protected static function text(array $fields, string $field): string
    {
        $value = $fields[$field] ?? '';

        return is_string($value) ? $value : '';
    }
}
