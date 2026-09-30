<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Support\Facades\Exceptions;

function registerFirewatch(): Configuration
{
    app()->register(FirewatchServiceProvider::class, force: true);

    return app(Configuration::class);
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

it('reads each key from its environment variable', function (string $variable, string $value, string $property, mixed $expected) {
    setEnvironmentVariable($variable, $value);
    config()->set('firewatch', []);

    $configuration = registerFirewatch();

    expect($configuration->{$property})->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'enabled' => ['FIREWATCH_ENABLED', 'off', 'enabled', false],
    'environments' => ['FIREWATCH_ENVIRONMENTS', 'local,staging', 'environments', ['local', 'staging']],
    'database' => ['FIREWATCH_DATABASE', '/tmp/firewatch-env/store.sqlite', 'database', '/tmp/firewatch-env/store.sqlite'],
    'busy_timeout' => ['FIREWATCH_BUSY_TIMEOUT', '0', 'busyTimeoutMilliseconds', 0],
    'retention.age' => ['FIREWATCH_RETENTION_AGE', '12h', 'retentionAge', '12h'],
    'retention.records' => ['FIREWATCH_RETENTION_RECORDS', '5000', 'retentionRecords', 5000],
    'deploy' => ['FIREWATCH_DEPLOY', 'v2.0.1', 'deploy', 'v2.0.1'],
    'capture.logs' => ['FIREWATCH_CAPTURE_LOGS', 'false', 'captureLogs', false],
    'capture.request_payload' => ['FIREWATCH_CAPTURE_REQUEST_PAYLOAD', 'no', 'captureRequestPayload', false],
    'capture.redact_payload_fields' => ['FIREWATCH_REDACT_PAYLOAD_FIELDS', 'password, card_number', 'redactPayloadFields', ['password', 'card_number']],
    'capture.redact_headers' => ['FIREWATCH_REDACT_HEADERS', 'X-Api-Key', 'redactHeaders', ['X-Api-Key']],
]);

it('resolves the package defaults when nothing is published or set', function () {
    config()->set('firewatch', []);

    $configuration = registerFirewatch();

    expect($configuration)->toHaveProperties([
        'enabled' => true,
        'environments' => ['local', 'testing'],
        'database' => storage_path('firewatch/firewatch.sqlite'),
        'busyTimeoutMilliseconds' => 300,
        'retentionAge' => '7d',
        'retentionRecords' => 100000,
        'deploy' => null,
        'captureLogs' => true,
        'captureRequestPayload' => true,
        'redactPayloadFields' => [],
        'redactHeaders' => [],
        'budgets' => [],
        'issues' => [],
    ]);
});

it('fills the keys a partially published file leaves out', function () {
    config()->set('firewatch', ['retention' => ['age' => '3d'], 'capture' => ['redact_headers' => ['Cookie']]]);

    $configuration = registerFirewatch();

    expect($configuration)->toHaveProperties([
        'retentionAge' => '3d',
        'retentionRecords' => 100000,
        'redactHeaders' => ['Cookie'],
        'captureLogs' => true,
        'captureRequestPayload' => true,
        'environments' => ['local', 'testing'],
        'issues' => [],
    ]);
});

it('publishes the config file with the firewatch-config tag', function () {
    app()->useConfigPath($this->storeDirectory.'/config');
    registerFirewatch();

    $exitCode = $this->artisan('vendor:publish', ['--tag' => 'firewatch-config'])->run();

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($this->storeDirectory.'/config/firewatch.php'))->toBe(file_get_contents(__DIR__.'/../../config/firewatch.php'));
});

it('reports every configuration issue once through the exception handler in a console process', function () {
    config()->set('firewatch.busy_timeout', 'soon');
    config()->set('firewatch.environments', 'local,prod*');

    registerFirewatch();
    app(Configuration::class);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === "Firewatch configuration: firewatch.environments: \"prod*\" is not an environment name (letters, digits, _ . -); dropped\nfirewatch.busy_timeout: \"soon\" is not an integer from 0 to 5000; using 300");
});

it('reports nothing for a valid configuration', function () {
    config()->set('firewatch.busy_timeout', 500);

    registerFirewatch();

    Exceptions::assertNothingReported();
});

it('stays silent about configuration issues in a web process', function () {
    (fn () => $this->isRunningInConsole = false)->call(app());
    config()->set('firewatch.busy_timeout', 'soon');

    $configuration = registerFirewatch();

    expect($configuration->busyTimeoutMilliseconds)->toBe(300);
    Exceptions::assertNothingReported();
});
