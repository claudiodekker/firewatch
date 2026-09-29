<?php

function manifest(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
}

test('the package requires exactly what the install decision lists', function () {
    $require = manifest()['require'];

    expect($require)->toBe([
        'php' => '^8.3',
        'ext-sqlite3' => '*',
        'illuminate/support' => '^12.0|^13.0',
        'laravel/mcp' => '^1.0.1',
        'laravel/nightwatch' => '^1.30.2',
    ]);
});

test('the package suggests nothing', function () {
    $manifest = manifest();

    expect($manifest)->not->toHaveKey('suggest');
});

test('the provider is auto-discovered and Nightwatch is not', function () {
    $laravel = manifest()['extra']['laravel'];

    expect($laravel)->toBe([
        'providers' => ['ClaudioDekker\\Firewatch\\FirewatchServiceProvider'],
        'dont-discover' => ['laravel/nightwatch'],
    ]);
});
