<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum LineageState: string
{
    case COMPLETE = 'complete';
    case NO_DISPATCH = 'no_dispatch';
    case NO_ATTEMPTS = 'no_attempts';
}
