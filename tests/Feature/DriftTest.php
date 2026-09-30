<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * @return list<array<string, mixed>>
 */
function queryStore(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

/**
 * @return list<array<string, mixed>>
 */
function driftCounts(): array
{
    return queryStore('SELECT kind, type, v, detail, count FROM drift ORDER BY kind, type, v, detail');
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function cacheEvent(array $fields = []): array
{
    $record = [
        'v' => 1,
        't' => 'cache-event',
        'timestamp' => 1767225600.25,
        'deploy' => 'v1.2.3',
        'server' => 'web-1',
        '_group' => str_repeat('a', 32),
        'trace_id' => 'trace-1',
        'execution_source' => 'request',
        'execution_id' => 'trace-1',
        'execution_preview' => 'GET /',
        'execution_stage' => 'action',
        'user' => '',
        'store' => 'array',
        'key' => 'first',
        'type' => 'miss',
        'duration' => 120,
        'ttl' => 0,
        ...$fields,
    ];

    // A null field is one the wire omitted.
    return array_filter($record, fn (mixed $value) => $value !== null);
}

/**
 * @param  list<array<mixed>>  $records
 */
function ingestBatch(array $records): void
{
    foreach ($records as $record) {
        app(Core::class)->ingest->write($record);
    }

    Nightwatch::digest();
}

function installNightwatch(string $version, bool $registeredFirst = false): void
{
    app()->instance(NightwatchInstall::class, new NightwatchInstall(version: $version, registeredFirst: $registeredFirst));

    app(Core::class)->ingest = app(Ingest::class);
}

it('counts nothing for a record that matches the contract table', function () {
    ingestBatch([cacheEvent()]);

    expect(driftCounts())->toBe([]);
});

it('counts a record of an unknown type and stores it as sent', function () {
    ingestBatch([cacheEvent(['t' => 'future-type', 'colour' => 'red'])]);

    expect(driftCounts())->toBe([['kind' => 'unknown_type', 'type' => 'future-type', 'v' => '1', 'detail' => '', 'count' => 1]])
        ->and(queryStore("SELECT type, data ->> '$.colour' AS colour FROM records"))->toBe([['type' => 'future-type', 'colour' => 'red']]);
});

it('counts a record of an unknown version without judging its fields', function () {
    ingestBatch([cacheEvent(['v' => 2, 'ttl' => null, 'colour' => 'red'])]);

    expect(driftCounts())->toBe([['kind' => 'unknown_version', 'type' => 'cache-event', 'v' => '2', 'detail' => '', 'count' => 1]])
        ->and(queryStore('SELECT v, key FROM cache_events'))->toBe([['v' => 2, 'key' => 'first']]);
});

it('counts a field the contract table does not know and keeps it in data', function () {
    ingestBatch([cacheEvent(['colour' => 'red'])]);

    expect(driftCounts())->toBe([['kind' => 'unknown_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'colour', 'count' => 1]])
        ->and(queryStore("SELECT data ->> '$.colour' AS colour FROM records"))->toBe([['colour' => 'red']]);
});

it('counts a missing field and stores it as NULL', function () {
    ingestBatch([cacheEvent(['ttl' => null])]);

    expect(driftCounts())->toBe([['kind' => 'missing_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'ttl', 'count' => 1]])
        ->and(queryStore('SELECT ttl FROM cache_events'))->toBe([['ttl' => null]]);
});

it('counts a field of a type the contract table does not accept and stores it as sent', function () {
    ingestBatch([cacheEvent(['duration' => 'slow'])]);

    expect(driftCounts())->toBe([['kind' => 'structure', 'type' => 'cache-event', 'v' => '1', 'detail' => 'duration: expected integer, got string', 'count' => 1]])
        ->and(queryStore('SELECT duration FROM cache_events'))->toBe([['duration' => 'slow']]);
});

it('accepts a whole number where the contract table expects a number', function () {
    ingestBatch([cacheEvent(['timestamp' => 1767225600])]);

    expect(driftCounts())->toBe([])
        ->and(queryStore('SELECT started_at FROM cache_events'))->toBe([['started_at' => 1767225600.0]]);
});

it('counts a timestamp that is not a number and starts the record at NULL', function () {
    ingestBatch([cacheEvent(['timestamp' => '1767225600.25'])]);

    expect(driftCounts())->toBe([['kind' => 'structure', 'type' => 'cache-event', 'v' => '1', 'detail' => 'timestamp: expected number, got string', 'count' => 1]])
        ->and(queryStore('SELECT started_at FROM records'))->toBe([['started_at' => null]]);
});

it('counts the timestamp that is not a number of a record of an unknown type', function () {
    ingestBatch([cacheEvent(['t' => 'future-type', 'timestamp' => true])]);

    expect(driftCounts())->toBe([
        ['kind' => 'structure', 'type' => 'future-type', 'v' => '1', 'detail' => 'timestamp: expected number, got boolean', 'count' => 1],
        ['kind' => 'unknown_type', 'type' => 'future-type', 'v' => '1', 'detail' => '', 'count' => 1],
    ])->and(queryStore('SELECT started_at FROM records'))->toBe([['started_at' => null]]);
});

it('stores a record it cannot read as an error placeholder and counts it', function (array $record, ?string $type, string $v, string $detail, string $error) {
    ingestBatch([$record]);

    expect(driftCounts())->toBe([['kind' => 'structure', 'type' => $type ?? '', 'v' => $v, 'detail' => $detail, 'count' => 1]])
        ->and(queryStore('SELECT type, v, started_at, duration, trace_id, execution_id, source, data FROM records'))->toBe([
            ['type' => $type, 'v' => null, 'started_at' => null, 'duration' => null, 'trace_id' => null, 'execution_id' => null, 'source' => null, 'data' => json_encode(['error' => $error])],
        ]);
})->with([
    'not an object' => ['record' => ['cache-event', 1], 'type' => null, 'v' => '', 'detail' => 'record: not an object', 'error' => 'record: not an object'],
    'an empty record' => ['record' => [], 'type' => null, 'v' => '', 'detail' => 'record: not an object', 'error' => 'record: not an object'],
    'no type' => ['record' => cacheEvent(['t' => null]), 'type' => null, 'v' => '1', 'detail' => 't: missing', 'error' => 't: missing'],
    'a type that is not a string' => ['record' => cacheEvent(['t' => 7]), 'type' => null, 'v' => '1', 'detail' => 't: expected string, got integer', 'error' => 't: expected string, got integer'],
    'unencodable' => ['record' => cacheEvent(['duration' => INF]), 'type' => 'cache-event', 'v' => '1', 'detail' => 'record: unencodable', 'error' => 'Inf and NaN cannot be JSON encoded'],
]);

it('stores the rest of a batch beside a record it cannot read', function () {
    ingestBatch([cacheEvent(['duration' => INF]), cacheEvent()]);

    expect(queryStore('SELECT type, duration FROM records ORDER BY id'))->toBe([
        ['type' => 'cache-event', 'duration' => null],
        ['type' => 'cache-event', 'duration' => 120],
    ]);
});

it('adds up the drift of a batch into one count per kind, type, version and detail', function () {
    ingestBatch([cacheEvent(['colour' => 'red']), cacheEvent(['colour' => 'blue']), cacheEvent(['v' => 2, 'colour' => 'red'])]);

    expect(driftCounts())->toBe([
        ['kind' => 'unknown_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'colour', 'count' => 2],
        ['kind' => 'unknown_version', 'type' => 'cache-event', 'v' => '2', 'detail' => '', 'count' => 1],
    ]);
});

it('adds each batch\'s drift to the count, keeping when it was first seen', function () {
    $this->travelTo(CarbonImmutable::createFromTimestamp(1767225600.5));
    ingestBatch([cacheEvent(['colour' => 'red'])]);

    $this->travelTo(CarbonImmutable::createFromTimestamp(1767225900.25));
    ingestBatch([cacheEvent(['colour' => 'red']), cacheEvent(['colour' => 'red'])]);

    expect(queryStore('SELECT count, first_seen, last_seen FROM drift'))->toBe([['count' => 3, 'first_seen' => 1767225600.5, 'last_seen' => 1767225900.25]]);
});

it('folds new drift into one overflow row per kind once 500 are counted', function (int $fields, array $counts) {
    $unknown = [];

    for ($field = 1; $field <= $fields; $field++) {
        $unknown["field_{$field}"] = 'red';
    }

    ingestBatch([cacheEvent([...$unknown, 'ttl' => null])]);

    expect(queryStore('SELECT kind, type, v, detail, count FROM drift WHERE detail NOT LIKE \'field\_%\' ESCAPE \'\\\' ORDER BY kind'))->toBe($counts)
        ->and(queryStore("SELECT count(*) AS rows FROM drift WHERE detail LIKE 'field\_%' ESCAPE '\\'"))->toBe([['rows' => min($fields, 499)]]);
})->with([
    'room for all' => ['fields' => 499, 'counts' => [
        ['kind' => 'missing_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'ttl', 'count' => 1],
    ]],
    'one too many' => ['fields' => 500, 'counts' => [
        ['kind' => 'missing_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'ttl', 'count' => 1],
        ['kind' => 'unknown_field', 'type' => '', 'v' => '', 'detail' => '... [overflow]', 'count' => 1],
    ]],
]);

it('keeps counting drift it already holds once 500 are counted', function () {
    $unknown = [];

    for ($field = 1; $field <= 500; $field++) {
        $unknown["field_{$field}"] = 'red';
    }

    ingestBatch([cacheEvent($unknown)]);

    ingestBatch([cacheEvent(['field_1' => 'red', 'field_501' => 'red'])]);

    expect(queryStore("SELECT detail, count FROM drift WHERE detail IN ('field_1', 'field_501', '... [overflow]') ORDER BY detail"))->toBe([
        ['detail' => '... [overflow]', 'count' => 1],
        ['detail' => 'field_1', 'count' => 2],
    ]);
});

it('counts one version drift per batch on an unverified Nightwatch release', function () {
    installNightwatch('v1.31.0');

    ingestBatch([cacheEvent(), cacheEvent()]);
    ingestBatch([cacheEvent()]);

    expect(driftCounts())->toBe([['kind' => 'version', 'type' => '', 'v' => '', 'detail' => 'v1.31.0', 'count' => 2]]);
});

it('keeps the Nightwatch release and whether it is verified from the latest batch', function () {
    installNightwatch('v1.30.2');
    ingestBatch([cacheEvent()]);

    installNightwatch('dev-main');
    ingestBatch([cacheEvent()]);

    expect(queryStore("SELECT key, value FROM meta WHERE key LIKE 'nightwatch_%' ORDER BY key"))->toBe([
        ['key' => 'nightwatch_verified', 'value' => '0'],
        ['key' => 'nightwatch_version', 'value' => 'dev-main'],
    ]);
});

it('counts the provider order once, on the process\'s first batch, when Nightwatch\'s provider registered first', function (bool $registeredFirst, array $counts) {
    installNightwatch('v1.30.2', registeredFirst: $registeredFirst);

    ingestBatch([cacheEvent()]);
    ingestBatch([cacheEvent()]);

    expect(driftCounts())->toBe($counts);
})->with([
    'registered first' => ['registeredFirst' => true, 'counts' => [['kind' => 'structure', 'type' => '', 'v' => '', 'detail' => 'provider order', 'count' => 1]]],
    'registered by Firewatch' => ['registeredFirst' => false, 'counts' => []],
]);

it('counts no drift for a known-gap counter the sensors start to fill', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/lazy', fn () => app(Core::class)->executionState->lazyLoads = 3);

    $this->get('/lazy');

    expect(queryStore('SELECT lazy_loads FROM requests'))->toBe([['lazy_loads' => 3]])
        ->and(driftCounts())->toBe([]);
});
