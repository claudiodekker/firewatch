<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum PruneReason: string
{
    case AGE = 'age';
    case CAP = 'cap';
    case SIZE = 'size';

    /**
     * Determine if the rule trims records of unknown start.
     */
    public function countsUnstarted(): bool
    {
        return $this !== self::AGE;
    }
}
