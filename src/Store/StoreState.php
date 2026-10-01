<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum StoreState
{
    case Absent;
    case SchemaMismatch;
    case Corrupt;
    case Foreign;
    case Busy;
    case Unavailable;
}
