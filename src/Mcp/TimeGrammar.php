<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use ClaudioDekker\Firewatch\Configuration\DurationUnit;

/**
 * @internal
 */
class TimeGrammar
{
    /**
     * The last epoch second the grammar reads: 2100-01-01.
     */
    public const MAXIMUM_EPOCH = 4102444800;

    /**
     * The unit of a relative time, by the spellings of the unit.
     *
     * @var array<string, DurationUnit>
     */
    protected const UNITS = [
        's' => DurationUnit::SECOND,
        'sec' => DurationUnit::SECOND,
        'second' => DurationUnit::SECOND,
        'seconds' => DurationUnit::SECOND,
        'm' => DurationUnit::MINUTE,
        'min' => DurationUnit::MINUTE,
        'minute' => DurationUnit::MINUTE,
        'minutes' => DurationUnit::MINUTE,
        'h' => DurationUnit::HOUR,
        'hour' => DurationUnit::HOUR,
        'hours' => DurationUnit::HOUR,
        'd' => DurationUnit::DAY,
        'day' => DurationUnit::DAY,
        'days' => DurationUnit::DAY,
        'w' => DurationUnit::WEEK,
        'week' => DurationUnit::WEEK,
        'weeks' => DurationUnit::WEEK,
    ];

    /**
     * Read a boundary of a window as Unix seconds, or null for a value the grammar does not read.
     */
    public static function parse(mixed $value, CarbonImmutable $now, string $timezone): ?float
    {
        if (is_int($value) || is_float($value)) {
            $value = Instant::epoch($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return match (true) {
            strtolower($value) === 'now' => Instant::of($now),
            preg_match('/^\d+(\.\d+)?$/', $value) === 1 => self::epoch($value),
            preg_match('/^-(\d+) ?([a-z]+)$/i', $value, $relative) === 1 => self::relative($relative, $now),
            preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?$/', $value) === 1 => self::local(value: $value, timezone: $timezone),
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/i', $value) === 1 => self::local(value: $value, timezone: $timezone),
            default => null,
        };
    }

    /**
     * Read epoch seconds, which stop at the last the grammar reads: a millisecond epoch is past it.
     */
    protected static function epoch(string $value): ?float
    {
        $epoch = (float) $value;

        return $epoch <= self::MAXIMUM_EPOCH ? $epoch : null;
    }

    /**
     * Read a count of a unit back from now.
     *
     * @param  array<int, string>  $match
     */
    protected static function relative(array $match, CarbonImmutable $now): ?float
    {
        $unit = self::UNITS[strtolower($match[2])] ?? null;

        if ($unit === null || ltrim($match[1], '0') === '') {
            return null;
        }

        $instant = Instant::of($now) - (float) $match[1] * $unit->seconds();

        return $instant < 0 ? null : $instant;
    }

    /**
     * Read a date or date-time, in the application timezone unless it names its own offset.
     */
    protected static function local(string $value, string $timezone): ?float
    {
        $day = substr($value, 0, 10);

        try {
            $rolledOver = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone)?->format('Y-m-d') !== $day;
            $parsed = CarbonImmutable::parse($value, $timezone);
        } catch (InvalidFormatException) {
            return null;
        }

        // A date PHP rolls over (February 30th) is no date.
        if ($rolledOver) {
            return null;
        }

        return Instant::of($parsed);
    }
}
