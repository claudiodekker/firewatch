<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum RebuildReason: string
{
    case SCHEMA = 'schema';
    case CORRUPT = 'corrupt';
    case UNKNOWN = 'unknown';
}
