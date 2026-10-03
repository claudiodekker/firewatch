<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Nightwatch\Facades\Nightwatch;

function recoveryPath(): string
{
    return app(Configuration::class)->database;
}

/**
 * Register Firewatch with a writer on the given SQLite release.
 */
function recoveryWriter(string $sqliteVersion): Writer
{
    $writer = new Writer(app(Configuration::class), sqliteVersion: $sqliteVersion);

    app()->instance(Writer::class, $writer);

    registerFirewatch();

    return $writer;
}

/**
 * Store one cache event through the application's own ingest.
 */
function recoveryBatch(string $key): void
{
    Cache::get($key);
    Nightwatch::digest();
}

/**
 * Create the store with a first batch, then let go of it so that everything is in the one file.
 */
function recoveryStore(string $key = 'old'): void
{
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');

    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('cache-event', json_object('key', '{$key}'))"));
}

/**
 * Overwrite the store from the given offset on.
 */
function recoveryCorrupt(int $offset = 4096): void
{
    $handle = fopen(recoveryPath(), 'r+b');

    fseek($handle, $offset);
    fwrite($handle, str_repeat("\xff", 8192));
    fclose($handle);
}

function recoveryForeignStore(): void
{
    $connection = new SQLite3(recoveryPath());
    $connection->exec('CREATE TABLE orders (id INTEGER); INSERT INTO orders VALUES (1)');
    $connection->close();
}

/**
 * @return list<string>
 */
function recoveryKeys(): array
{
    return array_column(storeRows('SELECT key FROM cache_events ORDER BY id'), 'key');
}

/**
 * @return list<array<string, mixed>>
 */
function recoveryFailures(): array
{
    $path = dirname(recoveryPath()).'/failures.jsonl';

    return array_map(
        fn (string $line) => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

/**
 * Stamp the store with the schema version of a later release, as that release would after rebuilding it in place.
 */
function recoveryNewerSchema(): void
{
    $store = new SQLite3(recoveryPath());
    $store->exec('PRAGMA user_version = '.(Schema::VERSION + 1));
    $store->close();
}

function recoveryUnusable(): ?StoreUnusable
{
    try {
        app(Reader::class)->snapshot(fn () => null);
    } catch (StoreUnusable $unusable) {
        return $unusable;
    }

    return null;
}

/**
 * @return list<string>
 */
function recoveryObjects(SQLite3 $connection): array
{
    $result = $connection->query("SELECT type || ' ' || name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY 1");
    $objects = [];

    while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
        $objects[] = $row[0];
    }

    return $objects;
}

describe('a store of another schema version', function () {
    it('is rebuilt in place and takes the batch', function (string $stamps) {
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        $store = new SQLite3($path);
        $store->exec($stamps.'; CREATE TABLE records (id INTEGER); CREATE INDEX records_type_started ON records (id); CREATE VIEW requests AS SELECT 1 AS one; CREATE TABLE legacy (id INTEGER)');
        $store->close();
        $identity = stat($path)['ino'];
        recoveryWriter('3.51.3');

        recoveryBatch('new');

        $fresh = new SQLite3(':memory:');

        foreach ((new Schema)->statements() as $statement) {
            $fresh->exec($statement);
        }

        $objects = app(Reader::class)->snapshot(recoveryObjects(...));

        expect(recoveryKeys())->toBe(['new'])
            ->and($objects)->toBe(recoveryObjects($fresh))
            ->and(stat($path)['ino'])->toBe($identity)
            ->and(app(Reader::class)->snapshot(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA user_version')))->toBe(Schema::VERSION)
            ->and(file_exists(dirname($path).'/failures.jsonl'))->toBeFalse();
    })->with([
        'an older schema' => 'PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0',
    ]);

    it('is left as it is when a later release wrote it, and the batch is dropped', function (string $sqliteVersion) {
        recoveryStore();
        recoveryNewerSchema();
        recoveryWriter($sqliteVersion);

        recoveryBatch('new');

        expect(recoveryUnusable()?->state)->toBe(StoreState::SCHEMA_MISMATCH)
            ->and(recoveryUnusable()?->found)->toBe(Schema::VERSION + 1)
            ->and(recoveryFailures())->toHaveCount(1)
            ->and(recoveryFailures()[0])->toMatchArray(['kind' => 'schema', 'dropped' => 1]);
    })->with([
        'a writer that closes its connection per batch' => '3.45.1',
        'a writer that keeps its connection' => '3.51.3',
    ]);

    it('is left as it is when a later release rebuilt it under a connection this writer kept', function () {
        recoveryWriter('3.51.3');
        recoveryBatch('old');
        recoveryNewerSchema();

        recoveryBatch('new');

        expect(recoveryUnusable()?->found)->toBe(Schema::VERSION + 1)
            ->and(recoveryFailures())->toHaveCount(1)
            ->and(recoveryFailures()[0])->toMatchArray(['kind' => 'schema', 'dropped' => 1]);
    });

    it('starts its history again at the rebuild', function () {
        $this->travelTo('2026-09-30 14:00:00');
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        $store = new SQLite3($path);
        $store->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)');
        $store->close();
        recoveryWriter('3.51.3');

        recoveryBatch('new');

        expect(app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->createdAt))->toBe(1790776800.0);
    });

    it('is not rebuilt twice when another writer rebuilt it first', function () {
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        $store = new SQLite3($path);
        $store->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)');
        $store->close();
        $other = new Writer(app(Configuration::class), sqliteVersion: '3.51.3');

        // The other writer rebuilds the store and stores a record between this writer's check and its transaction.
        $writer = new class(app(Configuration::class), '3.51.3', other: $other) extends Writer
        {
            protected bool $raced = false;

            public function __construct(Configuration $configuration, string $sqliteVersion, protected Writer $other)
            {
                parent::__construct($configuration, $sqliteVersion);
            }

            protected function transactionOn(SQLite3 $connection, Closure $callback): mixed
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->other->transaction(fn (SQLite3 $other) => $other->exec("INSERT INTO records (type, data) VALUES ('cache-event', json_object('key', 'other'))"));
                }

                return parent::transactionOn($connection, $callback);
            }
        };
        app()->instance(Writer::class, $writer);
        registerFirewatch();

        recoveryBatch('new');

        expect(recoveryKeys())->toBe(['other', 'new']);
    });
});

