<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;

/**
 * @return list<array<string, mixed>>
 */
function driftCounts(): array
{
    return storeRows('SELECT kind, type, v, detail, count FROM drift ORDER BY kind, type, v, detail');
}

function installNightwatch(string $version, bool $registeredFirst = false): void
{
    app()->instance(NightwatchInstall::class, new NightwatchInstall(version: $version, registeredFirst: $registeredFirst));

    app(Core::class)->ingest = app(Ingest::class);
}

it('counts nothing for a record that matches the contract table', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([]);
});

it('counts a record of an unknown type and stores it as sent', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['t' => 'future-type', 'colour' => 'red'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'unknown_type', 'type' => 'future-type', 'v' => '1', 'detail' => '', 'count' => 1]])
        ->and(storeRows("SELECT type, data ->> '$.colour' AS colour FROM records"))->toBe([['type' => 'future-type', 'colour' => 'red']]);
});

it('counts a record of an unknown version without judging its fields', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['v' => 2, 'colour' => 'red'])->without('ttl')]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'unknown_version', 'type' => 'cache-event', 'v' => '2', 'detail' => '', 'count' => 1]])
        ->and(storeRows('SELECT v, key FROM cache_events'))->toBe([['v' => 2, 'key' => 'orders']]);
});

it('counts a field the contract table does not know and keeps it in data', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'unknown_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'colour', 'count' => 1]])
        ->and(storeRows("SELECT data ->> '$.colour' AS colour FROM records"))->toBe([['colour' => 'red']]);
});

it('counts a missing field and stores it as NULL', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->without('ttl')]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'missing_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'ttl', 'count' => 1]])
        ->and(storeRows('SELECT ttl FROM cache_events'))->toBe([['ttl' => null]]);
});

it('counts a field of a type the contract table does not accept and stores it as sent', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['duration' => 'slow'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'structure', 'type' => 'cache-event', 'v' => '1', 'detail' => 'duration: expected integer, got string', 'count' => 1]])
        ->and(storeRows('SELECT duration FROM cache_events'))->toBe([['duration' => 'slow']]);
});

it('accepts a whole number where the contract table expects a number', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['timestamp' => 1767225600])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([])
        ->and(storeRows('SELECT started_at FROM cache_events'))->toBe([['started_at' => 1767225600.0]]);
});

it('counts a timestamp that is not a number and starts the record at NULL', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['timestamp' => '1767225600.25'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'structure', 'type' => 'cache-event', 'v' => '1', 'detail' => 'timestamp: expected number, got string', 'count' => 1]])
        ->and(storeRows('SELECT started_at FROM records'))->toBe([['started_at' => null]]);
});

it('counts the timestamp that is not a number of a record of an unknown type', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['t' => 'future-type', 'timestamp' => true])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([
        ['kind' => 'structure', 'type' => 'future-type', 'v' => '1', 'detail' => 'timestamp: expected number, got boolean', 'count' => 1],
        ['kind' => 'unknown_type', 'type' => 'future-type', 'v' => '1', 'detail' => '', 'count' => 1],
    ])->and(storeRows('SELECT started_at FROM records'))->toBe([['started_at' => null]]);
});

