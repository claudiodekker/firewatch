<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum CoverageState: string
{
    case OK = 'ok';
    case EMPTY = 'empty';
    case ABSENT = 'absent';
    case UNUSABLE = 'unusable';
}
