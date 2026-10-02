<?php

namespace ClaudioDekker\Firewatch\Mcp;

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
     * Format Unix seconds as local time in the timezone, in full, so that it can be read back and passed as a boundary.
     */
    public static function format(float $epoch, string $timezone): string
    {
        return Carbon::createFromTimestamp($epoch, $timezone)->format('Y-m-d H:i:s.u');
    }

    /**
     * Format Unix seconds with their microseconds, so that the number passes back into a tool as it was read.
     */
    public static function epoch(float $epoch): string
    {
        return rtrim(rtrim(sprintf('%.6F', $epoch), '0'), '.');
    }
}
