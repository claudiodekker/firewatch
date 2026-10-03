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
     * Determine if the rule trims records of unknown start, which have no age but count towards the cap and the size.
     */
    public function countsUnstarted(): bool
    {
        return $this !== self::AGE;
    }
}
