<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
enum ExecutionType: string
{
    case REQUEST = 'request';
    case COMMAND = 'command';
    case JOB_ATTEMPT = 'job-attempt';
    case SCHEDULED_TASK = 'scheduled-task';
}
