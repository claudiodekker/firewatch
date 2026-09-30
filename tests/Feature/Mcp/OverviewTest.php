<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Store\Writer;

it('answers that no store exists yet, with the store clock', function () {
    $this->travelTo('2026-09-30 14:00:00.250000');
    $path = app(Configuration::class)->database;

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(implode("\n", [
        '## overview',
        __('firewatch::messages.store_clock', ['time' => '2026-09-30 14:00:00.250000', 'epoch' => '1790776800.25']),
        __('firewatch::messages.no_store', ['path' => $path]),
    ]));
});

it('answers that the store is empty when it holds no records', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn () => null);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_empty', ['path' => $path]));
});

it('counts a request the application served', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee('- **request**: 1');
});

it('creates nothing where the store would be', function () {
    $path = app(Configuration::class)->database;

    FirewatchServer::tool(Overview::class);

    expect(dirname($path))->not->toBeDirectory();
});
