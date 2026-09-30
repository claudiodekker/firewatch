<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
enum DriftKind: string
{
    case UNKNOWN_TYPE = 'unknown_type';
    case UNKNOWN_VERSION = 'unknown_version';
    case UNKNOWN_FIELD = 'unknown_field';
    case MISSING_FIELD = 'missing_field';
    case STRUCTURE = 'structure';
    case VERSION = 'version';
}
