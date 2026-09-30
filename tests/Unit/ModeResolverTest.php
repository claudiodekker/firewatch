<?php

use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Mode;
use ClaudioDekker\Firewatch\ModeResolver;

it('resolves Off when SQLite is missing or below 3.38.0', function (?string $sqliteVersion, Mode $expected) {
    $configuration = (new ConfigurationNormaliser(basePath: '/app', publicPath: '/app/public', storagePath: '/app/storage'))->resolve([]);

    $mode = (new ModeResolver)->resolve($configuration, environment: 'local', argv: ['artisan'], sqliteVersion: $sqliteVersion);

    expect($mode)->toBe($expected);
})->with([
    'missing ext-sqlite3' => ['sqliteVersion' => null, 'expected' => Mode::OFF],
    '3.37.2' => ['sqliteVersion' => '3.37.2', 'expected' => Mode::OFF],
    '3.38.0' => ['sqliteVersion' => '3.38.0', 'expected' => Mode::ACTIVE],
    '3.51.3' => ['sqliteVersion' => '3.51.3', 'expected' => Mode::ACTIVE],
]);

it('steps aside in a disallowed environment even without a usable SQLite', function () {
    $configuration = (new ConfigurationNormaliser(basePath: '/app', publicPath: '/app/public', storagePath: '/app/storage'))->resolve([]);

    $mode = (new ModeResolver)->resolve($configuration, environment: 'production', argv: ['artisan'], sqliteVersion: null);

    expect($mode)->toBe(Mode::STEPPED_ASIDE);
});
