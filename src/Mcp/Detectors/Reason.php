<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum Reason: string
{
    case NO_RECORDS = 'no_records';
    case OUTSIDE_COVERAGE = 'outside_coverage';
    case PREREQUISITE_MISSING = 'prerequisite_missing';
    case STORE_UNAVAILABLE = 'store_unavailable';
    case DEADLINE = 'deadline';
    case NO_EXECUTIONS_IN_SCOPE = 'no_executions_in_scope';
}
