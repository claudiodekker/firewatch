<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Illuminate\Support\Carbon;

/**
 * @internal
 */
class Instant
{
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
