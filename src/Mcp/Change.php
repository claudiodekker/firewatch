<?php

namespace ClaudioDekker\Firewatch\Mcp;

use InvalidArgumentException;

/**
 * @internal
 */
enum Change: string
{
    case SLOWER = 'slower';
    case FASTER = 'faster';
    case HEAVIER = 'heavier';
    case LIGHTER = 'lighter';
    case MORE_CALLS = 'more_calls';
    case FEWER_CALLS = 'fewer_calls';
    case STEADY = 'steady';
    case NEW = 'new';
    case GONE = 'gone';
    case ZERO_BASELINE = 'zero_baseline';
    case NOT_EVALUATED = 'not_evaluated';

    /**
     * The share of the before value, in percent, that a value must move by more than.
     */
    public const BAND_PCT = 10;

    /**
     * Decide how a measure moved from the before value to the after value: it moved only past both the band and the measure's noise floor.
     */
    public static function of(Measure $measure, int|float $before, int|float $after): self
    {
        if ($before <= 0 && $after > 0) {
            return self::ZERO_BASELINE;
        }

        [$floor, $rises, $falls] = $measure->movement() ?? throw new InvalidArgumentException("No change rule judges {$measure->value}.");
        $difference = abs($after - $before);

        if ($difference * Ranking::PERCENT <= self::BAND_PCT * $before || $difference <= $floor) {
            return self::STEADY;
        }

        return $after > $before ? $rises : $falls;
    }

    /**
     * Determine if the change says the measure moved.
     */
    public function moved(): bool
    {
        return in_array($this, [self::SLOWER, self::FASTER, self::HEAVIER, self::LIGHTER, self::MORE_CALLS, self::FEWER_CALLS], true);
    }
}
