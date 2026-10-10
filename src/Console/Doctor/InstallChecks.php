<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Store\Writer;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use SQLite3;

/**
 * @internal
 */
class InstallChecks
{
    /**
     * The oldest PHP release Firewatch runs on.
     */
    public const MINIMUM_PHP_VERSION = '8.3.0';

    /**
     * The environments whose presence in the allowlist is a warning.
     */
    protected const PRODUCTION_ENVIRONMENTS = ['production', 'prod'];

    /**
     * The SQLite release ext-sqlite3 links, or null when the extension is missing.
     *
     * @var Closure(): ?string
     */
    protected Closure $sqliteVersion;

    /**
     * Create a new install checks instance.
     *
     * @param  (Closure(): ?string)|null  $sqliteVersion  answers null when ext-sqlite3 is missing
     */
    public function __construct(
        protected Configuration $configuration,
        protected NightwatchInstall $install,
        protected Dispatcher $events,
        protected Application $app,
        protected string $phpVersion = PHP_VERSION,
        protected string $phpBinary = PHP_BINARY,
        ?Closure $sqliteVersion = null,
    ) {
        $this->sqliteVersion = $sqliteVersion ?? static fn (): ?string => class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null;
    }

    /**
     * Report the environment, the allowlist, the enabled flag and the mode a host process resolves to.
     */
    public function mode(): CheckResult
    {
        $environment = $this->app->environment();
        $environments = implode(', ', $this->configuration->environments);
        $production = array_intersect(static::PRODUCTION_ENVIRONMENTS, $this->configuration->environments);

        if ($production !== []) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.mode.production', [
                    'environments' => $environments,
                    'production' => implode(', ', $production),
                ]),
                __('firewatch::messages.doctor.mode.production_fix'),
            );
        }

        if (! $this->configuration->enabled) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.mode.disabled', [
                    'environment' => $environment,
                    'environments' => $environments,
                ]),
                __('firewatch::messages.doctor.mode.disabled_fix'),
            );
        }

        $requestMode = (new ModeResolver)->resolve($this->configuration, $environment, argv: [], sqliteVersion: ($this->sqliteVersion)());

        return CheckResult::ok(__('firewatch::messages.doctor.mode.ok', [
            'environment' => $environment,
            'environments' => $environments,
            'mode' => $requestMode->value,
        ]));
    }

    /**
     * Report the PHP release and binary, failing below the floor.
     */
    public function php(): CheckResult
    {
        $facts = [
            'version' => $this->phpVersion,
            'binary' => $this->phpBinary,
            'minimum' => static::MINIMUM_PHP_VERSION,
        ];

        if (version_compare($this->phpVersion, static::MINIMUM_PHP_VERSION, '<')) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.php.too_old', $facts),
                __('firewatch::messages.doctor.php.too_old_fix', $facts),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.php.ok', $facts));
    }

    /**
     * Report the SQLite release, failing when it is missing or below the floor and warning in the write-ahead log reset range.
     */
    public function sqlite(): CheckResult
    {
        $version = ($this->sqliteVersion)();
        $facts = [
            'version' => (string) $version,
            'binary' => $this->phpBinary,
            'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION,
        ];

        if ($version === null) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.sqlite.missing'),
                __('firewatch::messages.doctor.sqlite.missing_fix', $facts),
            );
        }

        if (version_compare($version, ModeResolver::MINIMUM_SQLITE_VERSION, '<')) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.sqlite.too_old', $facts),
                __('firewatch::messages.doctor.sqlite.too_old_fix', $facts),
            );
        }

        if (Writer::hasWalResetBug($version)) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.sqlite.wal_reset', $facts),
                __('firewatch::messages.doctor.sqlite.wal_reset_fix'),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.sqlite.ok', $facts));
    }

    /**
     * Report the Nightwatch release against the verified line and the API the seam relies on.
     */
    public function nightwatch(): CheckResult
    {
        $facts = [
            'version' => $this->install->version,
            'line' => NightwatchInstall::VERIFIED_LINE,
        ];

        if (! $this->install->hasVetoEvent() || ! property_exists($this->install->vetoEvent(), 'records')) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.nightwatch.api', $facts),
                __('firewatch::messages.doctor.nightwatch.api_fix', $facts),
            );
        }

        if (! $this->install->isVerified()) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.nightwatch.unverified', $facts),
                __('firewatch::messages.doctor.nightwatch.unverified_fix', $facts),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.nightwatch.ok', $facts));
    }

    /**
     * Report the order guard and the other listeners on the event the veto listens on.
     */
    public function nightwatchOrder(): CheckResult
    {
        if ($this->install->registeredFirst) {
            return CheckResult::fail(
                __('firewatch::messages.doctor.nightwatch-order.registered_first'),
                __('firewatch::messages.doctor.nightwatch-order.registered_first_fix'),
            );
        }

        $otherListeners = count($this->events->getListeners($this->install->vetoEvent()));

        if ($otherListeners > 0) {
            return CheckResult::warn(
                trans_choice('firewatch::messages.doctor.nightwatch-order.listeners', $otherListeners, ['count' => $otherListeners]),
                __('firewatch::messages.doctor.nightwatch-order.listeners_fix'),
            );
        }

        return CheckResult::ok(__('firewatch::messages.doctor.nightwatch-order.ok'));
    }

    /**
     * Report each issue of every key but the store path and the budgets.
     *
     * @return list<CheckResult>
     */
    public function config(): array
    {
        $issues = $this->issues(Check::CONFIG);

        if ($issues === []) {
            return [CheckResult::ok(__('firewatch::messages.doctor.config.ok'))];
        }

        return array_map(fn (ConfigurationIssue $issue) => CheckResult::warn(
            $issue->line(),
            __('firewatch::messages.doctor.config.issue_fix', ['key' => $issue->key]),
        ), $issues);
    }

    /**
     * Report each budget issue and each entry that can never govern, else the entries.
     *
     * @return list<CheckResult>
     */
    public function budgets(): array
    {
        $results = array_map(fn (ConfigurationIssue $issue) => CheckResult::warn(
            $issue->line(),
            __('firewatch::messages.doctor.budgets.issue_fix', ['key' => $issue->key]),
        ), $this->issues(Check::BUDGETS));

        foreach ($this->shadowedEntries() as $number) {
            $results[] = CheckResult::info(__('firewatch::messages.doctor.budgets.shadowed', ['number' => $number]));
        }

        if ($results !== []) {
            return $results;
        }

        $count = count($this->configuration->budgets);

        return [CheckResult::ok(trans_choice('firewatch::messages.doctor.budgets.ok', $count, ['count' => $count]))];
    }

    /**
     * Report the resolved store path, warning when the configured one was refused and the default is in use.
     */
    public function storePath(): CheckResult
    {
        $path = $this->configuration->database;
        $issue = $this->issues(Check::STORE_PATH)[0] ?? null;

        if ($issue === null) {
            return CheckResult::ok(__('firewatch::messages.doctor.store-path.ok', ['path' => $path]));
        }

        return CheckResult::warn(
            __('firewatch::messages.doctor.store-path.refused', [
                'issue' => $issue->line(),
                'path' => $path,
            ]),
            __('firewatch::messages.doctor.store-path.refused_fix'),
        );
    }

    /**
     * Report the capture posture in effect, warning when Nightwatch's defaults apply because the order guard tripped.
     */
    public function capturePosture(): CheckResult
    {
        if ($this->install->registeredFirst) {
            return CheckResult::warn(
                __('firewatch::messages.doctor.capture-posture.nightwatch_defaults'),
                __('firewatch::messages.doctor.nightwatch-order.registered_first_fix'),
            );
        }

        return CheckResult::info(__('firewatch::messages.doctor.capture-posture.posture', [
            'fields' => $this->listed($this->configuration->redactPayloadFields),
            'headers' => $this->listed($this->configuration->redactHeaders),
            'payload' => $this->switched($this->configuration->captureRequestPayload),
            'logs' => $this->switched($this->configuration->captureLogs),
        ]));
    }

    /**
     * Get the configuration issues one check owns: `database` is the store path's, `budgets` and `budgets[N]` the budgets', the rest the config's.
     *
     * @return list<ConfigurationIssue>
     */
    protected function issues(Check $check): array
    {
        return array_values(array_filter(
            $this->configuration->issues,
            fn (ConfigurationIssue $issue) => $this->owner($issue) === $check,
        ));
    }

    /**
     * Get the check an issue belongs to.
     */
    protected function owner(ConfigurationIssue $issue): Check
    {
        return match (true) {
            $issue->key === 'database' => Check::STORE_PATH,
            $issue->key === 'budgets', str_starts_with($issue->key, 'budgets[') => Check::BUDGETS,
            default => Check::CONFIG,
        };
    }

    /**
     * Get the numbers of the global budget entries that an earlier global entry of the same type shadows.
     *
     * @return list<int>
     */
    protected function shadowedEntries(): array
    {
        $governed = [];
        $shadowed = [];

        foreach ($this->configuration->budgets as $entry) {
            if (! $entry->isGlobal()) {
                continue;
            }

            if (isset($governed[$entry->type->value])) {
                $shadowed[] = $entry->number;
            }

            $governed[$entry->type->value] = true;
        }

        return $shadowed;
    }

    /**
     * Get a list of names as one phrase.
     *
     * @param  list<string>  $names
     */
    protected function listed(array $names): string
    {
        return $names === [] ? __('firewatch::messages.doctor.none') : implode(', ', $names);
    }

    /**
     * Get a switch as the word for its position.
     */
    protected function switched(bool $on): string
    {
        return $on ? __('firewatch::messages.doctor.on') : __('firewatch::messages.doctor.off');
    }
}
