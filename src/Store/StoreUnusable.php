<?php

namespace ClaudioDekker\Firewatch\Store;

use RuntimeException;

/**
 * @internal
 */
class StoreUnusable extends RuntimeException
{
    /**
     * Create a new unusable store exception instance.
     *
     * @param  int|string|null  $found  the schema version a mismatched store carries, or the SQLite release that is too old
     */
    public function __construct(public readonly StoreState $state, public readonly int|string|null $found = null)
    {
        parent::__construct("The store is not usable: {$state->name}.");
    }
}
