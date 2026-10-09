<?php

namespace ClaudioDekker\Firewatch\Sql\Child;

/**
 * @internal
 */
enum Unavailable: string
{
    case SPAWN_FAILED = 'spawn_failed';
    case AUTHORIZER = 'authorizer';
}
