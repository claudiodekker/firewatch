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
        'exception' => ['class', 'file', 'code'],
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
     * @param  list<array<string, mixed>>  $sites
     * @return list<array{group: string, input: string, assumption: string|null}>
     */
    public static function candidates(RecordType $type, array $fields, ?string $driver = null, array $sites = []): array
    {
        return match ($type) {
            RecordType::REQUEST => [static::candidate(static::request($fields))],
            RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB => [static::candidate(static::text($fields, 'name'))],
            RecordType::SCHEDULED_TASK => [static::candidate(static::task($fields))],
            RecordType::QUERY => static::query($fields, $driver),
            RecordType::CACHE_EVENT => [static::candidate(static::text($fields, 'store').','.static::text($fields, 'key'))],
            RecordType::OUTGOING_REQUEST => [static::candidate(static::text($fields, 'host'))],
            RecordType::MAIL, RecordType::NOTIFICATION => [static::candidate(static::text($fields, 'class'))],
            RecordType::EXCEPTION => static::exception($fields, $sites),
            RecordType::LOG, RecordType::USER => throw new LogicException("A {$type->value} has no group recipe."),
        };
    }

    /**
     * Determine if a file is a Blade template, which Nightwatch names an exception thrown while rendering it by.
     */
    public static function isTemplate(string $file): bool
    {
        return str_ends_with($file, '.blade.php');
    }

    /**
     * Determine if a file is one Nightwatch looks past for the first application frame of an exception's trace.
     */
    public static function isVendor(string $file): bool
    {
        $path = str_replace('\\', '/', $file);

        return str_starts_with($path, 'vendor/') || in_array($path, ['artisan', 'public/index.php'], true);
    }

    /**
     * Get a file read in source, with either separator, as Nightwatch stores it on a host with the given separator.
     */
    public static function hostFile(string $file, string $separator = DIRECTORY_SEPARATOR): string
    {
        return str_replace(['/', '\\'], $separator, $file);
    }

    /**
     * Get how a template's file begins when it was rendered inside another template.
     */
    public static function nestedPrefix(string $file): string
    {
        return "{$file}) (View: ";
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
     * Hash an exception by its class, code, file and line, once for every reading of each the fields leave open.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $sites
     * @return list<array{group: string, input: string, assumption: string|null}>
     */
    protected static function exception(array $fields, array $sites): array
    {
        $class = static::text($fields, 'class');
        $candidates = [];

        foreach (static::files($fields, $sites) as [$file, $fileAssumption]) {
            foreach (static::lines($fields) as [$line, $lineAssumption]) {
                foreach (static::codes($fields, $sites) as [$code, $codeAssumption]) {
                    $assumptions = array_filter([$fileAssumption, $lineAssumption, $codeAssumption], is_string(...));

                    $candidates[] = static::candidate("{$class},{$code},{$file},{$line}", $assumptions === [] ? null : implode('; ', $assumptions));
                }
            }
        }

        return $candidates;
    }

    /**
     * Get the files an exception may be grouped by: the file given, and for a template the nested renders of it the store holds.
     *
     * Laravel appends a "(View: <template>)" to the message for every template the throw unwinds through, and Nightwatch takes the file from the first one to the end (`laravel/nightwatch` `src/Location.php:116`).
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $sites
     * @return list<array{string, mixed}>
     */
    protected static function files(array $fields, array $sites): array
    {
        $file = static::text($fields, 'file');
        $readings = [[$file, null]];

        if (! static::isTemplate($file)) {
            return $readings;
        }

        foreach (array_unique(array_map(fn (array $row) => static::text($row, 'file'), $sites)) as $held) {
            if (str_starts_with($held, static::nestedPrefix($file))) {
                $readings[] = [$held, __('firewatch::messages.fingerprint_assumption_nested')];
            }
        }

        return $readings;
    }

    /**
     * Get the lines an exception may be grouped by: none for a template unless Ignition kept its line, and none or 0 for a stored 0.
     *
     * @param  array<string, mixed>  $fields
     * @return list<array{string, mixed}>
     */
    protected static function lines(array $fields): array
    {
        $line = $fields['line'] ?? null;

        return match (true) {
            ! is_int($line) => [['', null]],
            $line === 0 => [['', __('firewatch::messages.fingerprint_assumption_no_line')], ['0', __('firewatch::messages.fingerprint_assumption_line_zero')]],
            static::isTemplate(static::text($fields, 'file')) => [['', __('firewatch::messages.fingerprint_assumption_view')], [(string) $line, __('firewatch::messages.fingerprint_assumption_ignition')]],
            default => [[(string) $line, null]],
        };
    }

    /**
     * Get the codes an exception may be grouped by: the code given, else 0 and each code the store holds at the file.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $sites
     * @return list<array{string, mixed}>
     */
    protected static function codes(array $fields, array $sites): array
    {
        $code = $fields['code'] ?? null;

        if (is_string($code)) {
            return [[$code, null]];
        }

        $held = array_diff(array_unique(array_map(fn (array $row) => static::text($row, 'code'), $sites)), ['0']);

        return [
            ['0', __('firewatch::messages.fingerprint_assumption_code_zero')],
            ...array_map(fn (string $code) => [$code, __('firewatch::messages.fingerprint_assumption_code_held', ['code' => $code])], array_values($held)),
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
