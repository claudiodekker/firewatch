<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Instant;

/**
 * @internal
 */
class Deadline
{
    /**
     * The seconds the count mode of the overview runs detectors for.
     */
    public const SECONDS = 5;

    /**
     * The decimals of a second the store clock keeps.
     */
    protected const CLOCK_DECIMALS = 6;

    /**
     * Create a new deadline instance.
     */
    public function __construct(protected float $startedAt)
    {
        //
    }

    /**
     * Determine if the bound has passed on the store clock.
     */
    public function passed(): bool
    {
        return round(Instant::now() - $this->startedAt, self::CLOCK_DECIMALS) >= self::SECONDS;
    }
}
