<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum Subject
{
    /**
     * The detector judges executions, so it has nothing to judge between a test's executions.
     */
    case EXECUTIONS;

    /**
     * The detector judges records wherever they ran.
     */
    case RECORDS;
}
