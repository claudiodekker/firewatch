<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum StoreState: string
{
    case ABSENT = 'absent';
    case SCHEMA_MISMATCH = 'schema_mismatch';
    case CORRUPT = 'corrupt';
    case FOREIGN = 'foreign';
    case BUSY = 'busy';
    case UNAVAILABLE = 'unavailable';
}
