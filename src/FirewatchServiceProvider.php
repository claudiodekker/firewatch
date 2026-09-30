<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Console\Commands\ClearCommand;
use ClaudioDekker\Firewatch\Console\Commands\DoctorCommand;
use ClaudioDekker\Firewatch\Console\Commands\ServerCommand;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;
use RuntimeException;
use SQLite3;

/**
 * @internal
 */
class FirewatchServiceProvider extends ServiceProvider
{
    /**
     * The path of the package's configuration file.
     */
    protected const CONFIG_PATH = __DIR__.'/../config/firewatch.php';

    /**
     * The configuration groups whose keys are merged one by one.
     */
    protected const NESTED_GROUPS = ['retention', 'capture'];

    /**
     * The Nightwatch token that no hosted service accepts.
     */
    protected const DEAD_TOKEN = 'firewatch';

    /**
     * The ingest settings: a loopback address no agent listens on, with Nightwatch's own defaults.
     */
    protected const DEAD_INGEST = [
        'uri' => '127.0.0.1:1',
        'timeout' => 0.5,
        'connection_timeout' => 0.5,
        'event_buffer' => 500,
    ];

    /**
     * The notice a console process reports when Firewatch steps aside.
     */
    protected const STEPPED_ASIDE_NOTICE = 'Firewatch is installed but stepped aside in environment `%s`; install with `composer install --no-dev` in production.';

    /**
     * The start of the report when Nightwatch's ingest could not be swapped.
     */
    protected const INGEST_NOT_REPLACED = 'Firewatch left Nightwatch\'s ingest in place: ';

    /**
     * The report when Nightwatch's provider was registered before Firewatch's.
     */
    protected const REGISTERED_FIRST = 'Nightwatch\'s provider was registered before Firewatch\'s, so Nightwatch read its configuration before Firewatch set it. Remove `Laravel\\Nightwatch\\NightwatchServiceProvider` from your providers and run `php artisan package:discover`.';

    /**
     * The mode this process resolved to.
     */
    protected Mode $mode;

    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->registerNightwatchInstall();
        $this->registerConfiguration();
        $this->resolveMode();
        $this->configureNightwatch();
        $this->vetoNightwatchTransmit();
        $this->registerNightwatch();
        $this->replaceNightwatchIngest();
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if ($this->mode === Mode::STEPPED_ASIDE) {
            report(new RuntimeException(sprintf(static::STEPPED_ASIDE_NOTICE, $this->app->environment())));

            return;
        }

        $this->reportConfigurationIssues();
        $this->reportNightwatchInstall();

        $this->publishes([static::CONFIG_PATH => $this->app->configPath('firewatch.php')], 'firewatch-config');

