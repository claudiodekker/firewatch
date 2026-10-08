<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum Link: string
{
    case DIRECT = 'direct';
    case DISPATCH = 'dispatch';
    case INSIDE = 'inside';
}
