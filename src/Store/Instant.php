<?php

namespace ClaudioDekker\Firewatch\Store;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * @internal
 */
class Instant
{
    /**
     * Get the clock's current moment as Unix seconds with microseconds.
     */
    public static function now(): float
    {
        return self::of(Date::now());
    }

    /**
     * Get a moment as Unix seconds with microseconds.
     */
    public static function of(CarbonInterface $moment): float
    {
        return (float) $moment->format('U.u');
    }

    /**
     * Format Unix seconds as local time in the timezone.
     */
    public static function format(float $epoch, string $timezone): string
    {
        return Carbon::createFromTimestamp($epoch, $timezone)->format('Y-m-d H:i:s.u');
    }

    /**
     * Round Unix seconds to the microsecond, the instant they read back as once formatted.
     */
    public static function rounded(float $epoch): float
    {
        return (float) self::epoch($epoch);
    }

    /**
     * Format Unix seconds with their microseconds.
     */
    public static function epoch(float $epoch): string
    {
        return rtrim(rtrim(sprintf('%.6F', $epoch), '0'), '.');
    }
}
