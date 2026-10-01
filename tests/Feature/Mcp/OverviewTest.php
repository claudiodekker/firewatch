<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

it('answers that no store exists yet, with the store clock', function () {
    $this->travelTo('2026-09-30 14:00:00.250000');
    $path = app(Configuration::class)->database;

    $envelope = Envelope::assert(Overview::class);

    expect($envelope)->toMatchArray([
        'tool' => 'overview',
        'now' => 1790776800.25,
        'summary' => 'Nothing to report: no store has been written yet.',
        'empty' => ['kind' => 'no_store', 'population' => null, 'message' => __('firewatch::messages.no_store', ['path' => $path])],
        'result' => [],
        'coverage' => ['state' => 'absent', 'reason' => null, 'oldest_at' => null, 'newest_at' => null, 'records' => null],
    ]);
});

it('answers that the store is empty when it holds no records', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn () => null);

    $envelope = Envelope::assert(Overview::class);

    expect($envelope)->toMatchArray([
        'summary' => 'Nothing to report: the store holds no records.',
        'empty' => ['kind' => 'store_empty', 'population' => 0, 'message' => __('firewatch::messages.store_empty', ['path' => $path])],
        'coverage' => ['state' => 'empty', 'reason' => null, 'oldest_at' => null, 'newest_at' => null, 'records' => 0],
    ]);
});

it('counts the records the store holds, and the requests among them', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');

    $envelope = Envelope::assert(Overview::class);

    expect($envelope['empty'])->toBeNull()
        ->and($envelope['result']['requests'])->toBe(1)
        ->and($envelope['result']['records'])->toBeGreaterThanOrEqual(1)
        ->and($envelope['summary'])->toBe("The store holds {$envelope['result']['records']} records, 1 of them requests.")
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'reason' => null, 'records' => $envelope['result']['records']])
        ->and($envelope['coverage']['oldest_at'])->toBeFloat()->toBeLessThanOrEqual($envelope['coverage']['newest_at']);
});

it('answers in JSON for a format given in any case', function () {
    $response = FirewatchServer::tool(Overview::class, ['format' => ' JSON ']);

    $response->assertStructuredContent(fn ($json) => $json->where('tool', 'overview')->etc());
});

it('refuses a format that is none', function () {
    $response = FirewatchServer::tool(Overview::class, ['format' => 'xml']);

    $response->assertHasErrors(["error: invalid_argument\n`format` must be markdown or json; got \"xml\".\nargument: format\naccepted: markdown or json\nexample: overview(format: \"json\")"]);
});

it('creates nothing where the store would be', function () {
    $path = app(Configuration::class)->database;

    FirewatchServer::tool(Overview::class);

    expect(dirname($path))->not->toBeDirectory();
});

it('answers that the store is unusable, with its reason', function (Closure $arrange, string $key, Closure $replace) {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    $arrange($path);

    $envelope = Envelope::assert(Overview::class);

    expect($envelope['empty'])->toBe(['kind' => 'store_unusable', 'population' => null, 'message' => __("firewatch::messages.store_unusable.{$key}", $replace($path))])
        ->and($envelope['summary'])->toBe('Nothing to report: the store can not be used.')
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => $key, 'records' => null]);
})->with([
    'a foreign file' => [
        fn (string $path) => (new SQLite3($path))->exec('CREATE TABLE orders (id INTEGER)'),
        'foreign_file',
        fn (string $path) => ['path' => $path],
    ],
    'a file that is not a database' => [
        fn (string $path) => file_put_contents($path, str_repeat('not a database ', 100)),
        'foreign_file',
        fn (string $path) => ['path' => $path],
    ],
    'an older schema' => [
        fn (string $path) => (new SQLite3($path))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)'),
        'older_schema',
        fn (string $path) => ['path' => $path, 'found' => 0, 'expected' => Schema::VERSION],
    ],
    'a newer schema' => [
        fn (string $path) => (new SQLite3($path))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 2; CREATE TABLE records (id INTEGER)'),
        'newer_schema',
        fn (string $path) => ['path' => $path, 'found' => 2, 'expected' => Schema::VERSION],
    ],
]);

it('answers that a damaged store cannot be read', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
    clearstatcache(true, $path);
    $handle = fopen($path, 'r+b');
    fseek($handle, 4096);
    fwrite($handle, str_repeat("\xff", filesize($path) - 4096));
    fclose($handle);

    $envelope = Envelope::assert(Overview::class);

    expect($envelope['empty'])->toMatchArray(['kind' => 'store_unusable', 'message' => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.corrupt')])])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'unreadable']);
});

it('answers that a busy store cannot be read yet', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
    $connection = new SQLite3($path);
    $connection->exec('PRAGMA journal_mode = DELETE');
    $connection->exec('BEGIN EXCLUSIVE');
    app()->instance(Reader::class, new class(app(Configuration::class)) extends Reader
    {
        protected const BUSY_TIMEOUT_MILLISECONDS = 20;
    });

    $envelope = Envelope::assert(Overview::class);

    expect($envelope['empty'])->toMatchArray(['kind' => 'store_unusable', 'message' => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.busy')])])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'unreadable']);
    $connection->exec('ROLLBACK');
});

it('answers that SQLite is too old to read the store', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => null);
    app()->instance(Reader::class, new Reader(app(Configuration::class), sqliteVersion: '3.37.2'));

    $envelope = Envelope::assert(Overview::class);

    expect($envelope['empty'])->toMatchArray(['kind' => 'store_unusable', 'message' => __('firewatch::messages.store_unusable.sqlite_too_old', ['path' => $path, 'version' => '3.37.2', 'minimum' => '3.38.0'])])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'sqlite_too_old']);
});

