<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum FailureEntry: string
{
    case BATCH = 'batch';
    case PRUNE = 'prune';
    case RECOVERY = 'recovery';
}
