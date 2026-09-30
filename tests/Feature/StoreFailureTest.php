<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * @return list<array<string, mixed>>
 */
function failureLines(): array
{
    $path = dirname(app(Configuration::class)->database).'/failures.jsonl';
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_map(fn (string $line) => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * @return list<string>
 */
function storedCacheKeys(): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) {
        $result = $connection->query('SELECT key FROM cache_events ORDER BY id');
        $keys = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $keys[] = $row['key'];
        }

        return $keys;
    });
}

/**
 * Start a second process that holds the store's write lock until the returned closure releases it.
 */
function holdWriteLock(string $database): Closure
{
    $script = <<<'PHP'
        $connection = new SQLite3($argv[1]);
        $connection->exec('BEGIN IMMEDIATE');
        fwrite(STDOUT, "locked\n");
        fgets(STDIN);
        $connection->exec('ROLLBACK');
        PHP;

    $process = proc_open([PHP_BINARY, '-r', $script, $database], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

    expect(fgets($pipes[1]))->toBe("locked\n");

    return function () use ($process, $pipes) {
        fclose($pipes[0]);
        proc_close($process);
    };
}

function writeStoreFile(Closure $callback): void
{
    $database = app(Configuration::class)->database;
    mkdir(dirname($database), recursive: true);

    $connection = new SQLite3($database);
    $callback($connection);
    $connection->close();
}

it('drops a batch when another process holds the write lock past the busy timeout, and records it', function (int $busyTimeout) {
    config()->set('firewatch.busy_timeout', $busyTimeout);
    registerFirewatch();
    $now = CarbonImmutable::parse('2026-09-30 12:00:00.250000');
    $this->travelTo($now);
    Cache::get('kept');
    Nightwatch::digest();
    $release = holdWriteLock(app(Configuration::class)->database);

    Cache::get('dropped');
    Cache::put('dropped', 'value');
    Nightwatch::digest();

    $release();

    expect(failureLines())->toBe([[
        'at' => (float) $now->format('U.u'),
        'kind' => 'busy',
        'code' => 5,
        'message' => 'database is locked',
        'dropped' => 2,
    ]])->and(storedCacheKeys())->toBe(['kept']);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Firewatch could not store a batch of 2 records: database is locked');
})->with([
    'failing fast at 0' => 0,
    'at a budget of 50 ms' => 50,
])->group('process');

it('records a store file it does not own by its kind, without a code', function (Closure $write, string $kind, string $message) {
    $now = CarbonImmutable::parse('2026-09-30 12:00:00.250000');
    $this->travelTo($now);
    writeStoreFile($write);
    $database = app(Configuration::class)->database;

    Cache::get('dropped');
    Nightwatch::digest();

    expect(failureLines())->toBe([[
        'at' => (float) $now->format('U.u'),
        'kind' => $kind,
        'code' => null,
        'message' => sprintf($message, $database),
        'dropped' => 1,
    ]]);
})->with([
    'a foreign file' => [
        'write' => fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'),
        'kind' => 'foreign',
        'message' => 'The file at [%s] is not a Firewatch store.',
    ],
    'a store of another schema version' => [
        'write' => fn (SQLite3 $connection) => $connection->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 2; CREATE TABLE records (id INTEGER)'),
        'kind' => 'schema',
        'message' => 'The store at [%s] has schema version 2, not 1.',
    ],
]);

it('keeps the latest 100 failure lines', function (int $earlier, int $kept) {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $lines = array_map(fn (int $index) => json_encode(['at' => $index, 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 1]), range(1, $earlier));
    file_put_contents(dirname(app(Configuration::class)->database).'/failures.jsonl', implode("\n", $lines)."\n");

    Cache::get('dropped');
    Nightwatch::digest();

    $failures = failureLines();

    expect($failures)->toHaveCount($kept)
        ->and($failures[0]['at'])->toBe($earlier - $kept + 2)
        ->and(end($failures)['kind'])->toBe('foreign');
})->with([
    'up to the cap' => ['earlier' => 99, 'kept' => 100],
    'over the cap' => ['earlier' => 100, 'kept' => 100],
]);

it('reports once per process however many batches it drops', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));

    Cache::get('first');
    Nightwatch::digest();
    Cache::get('second');
    Nightwatch::digest();

    expect(failureLines())->toHaveCount(2);
    Exceptions::assertReportedCount(1);
});

it('creates the failure file private to its owner', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));

    Cache::get('dropped');
    Nightwatch::digest();

    expect(fileperms(dirname(app(Configuration::class)->database).'/failures.jsonl') & 0777)->toBe(0600);
})->group('posix');

it('swallows a failure of the failure path', function (Closure $break, bool $logged) {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $break();

    // Digest through the ingest itself, as Nightwatch would swallow what escapes it.
    Cache::get('dropped');
    app(Core::class)->ingest->digest();
    Cache::get('dropped again');
    app(Core::class)->ingest->digest();

    expect(is_file(dirname(app(Configuration::class)->database).'/failures.jsonl'))->toBe($logged);
})->with([
    'a failure file that cannot be written' => [fn () => mkdir(dirname(app(Configuration::class)->database).'/failures.jsonl'), false],
    'a report that throws' => [function () {
        app()->instance(ExceptionHandler::class, new class(app()) extends Handler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('The handler failed.');
            }
        });
    }, true],
]);
