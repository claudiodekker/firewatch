<?php

use ClaudioDekker\Firewatch\Tests\Support\PackageSource;

test('the package requires exactly what the install decision lists', function () {
    $require = PackageSource::manifest()['require'];

    expect($require)->toBe([
        'php' => '^8.3',
        'ext-mbstring' => '*',
        'ext-ctype' => '*',
        'ext-sqlite3' => '*',
        'illuminate/console' => '^12.0|^13.0',
        'illuminate/contracts' => '^12.0|^13.0',
        'illuminate/database' => '^12.0|^13.0',
        'illuminate/events' => '^12.0|^13.0',
        'illuminate/http' => '^12.0|^13.0',
        'illuminate/json-schema' => '^12.0|^13.0',
        'illuminate/queue' => '^12.0|^13.0',
        'illuminate/support' => '^12.0|^13.0',
        'laravel/mcp' => '^1.0.2',
        'laravel/nightwatch' => '^1.30.2',
    ]);
});

test('every Illuminate namespace and mb_ or ctype_ function family the source uses is required', function () {
    $require = array_keys(PackageSource::manifest()['require']);
    $needed = PackageSource::requiredPackages();

    expect($needed)->toBe([
        'ext-ctype',
        'ext-mbstring',
        'illuminate/console',
        'illuminate/contracts',
        'illuminate/database',
        'illuminate/events',
        'illuminate/http',
        'illuminate/json-schema',
        'illuminate/queue',
        'illuminate/support',
    ]);
    expect(array_values(array_diff($needed, $require)))->toBe([]);
});

test('the package suggests nothing', function () {
    $manifest = PackageSource::manifest();

    expect($manifest)->not->toHaveKey('suggest');
});

test('the provider is auto-discovered and Nightwatch is not', function () {
    $laravel = PackageSource::manifest()['extra']['laravel'];

    expect($laravel)->toBe([
        'providers' => ['ClaudioDekker\\Firewatch\\FirewatchServiceProvider'],
        'dont-discover' => ['laravel/nightwatch'],
    ]);
});
