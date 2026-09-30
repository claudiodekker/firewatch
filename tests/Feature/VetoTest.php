<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Ingest;
use Laravel\Nightwatch\RecordsBuffer;

it('keeps a real sensor\'s digest from reaching Nightwatch\'s stream when its own ingest stays in place', function () {
    $streams = 0;
    $ingest = new Ingest(
        transmitTo: '127.0.0.1:1',
        connectionTimeout: 0.5,
        timeout: 0.5,
        streamFactory: function () use (&$streams) {
            $streams++;

            throw new RuntimeException('Nightwatch opened a stream.');
        },
        buffer: new RecordsBuffer(length: 500),
        tokenHash: 'firewat',
        events: app('events'),
    );
    app(Core::class)->ingest = $ingest;

    DB::select('select 1');
    Nightwatch::digest();

    expect($streams)->toBe(0)
        ->and($ingest->buffer->count())->toBe(0);
});

it('vetoes every batch when Active', function (array $records) {
    app('events')->forget(IngestingEvents::class);

    registerFirewatch();

    $answer = app('events')->until(new IngestingEvents($records));

    expect($answer)->toBeFalse();
})->with([
    'a request' => ['records' => [['t' => 'request', 'v' => 1]]],
    'a user' => ['records' => [['t' => 'user', 'v' => 1]]],
    'a mixed batch' => ['records' => [['t' => 'request', 'v' => 1], ['t' => 'user', 'v' => 1], ['t' => 'query', 'v' => 1]]],
]);

it('registers no veto when Off or stepped aside', function (array $config) {
    app('events')->forget(IngestingEvents::class);
    config()->set($config);

    registerFirewatch();

    expect(app('events')->hasListeners(IngestingEvents::class))->toBeFalse();
})->with([
    'Off' => ['config' => ['firewatch.enabled' => false]],
    'stepped aside' => ['config' => ['firewatch.environments' => 'local']],
]);