describe('a damaged Firewatch store', function () {
    it('is moved aside and replaced by a store that takes the batch', function (string $sqliteVersion, int $offset) {
        $now = CarbonImmutable::parse('2026-09-30 12:00:00.250000');
        $this->travelTo($now);
        recoveryStore();
        recoveryCorrupt($offset);
        $path = recoveryPath();
        $damaged = md5_file($path);
        recoveryWriter($sqliteVersion);

        recoveryBatch('new');

        expect(recoveryKeys())->toBe(['new'])
            ->and(md5_file($path.'.corrupt'))->toBe($damaged)
            ->and(recoveryFailures())->toHaveCount(1)
            ->and(recoveryFailures()[0])->toMatchArray(['at' => (float) $now->format('U.u'), 'kind' => 'corrupt', 'code' => 11, 'dropped' => 0])
            ->and(recoveryFailures()[0]['message'])->toEndWith('database disk image is malformed')
            ->and(app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->rebuiltWhy))->toBe('corrupt');
        Exceptions::assertNothingReported();
    })->with([
        'a writer that closes its connection per batch, damaged in its tables' => ['3.45.1', 4096],
        'a writer that keeps its connection, damaged in its tables' => ['3.51.3', 4096],
        'a writer that keeps its connection, damaged in its first page' => ['3.51.3', 100],
    ]);

    it('moves aside its write-ahead log and shared memory too, replacing the copies of an earlier recovery', function () {
        $path = recoveryPath();
        recoveryStore();

        // Another process holding the store open leaves its log and shared memory in place.
        $holder = new SQLite3($path);
        $holder->querySingle('SELECT count(*) FROM records');
        recoveryCorrupt();
        file_put_contents($path.'.corrupt', 'an earlier copy');
        file_put_contents($path.'-wal.corrupt', 'an earlier log');
        file_put_contents($path.'-shm.corrupt', 'an earlier memory');
        recoveryWriter('3.51.3');

        recoveryBatch('new');
        $holder->close();

        expect($path.'-wal.corrupt')->toBeFile()
            ->and(file_get_contents($path.'-wal.corrupt'))->not->toBe('an earlier log')
            ->and(file_get_contents($path.'-shm.corrupt'))->not->toBe('an earlier memory')
            ->and(file_get_contents($path.'.corrupt'))->not->toBe('an earlier copy')
            ->and(recoveryKeys())->toBe(['new']);
    });

    it('leaves no copy of a log that the damaged store did not have', function () {
        $path = recoveryPath();
        recoveryStore();
        recoveryCorrupt();
        file_put_contents($path.'-wal.corrupt', 'an earlier log');
        recoveryWriter('3.51.3');

        recoveryBatch('new');

        expect($path.'-wal.corrupt')->not->toBeFile();
    });

    it('is left alone when another process already replaced it', function () {
        recoveryStore();
        $other = new Writer(app(Configuration::class), sqliteVersion: '3.51.3');

        // The other process finds the damage first, replaces the store and writes to the new one, before this writer sees the same damage.
        $writer = new class(app(Configuration::class), '3.51.3', $other) extends Writer
        {
            protected bool $raced = false;

            public function __construct(Configuration $configuration, string $sqliteVersion, protected Writer $other)
            {
                parent::__construct($configuration, $sqliteVersion);
            }

            protected function write(Closure $callback): mixed
            {
                if (! $this->raced) {
                    $this->raced = true;

                    $path = $this->configuration->database;
                    rename($path, $path.'.corrupt');
                    $this->other->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('cache-event', json_object('key', 'other'))"));

                    throw new SQLite3Exception('database disk image is malformed', 11);
                }

                return parent::write($callback);
            }
        };
        app()->instance(Writer::class, $writer);
        registerFirewatch();

        recoveryBatch('new');

        expect(recoveryKeys())->toBe(['other', 'new'])
            ->and(dirname(recoveryPath()).'/failures.jsonl')->not->toBeFile();
    });
});

