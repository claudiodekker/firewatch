<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
enum TestRecord
{
    case IN_EXECUTION;
    case OUTSIDE_EXECUTIONS;

    /**
     * The source stored on what a test runs outside its executions, which no execution has.
     */
    public const BETWEEN_SOURCE = 'between';
}
