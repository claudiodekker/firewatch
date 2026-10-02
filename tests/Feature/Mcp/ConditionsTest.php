<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreFailure;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

/**
 * @param  array<string, mixed>  $arguments
 * @return list<array<string, mixed>>
 */
function conditionsOf(array $arguments = []): array
{
    return array_values(array_filter(Envelope::assert(Overview::class, $arguments)['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'condition'));
}

/**
 * @param  list<RecordType>  $types
 * @return list<array<string, mixed>>
 */
function conditionsFor(Markers $markers, array $types = [RecordType::REQUEST], ?float $since = null, ?float $until = null, array $drift = []): array
{
    return app(Conditions::class)->for(new StoreFacts($markers, $drift), $types, Window::between($since, $until, 'UTC'));
}

beforeEach(function () {
    config()->set('app.timezone', 'UTC');
    config()->set('firewatch.retention.age', '7d');
    registerFirewatch();
});

describe('history', function () {
    it('says the window starts before pruned history, with the start and the reason', function () {
        $this->travelTo('2026-09-01 12:00:00');
        app(Writer::class)->transaction(fn () => null);
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1788350400.0])]);
        $this->travelTo('2026-09-30 14:00:00');
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([[
            'id' => 'history-pruned',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.history-pruned', ['from' => '2026-09-02 12:00:00.000000', 'reason' => 'pruned-age']),
            'from_at' => 1788350400.0,
            'reason' => 'pruned-age',
        ]]);
    });

    it('attaches only while the window starts before the history does', function (?float $since, bool $attached) {
        $markers = new Markers(createdAt: 100.0, prunedThrough: 500.0, prunedReason: 'cap');

        expect(array_column(conditionsFor($markers, since: $since), 'id'))->toBe($attached ? ['history-pruned'] : []);
    })->with([
        'no start' => [null, true],
        'a start before it' => [499.999999, true],
        'a start at it' => [500.0, false],
        'a start after it' => [501.0, false],
    ]);

    it('names the reason of the prune that left the history', function (string $by) {
        $conditions = conditionsFor(new Markers(createdAt: 100.0, prunedThrough: 500.0, prunedReason: $by));

        expect($conditions[0])->toMatchArray(['id' => 'history-pruned', 'reason' => "pruned-{$by}"])
            ->and($conditions[0]['message'])->toContain("(pruned-{$by})");
    })->with(['by age' => 'age', 'by cap' => 'cap', 'by size' => 'size']);

    it('says the window starts before cleared history, for a clear of everything or of a type read', function (Markers $markers, string $reason) {
        $conditions = conditionsFor($markers);

        expect($conditions)->toBe([[
            'id' => 'history-cleared',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.history-cleared', ['from' => '1970-01-01 00:08:20.000000']),
            'from_at' => 500.0,
            'reason' => $reason,
        ]]);
    })->with([
        'a clear' => [new Markers(createdAt: 100.0, clearedAt: 500.0), 'cleared'],
        'a clear of the type' => [new Markers(createdAt: 100.0, clearedTypes: ['request' => 500.0]), 'cleared-type'],
    ]);

    it('says nothing of a history that starts at the creation of the store, or of a clear of another type', function (Markers $markers) {
        $conditions = conditionsFor($markers);

        expect($conditions)->toBe([]);
    })->with([
        'created' => [new Markers(createdAt: 100.0)],
        'a clear of another type' => [new Markers(createdAt: 100.0, clearedTypes: ['log' => 500.0])],
        'no markers' => [new Markers],
    ]);
});