describe('a file that is not a Firewatch store', function () {
    it('is never written to', function (Closure $write, string $message) {
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        $write();
        $before = [md5_file($path), filemtime($path)];
        recoveryWriter('3.51.3');

        recoveryBatch('dropped');

        expect([md5_file($path), filemtime($path)])->toBe($before)
            ->and($path.'.corrupt')->not->toBeFile()
            ->and(recoveryFailures()[0])->toMatchArray(['kind' => 'foreign', 'message' => sprintf($message, $path), 'dropped' => 1]);
    })->with([
        'a foreign SQLite file' => [fn () => recoveryForeignStore(), 'The file at [%s] is not a Firewatch store.'],
        'a file that is not a database' => [fn () => file_put_contents(recoveryPath(), str_repeat('not a database ', 100)), 'The file at [%s] is not a Firewatch store.'],
    ]);

    it('is never moved aside when it is damaged', function () {
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        recoveryForeignStore();
        recoveryCorrupt(100);
        $before = md5_file($path);
        recoveryWriter('3.51.3');

        recoveryBatch('dropped');

        expect(md5_file($path))->toBe($before)
            ->and($path.'.corrupt')->not->toBeFile()
            ->and(recoveryFailures()[0])->toMatchArray(['kind' => 'foreign', 'message' => "The file at [{$path}] is not a Firewatch store.", 'dropped' => 1]);
    });

    it('treats a zero-byte file as a new store', function () {
        $path = recoveryPath();
        mkdir(dirname($path), recursive: true);
        touch($path);
        recoveryWriter('3.51.3');

        recoveryBatch('first');

        expect(recoveryKeys())->toBe(['first'])
            ->and($path.'.corrupt')->not->toBeFile();
    });
});
