<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum DetectorName: string
{
    case N_PLUS_ONE = 'n-plus-one';
    case DATABASE_BOUND = 'database-bound';
    case FAILING_ROUTES = 'failing-routes';
    case FAILING_JOBS = 'failing-jobs';
    case QUEUE_LATENCY = 'queue-latency';
    case FAILING_TASKS = 'failing-tasks';
    case EXCEPTION_CLUSTERS = 'exception-clusters';
    case ERROR_LOGS = 'error-logs';
    case FAILING_HTTP = 'failing-http';
    case CACHE = 'cache';
    case MEMORY = 'memory';
}
