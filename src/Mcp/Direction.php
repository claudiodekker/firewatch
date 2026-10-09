<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum Direction: string
{
    case ROSE = 'rose';
    case FELL = 'fell';
    case HELD = 'held';

    /**
     * Decide how a measure moved from the median of a trend's first half to that of its last half, by the change rule with the measure's noise floor.
     */
    public static function of(Measure $by, int|float $before, int|float $after): self
    {
        $change = Change::of($by, $before, $after);

        if ($change === Change::ZERO_BASELINE) {
            return $after > ($by->movement()[0] ?? 0) ? self::ROSE : self::HELD;
        }

        return match ($change) {
            Change::SLOWER, Change::HEAVIER, Change::MORE_CALLS => self::ROSE,
            Change::FASTER, Change::LIGHTER, Change::FEWER_CALLS => self::FELL,
            default => self::HELD,
        };
    }
}
