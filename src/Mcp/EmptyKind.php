<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum EmptyKind: string
{
    case NO_STORE = 'no_store';
    case STORE_UNUSABLE = 'store_unusable';
    case STORE_EMPTY = 'store_empty';
    case WINDOW_EMPTY = 'window_empty';
    case NO_MATCH = 'no_match';
}
