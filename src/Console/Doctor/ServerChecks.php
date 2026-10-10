<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * @internal
 */
class ServerChecks
{
    /**
     * Report that the server boots.
     */
    public function server(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report whether the SQL tool can run.
     */
    public function sqlAccess(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the launch command.
     */
    public function client(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }
}
