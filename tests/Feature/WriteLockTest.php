<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
use Laravel\Nightwatch\Facades\Nightwatch;

function registerFirewatchOnSqlite(string $version): void
{
    app()->bind(Writer::class, fn () => new Writer(app(Configuration::class), sqliteVersion: $version));

    registerFirewatch();
}

function lockPath(): string
{
    return app(Configuration::class)->database.'.lock';
}

/**
 * Hold the store's lock file open, as another process would.
 */
function holdLockFile(): void
{
    mkdir(dirname(lockPath()), recursive: true);
    $handle = fopen(lockPath(), 'c');
    flock($handle, LOCK_EX);
    test()->lockHandle = $handle;

    test()->beforeApplicationDestroyed(fn () => fclose($handle));
}

/**
 * @return list<string>
 */
function lockedCacheKeys(): array
{
    return array_column(storeRows('SELECT key FROM cache_events ORDER BY id'), 'key');
}

/**
 * @return list<array<string, mixed>>
 */
function lockFailureLines(): array
{
    $lines = file(dirname(app(Configuration::class)->database).'/failures.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_map(fn (string $line) => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);
}

it('serializes writes through the lock file on SQLite builds with the WAL-reset bug', function (string $version) {
    registerFirewatchOnSqlite($version);
    holdLockFile();

    Cache::get('first');
    Nightwatch::digest();

    expect(lockFailureLines())->toHaveCount(1)
        ->and(lockFailureLines()[0])->toMatchArray(['kind' => 'busy', 'code' => null, 'message' => 'Firewatch could not lock ['.lockPath().'] within 300 ms.', 'dropped' => 1])
        ->and(app(Reader::class)->exists())->toBeFalse();
})->with([
    'the first affected release' => '3.7.0',
    'the last affected 3.44 release' => '3.44.5',
    'the first affected 3.45 release' => '3.45.0',
    'the last affected 3.50 release' => '3.50.6',
    'the first affected 3.51 release' => '3.51.0',
    'the last affected 3.51 release' => '3.51.2',
]);

it('writes without the lock file on SQLite builds without the bug', function (string $version) {
    registerFirewatchOnSqlite($version);
    holdLockFile();

    Cache::get('first');
    Nightwatch::digest();

    expect(lockedCacheKeys())->toBe(['first']);
})->with([
    'before the first affected release' => '3.6.23',
    'the 3.44 fix' => '3.44.6',
    'the 3.50 fix' => '3.50.7',
    'between the fixed 3.50 and the affected 3.51' => '3.50.9',
    'the 3.51 fix' => '3.51.3',
]);

it('takes no lock file and keeps one connection on SQLite builds without the bug', function (string $version, bool $kept) {
    $writer = new Writer(app(Configuration::class), sqliteVersion: $version);

    $first = $writer->transaction(fn (SQLite3 $connection) => $connection);
    $second = $writer->transaction(fn (SQLite3 $connection) => $connection);

    expect($first === $second)->toBe($kept)
        ->and(file_exists(lockPath()))->toBe(! $kept);
})->with([
    'without the bug' => ['3.51.3', true],
    'with the bug' => ['3.45.0', false],
]);

it('opens a new connection inside the lock for each batch on SQLite builds with the bug', function () {
    registerFirewatchOnSqlite('3.45.0');

    Cache::get('first');
    Nightwatch::digest();
    unlink(app(Configuration::class)->database);
    Cache::get('second');
    Nightwatch::digest();

    expect(lockedCacheKeys())->toBe(['second']);
});

it('creates the lock file private to its owner', function () {
    registerFirewatchOnSqlite('3.45.0');

    Cache::get('first');
    Nightwatch::digest();

    expect(fileperms(lockPath()) & 0777)->toBe(0600);
})->group('posix');

it('polls the lock every 5 ms within the busy timeout', function (int $busyTimeout, array $sleeps) {
    config()->set('firewatch.busy_timeout', $busyTimeout);
    registerFirewatchOnSqlite('3.45.0');
    holdLockFile();

    Cache::get('first');
    Nightwatch::digest();

    Sleep::assertSequence(array_map(fn (int $microseconds) => Sleep::usleep($microseconds), $sleeps));
    Exceptions::assertReported(fn (RuntimeException $exception) => str_ends_with($exception->getMessage(), "within {$busyTimeout} ms."));
})->with([
    'failing fast at 0' => [0, []],
    'a budget shorter than one poll' => [3, [3_000]],
    'a budget of whole polls' => [10, [5_000, 5_000]],
    'a budget ending between polls' => [12, [5_000, 5_000, 2_000]],
]);

it('writes once the lock is released within the busy timeout', function () {
    registerFirewatchOnSqlite('3.45.0');
    holdLockFile();
    Sleep::whenFakingSleep(function () {
        flock(test()->lockHandle, LOCK_UN);
    });

    Cache::get('first');
    Nightwatch::digest();

    expect(lockedCacheKeys())->toBe(['first']);
    Sleep::assertSleptTimes(1);
});

it('gives the write what is left of the busy timeout after waiting for the lock', function (string $version, bool $held, int $busyTimeout) {
    if ($held) {
        holdLockFile();
    }

    Sleep::whenFakingSleep(function () {
        flock(test()->lockHandle, LOCK_UN);
    });
    $writer = new Writer(app(Configuration::class), sqliteVersion: $version);

    $result = $writer->transaction(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA busy_timeout'));

    expect($result)->toBe($busyTimeout);
})->with([
    'with the bug, at once' => ['3.45.0', false, 300],
    'with the bug, after one poll' => ['3.45.0', true, 295],
    'without the bug' => ['3.51.3', true, 300],
]);

it('records a lock file it can\'t open as a filesystem failure', function () {
    registerFirewatchOnSqlite('3.45.0');
    mkdir(lockPath(), recursive: true);

    Cache::get('first');
    Nightwatch::digest();

    expect(lockFailureLines())->toHaveCount(1)
        ->and(lockFailureLines()[0])->toMatchArray(['kind' => 'io', 'code' => null, 'message' => 'Firewatch could not open ['.lockPath().'].', 'dropped' => 1]);
});
