<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum CallFact: string
{
    case ACTOR = 'actor';
    case ANCHORED = 'anchored';
    case STORE_LEVEL = 'store-level';
    case COMPILED_VIEW = 'compiled-view';
}
