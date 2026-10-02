<?php

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\Ingest as FirewatchIngest;
use ClaudioDekker\Firewatch\NullIngest;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest;
use Laravel\Nightwatch\NightwatchServiceProvider;

function applicationNightwatchConfig(): array
{
    return [
        'enabled' => false,
        'token' => 'application-token',
        'ingest' => ['uri' => 'ingest.example.com:2407', 'timeout' => 3, 'connection_timeout' => 3, 'event_buffer' => 7],
    ];
}

it('enables Nightwatch against the dead token and address when Active', function () {
    config()->set('nightwatch', applicationNightwatchConfig());

    registerFirewatch();

    expect(config('nightwatch'))->toBe([
        'enabled' => true,
        'token' => 'firewatch',
        'ingest' => ['uri' => '127.0.0.1:1', 'timeout' => 0.5, 'connection_timeout' => 0.5, 'event_buffer' => 500],
        'sampling' => ['requests' => 1.0, 'commands' => 1.0, 'exceptions' => 1.0, 'scheduled_tasks' => 1.0],
        'filtering' => ['ignore_cache_events' => false, 'ignore_mail' => false, 'ignore_notifications' => false, 'ignore_outgoing_requests' => false, 'ignore_queries' => false, 'log_level' => 'debug'],
        'capture_exception_source_code' => true,
        'capture_request_payload' => true,
        'redact_payload_fields' => [],
        'redact_headers' => [],
    ]);
});

it('writes only a disabled Nightwatch when Off', function () {
    config()->set('nightwatch', [...applicationNightwatchConfig(), 'enabled' => true]);
    config()->set('firewatch.enabled', false);

    registerFirewatch();

    expect(config('nightwatch'))->toBe([...applicationNightwatchConfig(), 'enabled' => false]);
});

it('writes no Nightwatch key when stepped aside', function () {
    config()->set('nightwatch', [...applicationNightwatchConfig(), 'enabled' => true]);
    config()->set('firewatch.environments', 'local');

    registerFirewatch();

    expect(config('nightwatch'))->toBe([...applicationNightwatchConfig(), 'enabled' => true]);
});

it('ignores the NIGHTWATCH_ variables for the keys it writes', function (bool $enabled, array $expected) {
    setEnvironmentVariable(name: 'NIGHTWATCH_ENABLED', value: $enabled ? 'false' : 'true');
    setEnvironmentVariable(name: 'NIGHTWATCH_TOKEN', value: 'application-token');
    setEnvironmentVariable(name: 'NIGHTWATCH_INGEST_URI', value: 'ingest.example.com:2407');
    setEnvironmentVariable(name: 'NIGHTWATCH_INGEST_EVENT_BUFFER', value: '7');
    config()->set('nightwatch', []);
    config()->set('firewatch.enabled', $enabled);

    registerFirewatch();
    app()->register(NightwatchServiceProvider::class, force: true);

    expect(config()->get(['nightwatch.enabled', 'nightwatch.token', 'nightwatch.ingest.uri', 'nightwatch.ingest.event_buffer']))->toBe($expected);
})->with([
    'Active' => ['enabled' => true, 'expected' => ['nightwatch.enabled' => true, 'nightwatch.token' => 'firewatch', 'nightwatch.ingest.uri' => '127.0.0.1:1', 'nightwatch.ingest.event_buffer' => 500]],
    'Off' => ['enabled' => false, 'expected' => ['nightwatch.enabled' => false, 'nightwatch.token' => 'application-token', 'nightwatch.ingest.uri' => 'ingest.example.com:2407', 'nightwatch.ingest.event_buffer' => '7']],
]);

it('resolves a firewatch: process Off by its first argument that is not an option', function (array $argv, bool $expected) {
    setArgv($argv);

    registerFirewatch();

    expect(config('nightwatch.enabled'))->toBe($expected);
})->with([
    'firewatch:server' => ['argv' => ['artisan', 'firewatch:server'], 'expected' => false],
    'an option before the command' => ['argv' => ['artisan', '--env=local', 'firewatch:server'], 'expected' => false],
    'another command' => ['argv' => ['artisan', 'migrate'], 'expected' => true],
    'another command with a firewatch: argument' => ['argv' => ['artisan', 'queue:work', 'firewatch:server'], 'expected' => true],
    'no command' => ['argv' => ['artisan'], 'expected' => true],
    'only options' => ['argv' => ['artisan', '--version'], 'expected' => true],
]);

