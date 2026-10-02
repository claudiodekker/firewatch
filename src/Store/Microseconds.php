<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
class Microseconds
{
    /**
     * The microseconds in a millisecond.
     */
    public const PER_MILLISECOND = 1_000;

    /**
     * The microseconds in a second, as a float so that a division by it never yields a whole number type.
     */
    public const PER_SECOND = 1_000_000.0;
}
