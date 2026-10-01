<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;

function stateStore(): string
{
    $path = app(Configuration::class)->database;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    return $path;
}

/**
 * Create the store with one record, with every page in the one file.
 */
function stateCurrentStore(): string
{
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('cache-event', '{}')"));

    return app(Configuration::class)->database;
}

function stateRead(?Reader $reader = null): StoreUnusable
{
    try {
        ($reader ?? app(Reader::class))->snapshot(fn (SQLite3 $connection) => $connection->querySingle('SELECT count(*) FROM records'));
    } catch (StoreUnusable $unusable) {
        return $unusable;
    }

    throw new RuntimeException('The store was usable.');
}

it('reads a current store', function () {
    stateCurrentStore();

    expect(app(Reader::class)->snapshot(fn (SQLite3 $connection) => $connection->querySingle('SELECT count(*) FROM records')))->toBe(1);
});

it('reports the state of a store that cannot be read as usual', function (?Closure $arrange, StoreState $state, ?int $found) {
    stateStore();
    $arrange && $arrange();

    $unusable = stateRead();

    expect($unusable->state)->toBe($state)
        ->and($unusable->found)->toBe($found);
})->with([
    'no file' => [null, StoreState::ABSENT, null],
    'a zero-byte file' => [fn () => touch(stateStore()), StoreState::ABSENT, null],
    'a SQLite file with nothing in it' => [fn () => (new SQLite3(stateStore()))->exec('PRAGMA journal_mode = WAL'), StoreState::ABSENT, null],
    'an older schema' => [fn () => (new SQLite3(stateStore()))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)'), StoreState::SCHEMA_MISMATCH, 0],
    'a newer schema' => [fn () => (new SQLite3(stateStore()))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 2; CREATE TABLE records (id INTEGER)'), StoreState::SCHEMA_MISMATCH, 2],
    'a foreign SQLite file' => [fn () => (new SQLite3(stateStore()))->exec('CREATE TABLE orders (id INTEGER)'), StoreState::FOREIGN, null],
    'a file that is not a database' => [fn () => file_put_contents(stateStore(), str_repeat('not a database ', 100)), StoreState::FOREIGN, null],
]);

it('reports a damaged Firewatch store as corrupt, however it is damaged', function (int $offset) {
    $path = stateCurrentStore();
    clearstatcache(true, $path);
    $handle = fopen($path, 'r+b');
    fseek($handle, $offset);
    fwrite($handle, str_repeat("\xff", filesize($path) - $offset));
    fclose($handle);

    $unusable = stateRead();

    expect($unusable->state)->toBe(StoreState::CORRUPT);
})->with([
    'in its tables, read by the answer' => [4096],
    'in its first page, read by the check' => [100],
]);

it('reports a damaged file of another application as foreign', function () {
    $path = stateStore();
    (new SQLite3($path))->exec('CREATE TABLE orders (id INTEGER)');
    clearstatcache(true, $path);
    $handle = fopen($path, 'r+b');
    fseek($handle, 100);
    fwrite($handle, str_repeat("\xff", filesize($path) - 100));
    fclose($handle);

    expect(stateRead()->state)->toBe(StoreState::FOREIGN);
});

it('reports a store below the SQLite floor as unavailable, without opening it', function (string $version, bool $unavailable) {
    $path = stateCurrentStore();
    $reader = new Reader(app(Configuration::class), sqliteVersion: $version);

    if ($unavailable) {
        expect(stateRead($reader))->state->toBe(StoreState::UNAVAILABLE)->found->toBe($version);
    } else {
        expect($reader->snapshot(fn (SQLite3 $connection) => $connection->querySingle('SELECT count(*) FROM records')))->toBe(1);
    }

    expect($path)->toBeFile();
})->with([
    'just below the floor' => ['3.37.2', true],
    'at the floor' => ['3.38.0', false],
]);

it('reports a store that stays locked past the busy timeout as busy', function () {
    $path = stateCurrentStore();
    $connection = new SQLite3($path);
    $connection->exec('PRAGMA journal_mode = DELETE');
    $connection->exec('BEGIN EXCLUSIVE');

    $reader = new class(app(Configuration::class)) extends Reader
    {
        protected const BUSY_TIMEOUT_MILLISECONDS = 20;
    };

    expect(stateRead($reader)->state)->toBe(StoreState::BUSY);

    $connection->exec('ROLLBACK');
});

it('lets an error that is not the store\'s own through', function () {
    stateCurrentStore();

    expect(fn () => app(Reader::class)->snapshot(fn (SQLite3 $connection) => $connection->query('SELECT * FROM nowhere')))
        ->toThrow(SQLite3Exception::class, 'no such table: nowhere');
});

it('creates nothing and changes nothing when it reports a state', function () {
    $path = stateStore();
    file_put_contents($path, str_repeat('not a database ', 100));
    $before = [md5_file($path), scandir(dirname($path))];

    stateRead();

    expect([md5_file($path), scandir(dirname($path))])->toBe($before);
});
