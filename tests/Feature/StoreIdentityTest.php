<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Support\Facades\Cache;
use Laravel\Nightwatch\Facades\Nightwatch;

function identityConnection(Writer $writer): SQLite3
{
    return $writer->transaction(fn (SQLite3 $connection) => $connection);
}

/**
 * Register Firewatch with a writer that keeps its connection.
 */
function identityWriter(?Closure $pid = null): Writer
{
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.51.3', pid: $pid);

    app()->instance(Writer::class, $writer);

    registerFirewatch();

    return $writer;
}

function identityStorePath(): string
{
    return app(Configuration::class)->database;
}

/**
 * @return list<string>
 */
function identityCacheKeys(): array
{
    return array_column(storeRows('SELECT key FROM cache_events ORDER BY id'), 'key');
}

/**
 * Write one batch that holds a single cache event, through the application's own ingest.
 */
function identityWriteBatch(string $key): void
{
    Cache::get($key);
    Nightwatch::digest();
}

/**
 * Replace the store file with another one, as a deploy or a restore would.
 */
function replaceStore(string $key): void
{
    $path = identityStorePath();
    $replacement = $path.'.replacement';
    $normaliser = new ConfigurationNormaliser(basePath: base_path(), publicPath: public_path(), storagePath: storage_path());
    $writer = new Writer($normaliser->resolve(['database' => $replacement]), sqliteVersion: '3.51.3');

    $connection = $writer->transaction(function (SQLite3 $connection) use ($key) {
        $connection->exec("INSERT INTO records (type, data) VALUES ('cache-event', json_object('key', '{$key}'))");

        return $connection;
    });

    // A rollback journal keeps the whole store in the one file that is moved.
    $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $connection->exec('PRAGMA journal_mode = DELETE');
    $connection->close();

    // A restore leaves nothing of the old store's write-ahead log beside the new file.
    @unlink($path.'-wal');
    @unlink($path.'-shm');
    rename($replacement, $path);
}

it('recreates the store lazily when it is deleted under a live writer', function () {
    $writer = identityWriter();
    identityWriteBatch('before');
    $connection = identityConnection($writer);
    unlink(identityStorePath());

    identityWriteBatch('after');

    expect(identityCacheKeys())->toBe(['after'])
        ->and(fn () => $connection->querySingle('SELECT 1'))->toThrow(Error::class);
})->group('posix');

it('writes to the replacement when the store is replaced under a live writer', function () {
    identityWriter();
    identityWriteBatch('before');
    replaceStore('replacement');

    identityWriteBatch('after');

    expect(identityCacheKeys())->toBe(['replacement', 'after']);
})->group('posix');

it('reads the replacement when the store is replaced under a live reader', function () {
    identityWriteBatch('before');
    $before = identityCacheKeys();
    replaceStore('replacement');

    $after = identityCacheKeys();

    expect($before)->toBe(['before'])
        ->and($after)->toBe(['replacement']);
})->group('posix');

it('never reopens an unchanged store', function () {
    $writer = identityWriter();
    identityWriteBatch('first');
    $connection = identityConnection($writer);

    identityWriteBatch('second');

    expect(identityConnection($writer))->toBe($connection)
        ->and(identityCacheKeys())->toBe(['first', 'second']);
})->group('posix');

it('abandons an inherited connection without closing it when the pid changes', function () {
    $pid = getmypid();
    $writer = identityWriter(function () use (&$pid) {
        return $pid;
    });
    identityWriteBatch('inherited');
    $inherited = identityConnection($writer);

    $pid++;
    identityWriteBatch('forked');

    expect(identityConnection($writer))->not->toBe($inherited)
        ->and($inherited->querySingle('SELECT 1'))->toBe(1)
        ->and(identityCacheKeys())->toBe(['inherited', 'forked']);
})->group('posix');