it('does not touch an unusable store', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));
    $before = md5_file($path);

    FirewatchServer::tool(Overview::class);

    expect(md5_file($path))->toBe($before)
        ->and(scandir(dirname($path)))->toBe(['.', '..', basename($path)]);
});

describe('windows', function () {
    function requestsAt(float ...$epochs): void
    {
        ingest(array_map(fn (float $epoch) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => $epoch]), $epochs));
    }

    beforeEach(function () {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $this->travelTo('2026-09-30 14:00:00');
    });

    it('counts only the records that started in the window', function () {
        requestsAt(1790690400.0, 1790766000.0, 1790773200.0);

        $envelope = Envelope::assert(Overview::class, ['since' => '-2h']);

        expect($envelope['result'])->toBe(['records' => 1, 'requests' => 1])
            ->and($envelope['window'])->toMatchArray(['windowed' => true, 'basis' => 'started_at', 'since' => 1790769600.0, 'until' => null, 'timezone' => 'Europe/Amsterdam'])
            ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'oldest_at' => 1790690400.0, 'newest_at' => 1790773200.0, 'records' => 3]);
    });

    it('takes a record at since in and a record at until out', function () {
        requestsAt(1790766000.0, 1790769600.0, 1790773200.0);

        $envelope = Envelope::assert(Overview::class, ['since' => 1790766000, 'until' => 1790773200]);

        expect($envelope['result']['requests'])->toBe(2)
            ->and($envelope['window'])->toMatchArray(['since' => 1790766000.0, 'until' => 1790773200.0]);
    });

    it('reads the bounds in the application timezone', function () {
        requestsAt(1790719200.0, 1790719199.0);

        $envelope = Envelope::assert(Overview::class, ['since' => '2026-09-30', 'until' => '2026-09-30 02:00']);

        expect($envelope['result']['requests'])->toBe(1)
            ->and($envelope['window'])->toMatchArray(['since' => 1790719200.0, 'until' => 1790726400.0]);
    });

    it('answers that the window is empty, among the records the store holds', function () {
        requestsAt(1790690400.0, 1790773200.0);

        $envelope = Envelope::assert(Overview::class, ['since' => '-30m']);

        expect($envelope['empty'])->toBe(['kind' => 'window_empty', 'population' => 2, 'message' => __('firewatch::messages.window_empty', ['population' => 2])])
            ->and($envelope['summary'])->toBe('Nothing to report: no records fall in the window.')
            ->and($envelope['result'])->toBe([])
            ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 2]);
    });

    it('answers that the store is empty before it looks at the window', function () {
        app(Writer::class)->transaction(fn () => null);

        $envelope = Envelope::assert(Overview::class, ['since' => '-1d']);

        expect($envelope['empty']['kind'])->toBe('store_empty');
    });

    it('refuses a time the grammar does not read', function (string $argument, mixed $value, string $shown) {
        $response = FirewatchServer::tool(Overview::class, [$argument => $value]);

        $response->assertHasErrors([__('firewatch::messages.unreadable_time', ['argument' => $argument, 'value' => $shown, 'tool' => 'overview', 'maximum' => 4102444800])]);
    })->with([
        'a word' => ['since', 'yesterday', '`yesterday`'],
        'milliseconds' => ['until', '1790776800000', '`1790776800000`'],
        'a list' => ['since', [1], '`[1]`'],
    ]);

    it('refuses a window that ends before it starts or is no longer than a point', function (string $since, string $until) {
        $response = FirewatchServer::tool(Overview::class, ['since' => $since, 'until' => $until]);

        $response->assertHasErrors([__('firewatch::messages.empty_window', ['since' => "{$since}.000000", 'until' => "{$until}.000000", 'tool' => 'overview'])]);
    })->with([
        'before' => ['2026-09-30 15:00:00', '2026-09-30 14:00:00'],
        'equal' => ['2026-09-30 15:00:00', '2026-09-30 15:00:00'],
    ]);

    it('takes a window one microsecond long', function () {
        requestsAt(1790773200.0);

        $envelope = Envelope::assert(Overview::class, ['since' => '2026-09-30 15:00:00', 'until' => '2026-09-30 15:00:00.000001']);

        expect($envelope['result']['requests'])->toBe(1);
    });
});