        $this->commands([
            ServerCommand::class,
            DoctorCommand::class,
            ClearCommand::class,
        ]);
    }

    /**
     * Keep how Nightwatch is installed, before Firewatch registers its provider.
     */
    protected function registerNightwatchInstall(): void
    {
        // A second registration of Firewatch finds the Nightwatch provider its first one registered.
        $registeredFirst = $this->app->getProvider(NightwatchServiceProvider::class) !== null
            && $this->app->getProvider($this) === null;

        $install = $this->app->make(NightwatchInstall::class, [
            'version' => InstalledVersions::getPrettyVersion('laravel/nightwatch') ?? '',
            'registeredFirst' => $registeredFirst,
        ]);

        $this->app->instance(NightwatchInstall::class, $install);
    }

    /**
     * Resolve the configuration once for this process.
     */
    protected function registerConfiguration(): void
    {
        $this->mergeConfiguration();

        $raw = $this->app->make('config')->get('firewatch');
        $normaliser = new ConfigurationNormaliser(
            basePath: $this->app->basePath(),
            publicPath: $this->app->publicPath(),
            storagePath: $this->app->storagePath(),
        );

        $configuration = $normaliser->resolve(is_array($raw) ? $raw : []);

        $this->app->instance(Configuration::class, $configuration);
    }

    /**
     * Resolve the mode once for this process.
     */
    protected function resolveMode(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $resolver = new ModeResolver;

        $this->mode = $resolver->resolve(
            $this->app->make(Configuration::class),
            environment: $this->app->environment(),
            argv: is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [],
            sqliteVersion: class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null,
        );
    }

    /**
     * Write the mode's Nightwatch keys before Nightwatch's provider snapshots them.
     */
    protected function configureNightwatch(): void
    {
        $config = $this->app->make('config');

        match ($this->mode) {
            Mode::ACTIVE => $config->set([
                'nightwatch.enabled' => true,
                'nightwatch.token' => static::DEAD_TOKEN,
                'nightwatch.ingest' => static::DEAD_INGEST,
            ]),
            Mode::OFF => $config->set('nightwatch.enabled', false),
            Mode::STEPPED_ASIDE => null,
        };
    }

    /**
     * Veto every batch of Nightwatch's own ingest when Active, in case it stays in place.
     */
    protected function vetoNightwatchTransmit(): void
    {
        if ($this->mode !== Mode::ACTIVE) {
            return;
        }

        // until() stops at the first non-null answer, so listen before Nightwatch's provider registers.
        $this->app->make('events')->listen(IngestingEvents::class, static fn () => false);
    }

    /**
     * Merge the package defaults under the published configuration file.
     */
    protected function mergeConfiguration(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = require static::CONFIG_PATH;
        $published = $config->get('firewatch');
        $published = is_array($published) ? $published : [];

        // mergeConfigFrom merges the top level only; keep the package's env() for nested keys a published group leaves out.
        foreach (static::NESTED_GROUPS as $group) {
            if (is_array($published[$group] ?? null)) {
                $published[$group] += $defaults[$group];
            }
        }

        $config->set('firewatch', $published + $defaults);
    }

    /**
     * Register Nightwatch's provider and facade alias.
     */
    protected function registerNightwatch(): void
    {
        // Nightwatch is in dont-discover, so this is its only registration.
        $this->app->register(NightwatchServiceProvider::class);

        AliasLoader::getInstance()->alias('Nightwatch', Nightwatch::class);
    }

    /**
     * Swap Nightwatch's ingest for one that transmits nothing, unless Firewatch is stepped aside.
     */
    protected function replaceNightwatchIngest(): void
    {
        if ($this->mode === Mode::STEPPED_ASIDE) {
            return;
        }

        if (! $this->app->bound(Core::class)) {
            $this->reportIngestNotReplaced(new RuntimeException('its core is not registered'));

            return;
        }

        try {
            (new IngestReplacer)->replace($this->app->make(Core::class));
        } catch (RuntimeException $exception) {
            $this->reportIngestNotReplaced($exception);
        }
    }

    /**
     * Report once in a console process that Nightwatch's ingest stayed in place.
     */
    protected function reportIngestNotReplaced(RuntimeException $reason): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        report(new RuntimeException(static::INGEST_NOT_REPLACED.$reason->getMessage().'.', previous: $reason));
    }

    /**
     * Report the configuration issues once.
     */
    protected function reportConfigurationIssues(): void
    {
        $issues = $this->app->make(Configuration::class)->issues;

        if ($issues === []) {
            return;
        }

        $lines = array_map(fn (ConfigurationIssue $issue) => $issue->line(), $issues);

        report(new RuntimeException('Firewatch configuration: '.implode("\n", $lines)));
    }

    /**
     * Report once what the boot guards found wrong with how Nightwatch is installed.
     */
    protected function reportNightwatchInstall(): void
    {
        $install = $this->app->make(NightwatchInstall::class);

        if ($install->registeredFirst) {
            report(new RuntimeException(static::REGISTERED_FIRST));
        }

        // Only Active registers the veto.
        $missing = $this->mode === Mode::ACTIVE ? $install->missingVetoEvent() : null;

        if ($missing !== null) {
            report($missing);
        }
    }
}
