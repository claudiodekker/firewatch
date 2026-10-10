<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * The doctor's checks: a closed set of ids, run and printed in the order of the cases.
 *
 * The case order is the contract order (#9 item 23). Adding, removing or moving a case changes the contract,
 * and `Doctor::check()` stops compiling under PHPStan (non-exhaustive match) until the case has a check.
 *
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