it('stores a record it cannot read as an error placeholder and counts it', function (RecordBuilder|array $record, ?string $type, string $v, string $detail, string $error) {
    ingest([$record]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'structure', 'type' => $type ?? '', 'v' => $v, 'detail' => $detail, 'count' => 1]])
        ->and(storeRows('SELECT type, v, started_at, duration, trace_id, execution_id, source, data FROM records'))->toBe([
            ['type' => $type, 'v' => null, 'started_at' => null, 'duration' => null, 'trace_id' => null, 'execution_id' => null, 'source' => null, 'data' => json_encode(['error' => $error])],
        ]);
})->with([
    'not an object' => ['record' => ['cache-event', 1], 'type' => null, 'v' => '', 'detail' => 'record: not an object', 'error' => 'record: not an object'],
    'an empty record' => ['record' => [], 'type' => null, 'v' => '', 'detail' => 'record: not an object', 'error' => 'record: not an object'],
    'no type' => ['record' => syntheticRecord(RecordType::CACHE_EVENT)->without('t'), 'type' => null, 'v' => '1', 'detail' => 't: missing', 'error' => 't: missing'],
    'a type that is not a string' => ['record' => syntheticRecord(RecordType::CACHE_EVENT)->with(['t' => 7]), 'type' => null, 'v' => '1', 'detail' => 't: expected string, got integer', 'error' => 't: expected string, got integer'],
    'unencodable' => ['record' => syntheticRecord(RecordType::CACHE_EVENT)->with(['duration' => INF]), 'type' => 'cache-event', 'v' => '1', 'detail' => 'record: unencodable', 'error' => 'Inf and NaN cannot be JSON encoded'],
]);

it('stores the rest of a batch beside a record it cannot read', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['duration' => INF]), syntheticRecord(RecordType::CACHE_EVENT)]);

    expect(storeRows('SELECT type, duration FROM records ORDER BY id'))->toBe([
        ['type' => 'cache-event', 'duration' => null],
        ['type' => 'cache-event', 'duration' => 1000],
    ]);
});

it('stores a list or an object sent for a column as its JSON and counts it, beside the rest of the batch', function (string $field, string $column, mixed $value, string $stored, string $detail) {
    ingest([syntheticRecord(RecordType::CACHE_EVENT), syntheticRecord(RecordType::CACHE_EVENT)->with([$field => $value]), syntheticRecord(RecordType::CACHE_EVENT)]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'structure', 'type' => 'cache-event', 'v' => '1', 'detail' => $detail, 'count' => 1]])
        ->and(storeRows('SELECT count(*) AS records FROM records'))->toBe([['records' => 3]])
        ->and(storeRows("SELECT {$column} AS value FROM records ORDER BY id LIMIT 1 OFFSET 1"))->toBe([['value' => $stored]]);
})->with([
    'a list for a string' => ['field' => 'trace_id', 'column' => 'trace_id', 'value' => ['a'], 'stored' => '["a"]', 'detail' => 'trace_id: expected string, got array'],
    'an object for a string' => ['field' => 'user', 'column' => 'user_id', 'value' => ['id' => '7'], 'stored' => '{"id":"7"}', 'detail' => 'user: expected string, got object'],
    'a list for an integer' => ['field' => 'duration', 'column' => 'duration', 'value' => [1], 'stored' => '[1]', 'detail' => 'duration: expected integer, got array'],
]);

it('stores a list or an object sent as the name of a user as its JSON, beside the rest of the batch', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT), syntheticRecord(RecordType::USER)->with(['name' => ['first' => 'Taylor'], 'username' => ['taylor']]), syntheticRecord(RecordType::CACHE_EVENT)]);

    expect(storeRows('SELECT count(*) AS records FROM records'))->toBe([['records' => 2]])
        ->and(storeRows('SELECT id, name, username FROM users'))->toBe([['id' => '7', 'name' => '{"first":"Taylor"}', 'username' => '["taylor"]']]);
});

it('adds up the drift of a batch into one count per kind, type, version and detail', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red']), syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'blue']), syntheticRecord(RecordType::CACHE_EVENT)->with(['v' => 2, 'colour' => 'red'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([
        ['kind' => 'unknown_field', 'type' => 'cache-event', 'v' => '1', 'detail' => 'colour', 'count' => 2],
        ['kind' => 'unknown_version', 'type' => 'cache-event', 'v' => '2', 'detail' => '', 'count' => 1],
    ]);
});

