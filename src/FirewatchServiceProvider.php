<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Console\Commands\ClearCommand;
use ClaudioDekker\Firewatch\Console\Commands\DoctorCommand;
use ClaudioDekker\Firewatch\Console\Commands\ServerCommand;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
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
     * The loopback address that no ingest agent listens on.
     */
    protected const DEAD_INGEST_URI = '127.0.0.1:1';

    /**
     * The notice a console process reports when Firewatch steps aside.
     */
    protected const STEPPED_ASIDE_NOTICE = 'Firewatch is installed but stepped aside in environment `%s`; install with `composer install --no-dev` in production.';

    /**
     * The mode this process resolved to.
     */
    protected Mode $mode;

    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->registerConfiguration();
        $this->resolveMode();
        $this->configureNightwatch();
        $this->registerNightwatch();
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

        $this->publishes([static::CONFIG_PATH => $this->app->configPath('firewatch.php')], 'firewatch-config');

        $this->commands([
            ServerCommand::class,
            DoctorCommand::class,
            ClearCommand::class,
        ]);
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
            argv: is_array($argv) ? array_values($argv) : [],
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
                'nightwatch.ingest.uri' => static::DEAD_INGEST_URI,
            ]),
            Mode::OFF => $config->set('nightwatch.enabled', false),
            Mode::STEPPED_ASIDE => null,
        };
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
}
