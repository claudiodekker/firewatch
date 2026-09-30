<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;

it('answers that no store exists yet, with the store clock', function () {
    $this->travelTo('2026-09-30 14:00:00.250000');
    $path = app(Configuration::class)->database;

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(implode("\n", [
        '## overview',
        __('firewatch::messages.no_store', ['path' => $path]),
        __('firewatch::messages.store_clock', ['time' => '2026-09-30 14:00:00.250000', 'epoch' => '1790776800.25']),
    ]));
});

it('creates nothing where the store would be', function () {
    $path = app(Configuration::class)->database;

    FirewatchServer::tool(Overview::class);

    expect(dirname($path))->not->toBeDirectory();
});
