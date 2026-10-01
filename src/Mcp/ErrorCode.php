<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum ErrorCode: string
{
    case INVALID_ARGUMENT = 'invalid_argument';
    case UNREADABLE_TIME = 'unreadable_time';
    case EMPTY_WINDOW = 'empty_window';
}
