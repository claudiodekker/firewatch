<?php

use ClaudioDekker\Firewatch\Tests\Support\PackageSource;

test('the package requires exactly what the install decision lists', function () {
    $require = PackageSource::manifest()['require'];

    expect($require)->toBe([
        'php' => '^8.3',
        'ext-sqlite3' => '*',
        'illuminate/support' => '^12.0|^13.0',
        'laravel/mcp' => '^1.0.1',
        'laravel/nightwatch' => '^1.30.2',
    ]);
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
