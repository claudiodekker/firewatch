<?php

namespace ClaudioDekker\Firewatch\Configuration;

/**
 * @internal
 */
enum DurationUnit: string
{
    case SECOND = 's';
    case MINUTE = 'm';
    case HOUR = 'h';
    case DAY = 'd';
    case WEEK = 'w';

    /**
     * Get the seconds in the unit.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::SECOND => 1,
            self::MINUTE => 60,
            self::HOUR => 3600,
            self::DAY => 86400,
            self::WEEK => 604800,
        };
    }
}
