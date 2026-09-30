<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
enum Mode: string
{
    case ACTIVE = 'active';
    case OFF = 'off';
    case STEPPED_ASIDE = 'stepped-aside';
}
