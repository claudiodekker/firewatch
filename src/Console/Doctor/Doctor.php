<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs every check of `firewatch:doctor` and reports what each found. Read-only: it writes nothing anywhere.
 *
 * Independence is structural, not a convention the checks keep:
 * - each check resolves its group fresh from the container, so no state (an open connection, a memoised read,
 *   a half-built object) outlives the check that made it;
 * - resolving the group happens inside the per-check try, so a collaborator that can't even be built
 *   (a Reader without ext-sqlite3, a server whose tools fail to resolve) fails that one check, not the run.
 *
 * Not an Action: Actions change state (CODING_STANDARDS section 2); this is the one query the command calls.
 *
 * @internal
 */
class Doctor
{
    /**
     * Create a new doctor instance.
     */
    public function __construct(protected Container $container)
    {
        //
    }

    /**
     * Run every check in order.
     */
    public function run(): DoctorReport
    {
        return DoctorReport::collect($this->attempt(...));
    }

    /**
     * Run one check, and report a check that throws as failed with the exception's message.
     *
     * @return CheckResult|list<CheckResult>
     */
    protected function attempt(Check $check): CheckResult|array
    {
        try {
            return $this->check($check);
        } catch (Throwable $exception) {
            return CheckResult::threw($exception);
        }
    }

    /**
     * Run one check on a group resolved for it alone.
     *
     * @return CheckResult|list<CheckResult>
     */
    protected function check(Check $check): CheckResult|array
    {
        return match ($check) {
            Check::MODE => $this->install()->mode(),
            Check::PHP => $this->install()->php(),
            Check::SQLITE => $this->install()->sqlite(),
            Check::NIGHTWATCH => $this->install()->nightwatch(),
            Check::NIGHTWATCH_ORDER => $this->install()->nightwatchOrder(),
            Check::CONFIG => $this->install()->config(),
            Check::BUDGETS => $this->install()->budgets(),
            Check::STORE_PATH => $this->install()->storePath(),
            Check::STORE_PERMISSIONS => $this->store()->permissions(),
            Check::STORE_GITIGNORE => $this->store()->gitignore(),
            Check::STORE_IDENTITY => $this->store()->identity(),
            Check::STORE_INTEGRITY => $this->store()->integrity(),
            Check::STORE_ACTIVITY => $this->store()->activity(),
            Check::STORE_LOSSES => $this->store()->losses(),
            Check::STORE_DRIFT => $this->store()->drift(),
            Check::CAPTURE_POSTURE => $this->install()->capturePosture(),
            Check::SERVER => $this->server()->server(),
            Check::SQL_ACCESS => $this->server()->sqlAccess(),
            Check::CLIENT => $this->server()->client(),
        };
    }

    /**
     * Get a fresh group of the checks on the PHP process, Nightwatch and the configuration.
     */
    protected function install(): InstallChecks
    {
        return $this->container->make(InstallChecks::class);
    }

    /**
     * Get a fresh group of the checks on the store and the files beside it.
     */
    protected function store(): StoreChecks
    {
        return $this->container->make(StoreChecks::class);
    }

    /**
     * Get a fresh group of the checks on what an assistant connects to.
     */
    protected function server(): ServerChecks
    {
        return $this->container->make(ServerChecks::class);
    }
}
