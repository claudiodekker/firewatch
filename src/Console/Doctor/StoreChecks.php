<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * @internal
 */
class StoreChecks
{
    /**
     * Report the modes of the store directory and file.
     */
    public function permissions(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the store directory's own ignore file.
     */
    public function gitignore(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report whose file the store is.
     */
    public function identity(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report what the integrity check finds.
     */
    public function integrity(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report what the store holds.
     */
    public function activity(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the dropped batches.
     */
    public function losses(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }

    /**
     * Report the drift rows.
     */
    public function drift(): CheckResult
    {
        return CheckResult::info('not checked yet');
    }
}
