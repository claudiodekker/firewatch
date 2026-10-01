<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Carbon\CarbonImmutable;
use Throwable;

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
     * The seconds in a unit of a relative time, by the spellings of the unit.
     *
     * @var array<string, int>
     */
    protected const UNITS = [
        's' => 1, 'sec' => 1, 'second' => 1, 'seconds' => 1,
        'm' => 60, 'min' => 60, 'minute' => 60, 'minutes' => 60,
        'h' => 3600, 'hour' => 3600, 'hours' => 3600,
        'd' => 86400, 'day' => 86400, 'days' => 86400,
        'w' => 604800, 'week' => 604800, 'weeks' => 604800,
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
            strtolower($value) === 'now' => self::seconds($now),
            preg_match('/^\d+(\.\d+)?$/', $value) === 1 => self::epoch($value),
            preg_match('/^-(\d+) ?([a-z]+)$/i', $value, $relative) === 1 => self::relative($relative, $now),
            preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?$/', $value) === 1 => self::local($value, $timezone),
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/i', $value) === 1 => self::local($value, $timezone),
            default => null,
        };
    }

    /**
     * Get Unix seconds from an instant.
     */
    protected static function seconds(CarbonImmutable $instant): float
    {
        return (float) $instant->format('U.u');
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
        $seconds = self::UNITS[strtolower($match[2])] ?? null;

        if ($seconds === null || ltrim($match[1], '0') === '') {
            return null;
        }

        $instant = self::seconds($now) - (float) $match[1] * $seconds;

        return $instant < 0 ? null : $instant;
    }

    /**
     * Read a date or date-time, in the application timezone unless it names its own offset.
     */
    protected static function local(string $value, string $timezone): ?float
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $timezone);
            $parsed = CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }

        // A date PHP rolls over (February 30th) is no date.
        if ($date?->format('Y-m-d') !== substr($value, 0, 10)) {
            return null;
        }

        return self::seconds($parsed);
    }
}
