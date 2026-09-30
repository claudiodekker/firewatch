<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
    })
    ->in('Scenario', 'Feature', 'Contract');

pest()->group('scenario')->in('Scenario');
pest()->group('feature')->in('Feature');
pest()->group('contract')->in('Contract');
pest()->group('unit')->in('Unit');
pest()->group('arch')->in('Arch');

function registerFirewatch(): Configuration
{
    app()->register(FirewatchServiceProvider::class, force: true);

    return app(Configuration::class);
}

/**
 * @param  class-string<ServiceProvider>  $provider
 */
function forgetProvider(string $provider): void
{
    (function () use ($provider) {
        unset($this->serviceProviders[$provider], $this->loadedProviders[$provider]);
    })->call(app());
}

function setEnvironmentVariable(string $name, string $value): void
{
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");

    test()->beforeApplicationDestroyed(function () use ($name) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    });
}

/**
 * @param  list<string>  $argv
 */
function setArgv(array $argv): void
{
    $original = $_SERVER['argv'];
    $outputBuffers = ob_get_level();
    $_SERVER['argv'] = $argv;

    // A firewatch:server process redirects stray output through a buffer of its own.
    test()->beforeApplicationDestroyed(function () use ($original, $outputBuffers) {
        $_SERVER['argv'] = $original;

        while (ob_get_level() > $outputBuffers) {
            ob_end_clean();
        }
    });
}

function forceRequests(): void
{
    setEnvironmentVariable('NIGHTWATCH_FORCE_REQUEST', '1');

    test()->refreshApplication();
}
