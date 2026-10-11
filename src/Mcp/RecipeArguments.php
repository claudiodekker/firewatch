<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * The grammar that reads the `fingerprint` tool's source arguments into the fields a type's recipe reads.
 *
 * @internal
 */
class RecipeArguments
{
    /**
     * The source arguments each type takes.
     */
    public const ARGUMENTS = [
        'request' => ['methods', 'path', 'domain'],
        'command' => ['name'],
        'job-attempt' => ['name'],
        'queued-job' => ['name'],
        'scheduled-task' => ['name', 'cron', 'timezone', 'repeat_seconds'],
        'query' => ['connection', 'sql', 'driver'],
        'cache-event' => ['store', 'key'],
        'outgoing-request' => ['host'],
        'mail' => ['class'],
        'notification' => ['class'],
    ];

    /**
     * The seconds Laravel repeats a task within the minute by (the divisors of 60), and 0 for a task that does not repeat.
     */
    public const REPEAT_SECONDS = [0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30];

    /**
     * The fields the recipes read under another name than the argument that gives them.
     */
    protected const FIELDS = [
        'methods' => 'route_methods',
        'path' => 'route_path',
        'domain' => 'route_domain',
    ];

    /**
     * The source arguments that may be left out.
     */
    protected const OPTIONAL = ['domain', 'timezone', 'repeat_seconds', 'driver'];

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function __construct(
        public readonly array $fields,
        public readonly ?string $driver,
    ) {}

    /**
     * Read the type's source arguments into the fields its recipe reads, refusing any that belong to another type.
     */
    public static function read(Arguments $arguments, RecordType $type): self
    {
        $taken = self::arguments($type);

        foreach ($arguments->names() as $argument) {
            if (! in_array($argument, ['type', 'format'], true) && ! in_array($argument, $taken, true)) {
                throw Refusal::conflicting(argument: $argument, with: "type: {$type->value}", accepted: self::accepted($type), example: self::example($type));
            }
        }

        foreach ($taken as $argument) {
            if ($arguments->get($argument) === null && ! in_array($argument, self::OPTIONAL, true)) {
                throw Refusal::missing($argument, self::accepted($type), self::example($type));
            }
        }

        $fields = self::defaults($type);

        foreach (array_diff($taken, ['driver']) as $argument) {
            $value = $arguments->get($argument);

            if ($value !== null) {
                $fields[self::FIELDS[$argument] ?? $argument] = self::value($arguments, $type, $argument, $value);
            }
        }

        return new self($fields, self::driver($arguments));
    }

    /**
     * Get the timezone Laravel gives a scheduled task that names none: the schedule's when set, even to null, else the application's.
     */
    public static function scheduleTimezone(): string
    {
        $timezone = config('app.schedule_timezone', config('app.timezone'));

        return is_string($timezone) ? $timezone : '';
    }

    /**
     * Get a call of the tool for the type, as a refusal shows it: its required arguments with placeholders.
     */
    public static function example(RecordType $type): string
    {
        $arguments = ['type' => $type->value];

        foreach (array_diff(self::arguments($type), self::OPTIONAL) as $argument) {
            $arguments[$argument] = $argument === 'methods' ? ['GET', 'HEAD'] : "<{$argument}>";
        }

        return Call::written('fingerprint', $arguments);
    }

    /**
     * @return list<string>
     */
    protected static function arguments(RecordType $type): array
    {
        return self::ARGUMENTS[$type->value] ?? [];
    }

    /**
     * Get the type's arguments as a refusal lists them, such as "methods, path, domain (optional)".
     */
    protected static function accepted(RecordType $type): string
    {
        $arguments = array_map(
            fn (string $argument) => in_array($argument, self::OPTIONAL, true) ? "{$argument} (optional)" : $argument,
            self::arguments($type),
        );

        return implode(', ', $arguments);
    }

    /**
     * Get the fields an optional argument gives when it is left out.
     *
     * @return array<string, mixed>
     */
    protected static function defaults(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => ['route_domain' => ''],
            RecordType::SCHEDULED_TASK => [
                'timezone' => self::scheduleTimezone(),
                'repeat_seconds' => 0,
            ],
            default => [],
        };
    }

    /**
     * Read the driver of a query's connection, which picks one reading of its SQL.
     */
    protected static function driver(Arguments $arguments): ?string
    {
        $value = $arguments->get('driver');

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw $arguments->invalid(argument: 'driver', expected: 'a non-empty string', accepted: 'a driver name, such as "mysql"', example: self::example(RecordType::QUERY));
        }

        return strtolower($value);
    }

    protected static function value(Arguments $arguments, RecordType $type, string $argument, mixed $value): mixed
    {
        return match ($argument) {
            'methods' => self::methods($arguments, $type, $value),
            'repeat_seconds' => self::repeatSeconds($arguments, $type, $value),
            default => self::textField($arguments, $type, $argument, $value),
        };
    }

    /**
     * Read the route methods as Nightwatch stores them: sorted, with HEAD wherever GET is, as Laravel registers every GET route.
     *
     * @return list<string>
     */
    protected static function methods(Arguments $arguments, RecordType $type, mixed $value): array
    {
        if (! self::isMethods($value)) {
            throw $arguments->invalid(argument: 'methods', expected: 'a non-empty list of uppercase HTTP methods', accepted: 'uppercase HTTP methods, such as ["GET","HEAD"]', example: self::example($type));
        }

        $methods = array_values(array_unique(in_array('GET', $value, true) ? [...$value, 'HEAD'] : $value));

        sort($methods);

        return $methods;
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    protected static function isMethods(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        return array_filter($value, fn (mixed $method) => is_string($method) && preg_match('/^[A-Z]+$/', $method) === 1) === $value;
    }

    /**
     * Read the repeat seconds of a task: 0, or a number of seconds that divides the minute.
     */
    protected static function repeatSeconds(Arguments $arguments, RecordType $type, mixed $value): int
    {
        if (! is_int($value) || ! in_array($value, self::REPEAT_SECONDS, true)) {
            $allowed = self::REPEAT_SECONDS;
            $last = array_pop($allowed);

            throw $arguments->invalid(argument: 'repeat_seconds', expected: 'a whole number of seconds that divides the minute', accepted: implode(', ', $allowed)." or {$last}", example: self::example($type));
        }

        return $value;
    }

    /**
     * Read a text argument as the field its recipe reads: a path trimmed to one leading slash, a domain without its scheme.
     */
    protected static function textField(Arguments $arguments, RecordType $type, string $argument, mixed $value): string
    {
        $text = self::text($arguments, $type, $argument, $value);

        return match ($argument) {
            'path' => '/'.trim($text, '/'),
            'domain' => str_replace(['http://', 'https://'], '', $text),
            default => $text,
        };
    }

    protected static function text(Arguments $arguments, RecordType $type, string $argument, mixed $value): string
    {
        if (! is_string($value)) {
            throw $arguments->invalid(argument: $argument, expected: 'a string', accepted: 'a string', example: self::example($type));
        }

        return $value;
    }
}
