<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use ClaudioDekker\Firewatch\Console\Commands\ServerCommand;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\Sqlite;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * @internal
 */
class ServerChecks
{
    /**
     * Create a new server checks instance.
     */
    public function __construct(
        protected Application $app,
        protected SqlRunner $runner,
        protected string $osFamily = PHP_OS_FAMILY,
    ) {
        //
    }

    /**
     * Report that the server boots and lists tools that each have a name and a description, with instructions.
     */
    public function server(): CheckResult
    {
        $listing = $this->app->make(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
        $incomplete = array_filter($listing['tools'], fn (array $tool) => ($tool['name'] ?? '') === '' || ($tool['description'] ?? '') === '');

        if ($listing['tools'] === [] || $incomplete !== []) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.server.tools'),
                __('firewatch::messages.doctor.server.fix'),
            );
        }

        if (trim(__('firewatch::messages.instructions')) === '') {
            return CheckResult::fail(
                __('firewatch::messages.doctor.server.instructions'),
                __('firewatch::messages.doctor.server.fix'),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.server.ok', [
            'version' => $listing['server']['version'],
            'count' => count($listing['tools']),
        ]));
    }

    /**
     * Report whether the SQL tool can run, spawning its child once.
     */
    public function sqlAccess(): CheckResult
    {
        $reason = $this->runner->probe();

        if ($reason === null) {
            return CheckResult::ok(__('firewatch::messages.doctor.sql-access.ok'));
        }

        return CheckResult::warn(
            __('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __("firewatch::messages.sql_unavailable.{$reason->value}")]),
            __("firewatch::messages.doctor.sql-access.{$reason->value}_fix", [
                'binary' => $this->runner->phpBinary(),
                'command' => $this->quote($this->runner->phpBinary()),
                'minimum' => Sqlite::MINIMUM_VERSION,
            ]),
        );
    }

    /**
     * Report the launch command an assistant's client runs, with absolute paths quoted when this platform's shell needs it.
     */
    public function client(): CheckResult
    {
        $command = $this->quote($this->runner->phpBinary()).' '.$this->quote($this->app->basePath('artisan')).' '.ServerCommand::NAME;

        return CheckResult::info(__('firewatch::messages.doctor.client.launch', ['command' => $command]));
    }

    /**
     * Quote an argument for this platform's shell only when it holds whitespace or a shell metacharacter.
     */
    protected function quote(string $argument): string
    {
        $windows = $this->osFamily === 'Windows';

        if (! preg_match($windows ? '/[\s"\'&|<>()^;,%!]/' : '/[\s"\'\\\\&|<>()$`;*?\[\]{}!#~=%]/', $argument)) {
            return $argument;
        }

        return $windows
            ? '"'.str_replace('"', '\\"', $argument).'"'
            : "'".str_replace("'", "'\\''", $argument)."'";
    }
}
