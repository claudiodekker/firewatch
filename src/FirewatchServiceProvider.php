<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;
use RuntimeException;

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
     * Register the package services.
     */
    public function register(): void
    {
        $this->registerConfiguration();
        $this->registerNightwatch();
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        $this->reportConfigurationIssues();

        if ($this->app->runningInConsole()) {
            $this->publishes([static::CONFIG_PATH => $this->app->configPath('firewatch.php')], 'firewatch-config');
        }
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
     * Report the configuration issues once in a console process.
     */
    protected function reportConfigurationIssues(): void
    {
        $issues = $this->app->make(Configuration::class)->issues;

        if ($issues === [] || ! $this->app->runningInConsole()) {
            return;
        }

        $lines = array_map(fn (ConfigurationIssue $issue) => $issue->line(), $issues);

        report(new RuntimeException('Firewatch configuration: '.implode("\n", $lines)));
    }
}
