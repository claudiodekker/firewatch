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
     * The whole of the scale a share and a percentage are on.
     */
    public const PERCENT = 100;

    /**
     * The decimals a share is rounded to in an answer.
     */
    public const DECIMALS = 1;

    /**
     * The records a group needs before its median is shown.
     */
    protected const MEDIAN_FLOOR = 3;

    /**
     * The records a group needs before its 95th percentile is shown.
     */
    protected const P95_FLOOR = 20;

    /**
     * Get a part as a percentage of a whole, rounded to the decimals of an answer, or null for a whole of nothing.
     *
     * @param  int|float|numeric-string  $part
     * @param  int|float|numeric-string  $whole
     */
    public static function percent(int|float|string $part, int|float|string $whole): ?float
    {
        return $whole <= 0 ? null : round(self::PERCENT * $part / $whole, self::DECIMALS);
    }

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
            self::MEDIAN => self::MEDIAN_FLOOR,
            self::P95 => self::P95_FLOOR,
        };
    }

    /**
     * Get the SQL expression of the row the percentile takes among `n` values in ascending order, by nearest rank.
     */
    public function nearestRank(): string
    {
        return 'max(1, (n * '.$this->share().' + '.(self::PERCENT - 1).') / '.self::PERCENT.')';
    }
}
