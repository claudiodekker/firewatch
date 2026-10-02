<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum Percentile: string
{
    case MEDIAN = 'median';
    case P95 = 'p95';

    /**
     * Get the share of the records, in percent, that lie at or below the percentile.
     */
    public function share(): int
    {
        return match ($this) {
            self::MEDIAN => 50,
            self::P95 => 95,
        };
    }

    /**
     * Get the fewest records the percentile is shown for.
     */
    public function floor(): int
    {
        return match ($this) {
            self::MEDIAN => Ranking::P50_FLOOR,
            self::P95 => Ranking::P95_FLOOR,
        };
    }
}
