<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * @internal
 */
enum Check: string
{
    case MODE = 'mode';
    case PHP = 'php';
    case SQLITE = 'sqlite';
    case NIGHTWATCH = 'nightwatch';
    case NIGHTWATCH_ORDER = 'nightwatch-order';
    case CONFIG = 'config';
    case BUDGETS = 'budgets';
    case STORE_PATH = 'store-path';
    case STORE_PERMISSIONS = 'store-permissions';
    case STORE_GITIGNORE = 'store-gitignore';
    case STORE_IDENTITY = 'store-identity';
    case STORE_INTEGRITY = 'store-integrity';
    case STORE_ACTIVITY = 'store-activity';
    case STORE_LOSSES = 'store-losses';
    case STORE_DRIFT = 'store-drift';
    case CAPTURE_POSTURE = 'capture-posture';
    case SERVER = 'server';
    case SQL_ACCESS = 'sql-access';
    case CLIENT = 'client';
}