it('adds each batch\'s drift to the count, keeping when it was first seen', function () {
    $this->travelTo(CarbonImmutable::createFromTimestamp(1767225600.5));
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red'])]);

    $this->travelTo(CarbonImmutable::createFromTimestamp(1767225900.25));
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red']), syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red'])]);

    expect(storeRows('SELECT count, first_seen, last_seen FROM drift'))->toBe([['count' => 3, 'first_seen' => 1767225600.5, 'last_seen' => 1767225900.25]]);
});

it('folds new drift into one overflow row per kind once 500 are counted', function (int $fields, array $counts) {
    $unknown = [];

    for ($field = 1; $field <= $fields; $field++) {
        $unknown["field_{$field}"] = 'red';
    }

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with($unknown)->without('ttl')]);

    expect(storeRows('SELECT kind, type, v, detail, count FROM drift WHERE detail NOT LIKE \'field\_%\' ESCAPE \'\\\' ORDER BY kind'))->toBe($counts)
        ->and(storeRows("SELECT count(*) AS rows FROM drift WHERE detail LIKE 'field\_%' ESCAPE '\\'"))->toBe([['rows' => min($fields, 499)]]);
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

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with($unknown)]);

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['field_1' => 'red', 'field_501' => 'red'])]);

    expect(storeRows("SELECT detail, count FROM drift WHERE detail IN ('field_1', 'field_501', '... [overflow]') ORDER BY detail"))->toBe([
        ['detail' => '... [overflow]', 'count' => 1],
        ['detail' => 'field_1', 'count' => 2],
    ]);
});

it('counts one version drift per batch on an unverified Nightwatch release', function () {
    installNightwatch('v1.31.0');

    ingest([syntheticRecord(RecordType::CACHE_EVENT), syntheticRecord(RecordType::CACHE_EVENT)]);
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([['kind' => 'version', 'type' => '', 'v' => '', 'detail' => 'v1.31.0', 'count' => 2]]);
});

it('counts no version drift on a release of the verified line', function () {
    installNightwatch('v1.30.9');

    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([]);
});

it('counts the drift of a batch in the batch\'s transaction', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);
    $store = new SQLite3(app(Configuration::class)->database);
    $store->exec("CREATE TRIGGER fail_meta BEFORE UPDATE ON meta BEGIN SELECT RAISE(ABORT, 'the disk is full'); END");
    $store->close();
    installNightwatch('dev-main');

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red'])]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe([])
        ->and(storeRows('SELECT count(*) AS records FROM records'))->toBe([['records' => 1]]);
});

it('keeps the Nightwatch release and whether it is verified from the latest batch', function () {
    installNightwatch('v1.30.2');
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    installNightwatch('dev-main');
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    expect(storeRows("SELECT key, value FROM meta WHERE key LIKE 'nightwatch_%' ORDER BY key"))->toBe([
        ['key' => 'nightwatch_verified', 'value' => '0'],
        ['key' => 'nightwatch_version', 'value' => 'dev-main'],
    ]);
});

it('counts the provider order once, on the process\'s first batch, when Nightwatch\'s provider registered first', function (bool $registeredFirst, array $counts) {
    installNightwatch('v1.30.2', registeredFirst: $registeredFirst);

    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);
    ingest([syntheticRecord(RecordType::CACHE_EVENT)]);

    $driftCounts = driftCounts();

    expect($driftCounts)->toBe($counts);
})->with([
    'registered first' => ['registeredFirst' => true, 'counts' => [['kind' => 'structure', 'type' => '', 'v' => '', 'detail' => 'provider order', 'count' => 1]]],
    'registered by Firewatch' => ['registeredFirst' => false, 'counts' => []],
]);

it('counts no drift for a known-gap counter the sensors start to fill', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/lazy', fn () => app(Core::class)->executionState->lazyLoads = 3);

    $this->get('/lazy');

    expect(storeRows('SELECT lazy_loads FROM requests'))->toBe([['lazy_loads' => 3]])
        ->and(driftCounts())->toBe([]);
});