describe('a rebuilt store', function () {
    it('says earlier data is gone from the rebuild, with when and why', function (string $why) {
        $conditions = conditionsFor(new Markers(createdAt: 600.0, rebuiltAt: 600.0, rebuiltWhy: $why));

        expect($conditions)->toBe([[
            'id' => 'store-rebuilt',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.store-rebuilt', ['at' => '1970-01-01 00:10:00.000000', 'why' => $why]),
            'rebuilt_at' => 600.0,
            'why' => $why,
        ]]);
    })->with(['a schema change' => 'schema', 'corruption' => 'corrupt']);

    it('attaches only while the window starts before the rebuild', function (?float $since, bool $attached) {
        $markers = new Markers(createdAt: 600.0, rebuiltAt: 600.0, rebuiltWhy: 'schema');

        expect(array_column(conditionsFor($markers, since: $since), 'id'))->toBe($attached ? ['store-rebuilt'] : []);
    })->with([
        'no start' => [null, true],
        'a start before it' => [599.0, true],
        'a start at it' => [600.0, false],
    ]);

    it('stamps a store its schema rebuilt, and states it in the answer', function () {
        $this->travelTo('2026-09-30 14:00:00');
        $path = app(Configuration::class)->database;
        mkdir(dirname($path), recursive: true);
        $store = new SQLite3($path);
        $store->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)');
        $store->close();

        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([[
            'id' => 'store-rebuilt',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.store-rebuilt', ['at' => '2026-09-30 14:00:00.000000', 'why' => 'schema']),
            'rebuilt_at' => 1790776800.0,
            'why' => 'schema',
        ]]);
    });

    it('says nothing of a store that was only created', function () {
        $this->travelTo('2026-09-30 14:00:00');

        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([]);
    });
});

function dropBatch(string $at, FailureKind $kind, int $records): void
{
    test()->travelTo($at);
    is_dir(dirname(app(Configuration::class)->database)) || mkdir(dirname(app(Configuration::class)->database), recursive: true);
    app(FailureLog::class)->record(new StoreFailure($kind, $kind->value), $records);
}

describe('dropped records', function () {
    it('counts what was not stored between the first and the last drop, with the last reason', function () {
        dropBatch('2026-09-30 14:00:00', FailureKind::BUSY, 3);
        dropBatch('2026-09-30 14:00:10', FailureKind::FULL, 4);
        $this->travelTo('2026-09-30 14:01:00');

        $conditions = conditionsOf();

        expect($conditions)->toEqual([[
            'id' => 'records-dropped',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.records-dropped', ['n' => 7, 'from' => '2026-09-30 14:00:00.000000', 'to' => '2026-09-30 14:00:10.000000', 'reason' => 'full']),
            'records' => 7,
            'from_at' => 1790776800.0,
            'to_at' => 1790776810.0,
            'reason' => 'full',
        ]]);
    });

    it('counts only the drops inside the window, the start included and the end not', function (array $arguments, int $records) {
        dropBatch('2026-09-30 14:00:00', FailureKind::BUSY, 3);
        dropBatch('2026-09-30 14:00:10', FailureKind::BUSY, 4);
        $this->travelTo('2026-09-30 14:01:00');

        expect(array_sum(array_column(conditionsOf($arguments), 'records')))->toBe($records);
    })->with([
        'the whole store' => [[], 7],
        'from the first drop' => [['since' => '2026-09-30 14:00:00'], 7],
        'from after the first drop' => [['since' => '2026-09-30 14:00:01'], 4],
        'until the last drop' => [['until' => '2026-09-30 14:00:10'], 3],
        'until after it' => [['until' => '2026-09-30 14:00:11'], 7],
        'between them' => [['since' => '2026-09-30 14:00:01', 'until' => '2026-09-30 14:00:09'], 0],
    ]);

    it('says nothing of a recovery that dropped nothing, or of a store that dropped nothing', function () {
        dropBatch('2026-09-30 14:00:00', FailureKind::BUSY, 0);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([]);

        app(FailureLog::class)->recovered(new StoreFailure(FailureKind::CORRUPT, 'damaged'));

        $conditions = conditionsOf();

        expect($conditions)->toEqual([]);
    });

    it('is attached to an answer about a store that cannot be read', function () {
        dropBatch('2026-09-30 14:00:00', FailureKind::BUSY, 2);

        expect(array_column(conditionsOf(), 'id'))->toBe(['records-dropped']);
    });
});

