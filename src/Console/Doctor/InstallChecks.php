<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * @internal
 */
class InstallChecks
{
    /**
     * Report the environment, the allowlist and the enabled flag.
     */
    public function mode(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the PHP release.
     */
    public function php(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the SQLite release.
     */
    public function sqlite(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the Nightwatch release.
     */
    public function nightwatch(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the order guard.
     */
    public function nightwatchOrder(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the configuration issues.
     *
     * @return list<CheckResult>
     */
    public function config(): array
    {
        return [CheckResult::info('not checked yet')];
    }

    /**
     * Report the budget entries.
     *
     * @return list<CheckResult>
     */
    public function budgets(): array
    {
        return [CheckResult::info('not checked yet')];
    }

    /**
     * Report the store path.
     */
    public function storePath(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the capture posture.
     */
    public function capturePosture(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }
}
