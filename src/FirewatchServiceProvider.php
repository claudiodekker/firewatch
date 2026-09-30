<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
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
    protected const CONFIG_PATH = __DIR__.'/../config/firewatch.php';

    public function register(): void
    {
        $this->registerConfiguration();
        $this->registerNightwatch();
    }

    public function boot(): void
    {
        $this->reportConfigurationIssues();

        if ($this->app->runningInConsole()) {
            $this->publishes([static::CONFIG_PATH => $this->app->configPath('firewatch.php')], 'firewatch-config');
        }
    }

    protected function registerConfiguration(): void
    {
        $this->mergeConfigFrom(static::CONFIG_PATH, 'firewatch');

        $normaliser = new ConfigurationNormaliser(
            basePath: $this->app->basePath(),
            publicPath: $this->app->publicPath(),
            storagePath: $this->app->storagePath(),
        );

        $configuration = $normaliser->resolve($this->app->make('config')->get('firewatch'));

        $this->app->instance(Configuration::class, $configuration);
    }

    protected function registerNightwatch(): void
    {
        // Nightwatch is in dont-discover, so this is its only registration.
        $this->app->register(NightwatchServiceProvider::class);

        AliasLoader::getInstance()->alias('Nightwatch', Nightwatch::class);
    }

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
