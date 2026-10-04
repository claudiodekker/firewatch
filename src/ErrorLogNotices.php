<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
class ErrorLogNotices implements Notices
{
    /**
     * Write a notice to the PHP error log, outside the exception handler and so outside the application's telemetry.
     */
    public function write(string $notice): void
    {
        error_log($notice);
    }
}
