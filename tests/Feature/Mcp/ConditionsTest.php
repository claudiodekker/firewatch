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
 * @param  array<string, string>  $meta
 * @return list<array<string, mixed>>
 */
function conditionsFor(array $meta, array $types = [RecordType::REQUEST], ?float $since = null, ?float $until = null, array $drift = []): array
{
    return app(Conditions::class)->for(new StoreFacts($meta, $drift), $types, Window::between($since, $until, 'UTC'));
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

        expect(conditionsOf())->toEqual([[
            'id' => 'history-pruned',
            'kind' => 'condition',
            'message' => 'History before 2026-09-02 12:00:00.000000 was pruned (pruned-age); this window starts before it.',
            'from_at' => 1788350400.0,
            'reason' => 'pruned-age',
        ]]);
    });

    it('attaches only while the window starts before the history does', function (?float $since, bool $attached) {
        $meta = ['created_at' => '100', 'pruned_through' => '500', 'pruned_by' => 'cap'];

        expect(array_column(conditionsFor($meta, since: $since), 'id'))->toBe($attached ? ['history-pruned'] : []);
    })->with([
        'no start' => [null, true],
        'a start before it' => [499.999999, true],
        'a start at it' => [500.0, false],
        'a start after it' => [501.0, false],
    ]);

    it('names the reason of the prune that left the history', function (string $by) {
        $conditions = conditionsFor(['created_at' => '100', 'pruned_through' => '500', 'pruned_by' => $by]);

        expect($conditions[0])->toMatchArray(['id' => 'history-pruned', 'reason' => "pruned-{$by}"])
            ->and($conditions[0]['message'])->toContain("(pruned-{$by})");
    })->with(['age', 'cap', 'size']);

    it('says the window starts before cleared history, for a clear of everything or of a type read', function (array $meta, string $reason) {
        $conditions = conditionsFor(['created_at' => '100', ...$meta]);

        expect($conditions)->toBe([[
            'id' => 'history-cleared',
            'kind' => 'condition',
            'message' => 'History before 1970-01-01 00:08:20.000000 was cleared; this window starts before it.',
            'from_at' => 500.0,
            'reason' => $reason,
        ]]);
    })->with([
        'a clear' => [['cleared_at' => '500'], 'cleared'],
        'a clear of the type' => [['cleared_types' => '{"request":500}'], 'cleared-type'],
    ]);

    it('says nothing of a history that starts at the creation of the store, or of a clear of another type', function (array $meta) {
        expect(conditionsFor($meta))->toBe([]);
    })->with([
        'created' => [['created_at' => '100']],
        'a clear of another type' => [['created_at' => '100', 'cleared_types' => '{"log":500}']],
        'no markers' => [[]],
    ]);
});