describe('drift', function () {
    it('counts the drift of a type the call read, by kind, with the last time it was seen', function () {
        $this->travelTo('2026-09-30 14:00:00');
        ingest([
            syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'red']),
            syntheticRecord(RecordType::CACHE_EVENT)->with(['colour' => 'blue']),
        ]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([[
            'id' => 'drift',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.drift', ['count' => 2, 'kind' => 'unknown_field', 'type' => 'cache-event', 'last' => '2026-09-30 14:00:00.000000']),
            'count' => 2,
            'drift_kind' => 'unknown_field',
            'type' => 'cache-event',
            'last_at' => 1790776800.0,
        ]]);
    });

    it('is about the types the call read, and about no type at all never', function () {
        $drift = [
            ['kind' => 'unknown_field', 'type' => 'log', 'count' => 5, 'last_seen' => 10.0],
            ['kind' => 'missing_field', 'type' => 'log', 'count' => 1, 'last_seen' => 30.0],
            ['kind' => 'unknown_field', 'type' => 'request', 'count' => 2, 'last_seen' => 20.0],
            ['kind' => 'version', 'type' => '', 'count' => 9, 'last_seen' => 40.0],
        ];

        expect(array_column(conditionsFor(new Markers(createdAt: 1.0), [RecordType::LOG], drift: $drift), 'count'))->toBe([5, 1])
            ->and(array_column(conditionsFor(new Markers(createdAt: 1.0), [RecordType::REQUEST], drift: $drift), 'count'))->toBe([2])
            ->and(conditionsFor(new Markers(createdAt: 1.0), [RecordType::QUERY], drift: $drift))->toBe([]);
    });
});

describe('an unverified Nightwatch', function () {
    it('names the installed release and the verified line', function (string $version) {
        app()->bind(NightwatchInstall::class, fn () => new NightwatchInstall($version, registeredFirst: false));
        registerFirewatch();
        $this->travelTo('2026-09-30 14:00:00');
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([[
            'id' => 'nightwatch-unverified',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.nightwatch-unverified', ['version' => $version, 'line' => '1.30']),
            'version' => $version,
            'line' => '1.30',
        ]]);
    })->with(['a newer minor' => 'v1.31.0', 'a newer major' => 'v2.0.0', 'a development branch' => 'dev-main']);

    it('says nothing of the verified line, its patch releases or lower releases', function (string $version) {
        app()->bind(NightwatchInstall::class, fn () => new NightwatchInstall($version, registeredFirst: false));
        registerFirewatch();
        $this->travelTo('2026-09-30 14:00:00');
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        $conditions = conditionsOf();

        expect($conditions)->toEqual([]);
    })->with(['the verified release' => 'v1.30.0', 'a patch release' => 'v1.30.9', 'a lower release' => 'v1.29.4']);
});

describe('redaction', function () {
    it('says some headers or payload fields are redacted while the request type is read and a list is not empty', function (array $headers, array $fields) {
        config()->set('firewatch.capture.redact_headers', $headers);
        config()->set('firewatch.capture.redact_payload_fields', $fields);
        registerFirewatch();

        $conditions = conditionsFor(new Markers(createdAt: 1.0));

        expect($conditions)->toBe([[
            'id' => 'redaction-active',
            'kind' => 'condition',
            'message' => __('firewatch::messages.conditions.redaction-active'),
            'headers' => $headers,
            'payload_fields' => $fields,
        ]]);
    })->with([
        'headers' => [['Authorization'], []],
        'payload fields' => [[], ['password']],
        'both' => [['Cookie'], ['_token']],
    ]);

    it('says nothing for empty lists, or when requests are not read', function () {
        $conditions = conditionsFor(new Markers(createdAt: 1.0));

        expect($conditions)->toBe([]);

        config()->set('firewatch.capture.redact_headers', ['Authorization']);
        registerFirewatch();

        $conditions = conditionsFor(new Markers(createdAt: 1.0), [RecordType::LOG, RecordType::QUERY]);

        expect($conditions)->toBe([]);
    });
});

it('attaches the conditions after the structural blind spots, in the order of the contract', function () {
    config()->set('firewatch.capture.redact_headers', ['Authorization']);
    registerFirewatch();

    $markers = new Markers(createdAt: 600.0, rebuiltAt: 600.0, rebuiltWhy: 'schema', prunedThrough: 700.0, prunedReason: 'age', nightwatchVersion: 'v2.0.0', nightwatchVerified: false);

    $conditions = conditionsFor($markers, drift: [['kind' => 'unknown_field', 'type' => 'request', 'count' => 1, 'last_seen' => 5.0]]);

    expect(array_column($conditions, 'id'))->toBe(['history-pruned', 'store-rebuilt', 'drift', 'nightwatch-unverified', 'redaction-active']);

    $kinds = array_column(Envelope::assert(Overview::class)['blind_spots'], 'kind');

    expect(end($kinds))->toBe('condition')
        ->and(array_search('condition', $kinds, true))->toBe(count($kinds) - 1)
        ->and(array_slice($kinds, 0, -1))->each->toBe('structural');
});
