<?php

use Illuminate\Support\Facades\Exceptions;

it('reads each key from its environment variable', function (string $variable, string $value, string $property, mixed $expected) {
    setEnvironmentVariable($variable, $value);
    config()->set('firewatch', []);

    $configuration = registerFirewatch();

    expect($configuration->{$property})->toBe($expected)
        ->and($configuration->issues)->toBe([]);
})->with([
    'enabled' => ['variable' => 'FIREWATCH_ENABLED', 'value' => 'off', 'property' => 'enabled', 'expected' => false],
    'environments' => ['variable' => 'FIREWATCH_ENVIRONMENTS', 'value' => 'local,staging', 'property' => 'environments', 'expected' => ['local', 'staging']],
    'database' => ['variable' => 'FIREWATCH_DATABASE', 'value' => '/tmp/firewatch-env/store.sqlite', 'property' => 'database', 'expected' => '/tmp/firewatch-env/store.sqlite'],
    'busy_timeout' => ['variable' => 'FIREWATCH_BUSY_TIMEOUT', 'value' => '0', 'property' => 'busyTimeoutMilliseconds', 'expected' => 0],
    'retention.age' => ['variable' => 'FIREWATCH_RETENTION_AGE', 'value' => '12h', 'property' => 'retentionAge', 'expected' => '12h'],
    'retention.records' => ['variable' => 'FIREWATCH_RETENTION_RECORDS', 'value' => '5000', 'property' => 'retentionRecords', 'expected' => 5000],
    'deploy' => ['variable' => 'FIREWATCH_DEPLOY', 'value' => 'v2.0.1', 'property' => 'deploy', 'expected' => 'v2.0.1'],
    'capture.logs' => ['variable' => 'FIREWATCH_CAPTURE_LOGS', 'value' => 'false', 'property' => 'captureLogs', 'expected' => false],
    'capture.request_payload' => ['variable' => 'FIREWATCH_CAPTURE_REQUEST_PAYLOAD', 'value' => 'no', 'property' => 'captureRequestPayload', 'expected' => false],
    'capture.redact_payload_fields' => ['variable' => 'FIREWATCH_REDACT_PAYLOAD_FIELDS', 'value' => 'password, card_number', 'property' => 'redactPayloadFields', 'expected' => ['password', 'card_number']],
    'capture.redact_headers' => ['variable' => 'FIREWATCH_REDACT_HEADERS', 'value' => 'X-Api-Key', 'property' => 'redactHeaders', 'expected' => ['X-Api-Key']],
]);

it('resolves the package defaults when nothing is published or set', function () {
    $this->setStorePath(null);
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

it('reads a nested key from its environment variable when the published file leaves it out', function () {
    setEnvironmentVariable('FIREWATCH_RETENTION_RECORDS', '5000');
    config()->set('firewatch', ['retention' => ['age' => '3d']]);

    $configuration = registerFirewatch();

    expect($configuration)->toHaveProperties(['retentionAge' => '3d', 'retentionRecords' => 5000, 'issues' => []]);
});

it('lets the environment variable win over the published file', function () {
    app()->useConfigPath($this->storeDirectory.'/config');
    registerFirewatch();
    $this->artisan('vendor:publish', ['--tag' => 'firewatch-config'])->run();
    setEnvironmentVariable('FIREWATCH_BUSY_TIMEOUT', '0');
    config()->set('firewatch', require $this->storeDirectory.'/config/firewatch.php');

    $configuration = registerFirewatch();

    expect($configuration->busyTimeoutMilliseconds)->toBe(0);
});

it('publishes the config file with the firewatch-config tag', function () {
    app()->useConfigPath($this->storeDirectory.'/config');
    registerFirewatch();

    $this->artisan('vendor:publish', ['--tag' => 'firewatch-config'])->run();

    expect(file_get_contents($this->storeDirectory.'/config/firewatch.php'))->toBe(file_get_contents(__DIR__.'/../../config/firewatch.php'));
});

it('reports every configuration issue once through the exception handler in a console process', function () {
    config()->set('firewatch.busy_timeout', 'soon');
    config()->set('firewatch.environments', 'testing,prod*');

    registerFirewatch();

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
