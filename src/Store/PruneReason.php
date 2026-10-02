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
}
