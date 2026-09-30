<?php

namespace ClaudioDekker\Firewatch\Store;

use RuntimeException;

/**
 * @internal
 */
class StoreFailure extends RuntimeException
{
    /**
     * Create a new store failure instance.
     */
    public function __construct(public readonly FailureKind $kind, string $message)
    {
        parent::__construct($message);
    }
}