describe('a rebuilt store', function () {
    it('says earlier data is gone from the rebuild, with when and why', function (string $why) {
        $conditions = conditionsFor(['created_at' => '600', 'rebuilt_at' => '600', 'rebuilt_why' => $why]);

        expect($conditions)->toBe([[
            'id' => 'store-rebuilt',
            'kind' => 'condition',
            'message' => "The store was rebuilt at 1970-01-01 00:10:00.000000 ({$why}); earlier data is gone.",
            'rebuilt_at' => 600.0,
            'why' => $why,
        ]]);
    })->with(['schema', 'corrupt']);

    it('attaches only while the window starts before the rebuild', function (?float $since, bool $attached) {
        $meta = ['created_at' => '600', 'rebuilt_at' => '600', 'rebuilt_why' => 'schema'];

        expect(array_column(conditionsFor($meta, since: $since), 'id'))->toBe($attached ? ['store-rebuilt'] : []);
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

        expect(conditionsOf())->toEqual([[
            'id' => 'store-rebuilt',
            'kind' => 'condition',
            'message' => 'The store was rebuilt at 2026-09-30 14:00:00.000000 (schema); earlier data is gone.',
            'rebuilt_at' => 1790776800.0,
            'why' => 'schema',
        ]]);
    });

    it('says nothing of a store that was only created', function () {
        $this->travelTo('2026-09-30 14:00:00');

        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        expect(conditionsOf())->toEqual([]);
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

        expect(conditionsOf())->toEqual([[
            'id' => 'records-dropped',
            'kind' => 'condition',
            'message' => '7 records were not stored between 2026-09-30 14:00:00.000000 and 2026-09-30 14:00:10.000000 (last reason: full); results may be incomplete.',
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

        expect(conditionsOf())->toEqual([]);

        app(FailureLog::class)->recovered(new StoreFailure(FailureKind::CORRUPT, 'damaged'));

        expect(conditionsOf())->toEqual([]);
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

        expect(conditionsOf())->toEqual([[
            'id' => 'drift',
            'kind' => 'condition',
            'message' => '2 unknown_field drift on cache-event, last 2026-09-30 14:00:00.000000; fields may be null or missing.',
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

        expect(array_column(conditionsFor(['created_at' => '1'], [RecordType::LOG], drift: $drift), 'count'))->toBe([5, 1])
            ->and(array_column(conditionsFor(['created_at' => '1'], [RecordType::REQUEST], drift: $drift), 'count'))->toBe([2])
            ->and(conditionsFor(['created_at' => '1'], [RecordType::QUERY], drift: $drift))->toBe([]);
    });
});

describe('an unverified Nightwatch', function () {
    it('names the installed release and the verified line', function (string $version) {
        app()->bind(NightwatchInstall::class, fn () => new NightwatchInstall($version, registeredFirst: false));
        registerFirewatch();
        $this->travelTo('2026-09-30 14:00:00');
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        expect(conditionsOf())->toEqual([[
            'id' => 'nightwatch-unverified',
            'kind' => 'condition',
            'message' => "Nightwatch {$version} is newer than the verified line 1.30; records may be partly interpreted.",
            'version' => $version,
            'line' => '1.30',
        ]]);
    })->with(['v1.31.0', 'v2.0.0', 'dev-main']);

    it('says nothing of the verified line, its patch releases or lower releases', function (string $version) {
        app()->bind(NightwatchInstall::class, fn () => new NightwatchInstall($version, registeredFirst: false));
        registerFirewatch();
        $this->travelTo('2026-09-30 14:00:00');
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

        expect(conditionsOf())->toEqual([]);
    })->with(['v1.30.0', 'v1.30.9', 'v1.29.4']);
});

describe('redaction', function () {
    it('says some headers or payload fields are redacted while the request type is read and a list is not empty', function (array $headers, array $fields) {
        config()->set('firewatch.capture.redact_headers', $headers);
        config()->set('firewatch.capture.redact_payload_fields', $fields);
        registerFirewatch();

        expect(conditionsFor(['created_at' => '1']))->toBe([[
            'id' => 'redaction-active',
            'kind' => 'condition',
            'message' => 'Some request headers or payload fields are redacted and read [N bytes redacted].',
            'headers' => $headers,
            'payload_fields' => $fields,
        ]]);
    })->with([
        'headers' => [['Authorization'], []],
        'payload fields' => [[], ['password']],
        'both' => [['Cookie'], ['_token']],
    ]);

    it('says nothing for empty lists, or when requests are not read', function () {
        expect(conditionsFor(['created_at' => '1']))->toBe([]);

        config()->set('firewatch.capture.redact_headers', ['Authorization']);
        registerFirewatch();

        expect(conditionsFor(['created_at' => '1'], [RecordType::LOG, RecordType::QUERY]))->toBe([]);
    });
});

it('attaches the conditions after the structural blind spots, in the order of the contract', function () {
    config()->set('firewatch.capture.redact_headers', ['Authorization']);
    registerFirewatch();

    $conditions = conditionsFor([
        'created_at' => '600', 'rebuilt_at' => '600', 'rebuilt_why' => 'schema',
        'pruned_through' => '700', 'pruned_by' => 'age', 'nightwatch_version' => 'v2.0.0', 'nightwatch_verified' => '0',
    ], drift: [['kind' => 'unknown_field', 'type' => 'request', 'count' => 1, 'last_seen' => 5.0]]);

    expect(array_column($conditions, 'id'))->toBe(['history-pruned', 'store-rebuilt', 'drift', 'nightwatch-unverified', 'redaction-active']);

    $kinds = array_column(Envelope::assert(Overview::class)['blind_spots'], 'kind');

    expect(end($kinds))->toBe('condition')
        ->and(array_search('condition', $kinds, true))->toBe(count($kinds) - 1)
        ->and(array_slice($kinds, 0, -1))->each->toBe('structural');
});