it('steps aside in a disallowed environment even in a firewatch: process', function () {
    setArgv(['artisan', 'firewatch:server']);
    config()->set('nightwatch.enabled', true);
    config()->set('firewatch.environments', 'local');

    registerFirewatch();

    expect(config('nightwatch.enabled'))->toBeTrue();
});

it('reports the stepped-aside notice once in a console process', function () {
    config()->set('firewatch.environments', 'local');
    config()->set('firewatch.busy_timeout', 'soon');

    registerFirewatch();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Firewatch is installed but stepped aside in environment `testing`; install with `composer install --no-dev` in production.');
});

it('stays silent about stepping aside in a web process', function () {
    (fn () => $this->isRunningInConsole = false)->call(app());
    config()->set('firewatch.environments', 'local');

    registerFirewatch();

    Exceptions::assertNothingReported();
});

it('registers the three commands and the publish tag only when Active or Off', function (array $config, array $commands, bool $tagged) {
    [$publishes, $publishGroups] = [ServiceProvider::$publishes, ServiceProvider::$publishGroups];
    $this->beforeApplicationDestroyed(fn () => [ServiceProvider::$publishes, ServiceProvider::$publishGroups] = [$publishes, $publishGroups]);
    Application::forgetBootstrappers();
    unset(ServiceProvider::$publishes[FirewatchServiceProvider::class], ServiceProvider::$publishGroups['firewatch-config']);
    config()->set($config);

    registerFirewatch();
    app(Kernel::class)->setArtisan(null);
    $names = array_values(array_filter(array_keys(Artisan::all()), fn (string $name) => str_starts_with($name, 'firewatch:')));

    expect($names)->toEqualCanonicalizing($commands)
        ->and(in_array('firewatch-config', ServiceProvider::publishableGroups(), true))->toBe($tagged);
})->with([
    'Active' => ['config' => [], 'commands' => ['firewatch:server', 'firewatch:doctor', 'firewatch:clear'], 'tagged' => true],
    'Off' => ['config' => ['firewatch.enabled' => false], 'commands' => ['firewatch:server', 'firewatch:doctor', 'firewatch:clear'], 'tagged' => true],
    'stepped aside' => ['config' => ['firewatch.environments' => 'local'], 'commands' => [], 'tagged' => false],
]);

it('loads its language file only when Active or Off', function (array $config, bool $loaded) {
    // The first registration left a callback that adds its namespace to every translator made after it.
    (fn () => $this->afterResolvingCallbacks = [])->call(app());
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');
    Lang::clearResolvedInstance('translator');
    config()->set($config);

    registerFirewatch();

    expect(app('translator')->has('firewatch::messages.no_store'))->toBe($loaded);
})->with([
    'Active' => ['config' => [], 'loaded' => true],
    'Off' => ['config' => ['firewatch.enabled' => false], 'loaded' => true],
    'stepped aside' => ['config' => ['firewatch.environments' => 'local'], 'loaded' => false],
]);

it('swaps Nightwatch\'s ingest for one that stores when Active and one that discards when Off', function (array $config, string $ingest) {
    config()->set($config);
    app()->register(NightwatchServiceProvider::class, force: true);

    registerFirewatch();

    expect(app(Core::class)->ingest)->toBeInstanceOf($ingest);
})->with([
    'Active' => ['config' => [], 'ingest' => FirewatchIngest::class],
    'Off' => ['config' => ['firewatch.enabled' => false], 'ingest' => NullIngest::class],
]);

it('keeps Nightwatch\'s own ingest when stepped aside', function () {
    config()->set('firewatch.environments', 'local');
    app()->register(NightwatchServiceProvider::class, force: true);

    registerFirewatch();

    expect(app(Core::class)->ingest)->toBeInstanceOf(Ingest::class);
});

it('reports once in a console process when the ingest cannot be swapped', function (Closure $breakCore, string $reason) {
    $breakCore();

    registerFirewatch();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === "Firewatch left Nightwatch's ingest in place: {$reason}.");
})->with([
    'core not registered' => ['breakCore' => fn () => app()->offsetUnset(Core::class), 'reason' => 'its core is not registered'],
    'core without an ingest property' => ['breakCore' => fn () => app()->instance(Core::class, new stdClass), 'reason' => 'its core has no assignable ingest property'],
]);

it('stays silent about an ingest it cannot swap in a web process', function () {
    (fn () => $this->isRunningInConsole = false)->call(app());
    app()->offsetUnset(Core::class);

    registerFirewatch();

    Exceptions::assertNothingReported();
});
