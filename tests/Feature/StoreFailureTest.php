<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Notices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Process\Process;

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
    return array_column(storeRows('SELECT key FROM cache_events ORDER BY id'), 'key');
}

/**
 * Start a second process that holds the write lock until the returned closure releases it.
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

    $release = function () use ($process, $pipes) {
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
            proc_close($process);
        }
    };
    test()->beforeApplicationDestroyed($release);

    expect(fgets($pipes[1]))->toBe("locked\n");

    return $release;
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
        'entry' => 'batch',
        'kind' => 'busy',
        'code' => 5,
        'message' => 'database is locked',
        'dropped' => 2,
    ]])->and(storedCacheKeys())->toBe(['kept']);
    expect(notices())->toBe(['Firewatch could not store a batch of 2 records: database is locked']);
    Exceptions::assertNothingReported();
})->with([
    'failing fast at 0' => 0,
    'at a budget of 50 ms' => 50,
])->group('process');

it('records a file that is not a Firewatch store by its kind', function (Closure $write, string $kind, ?int $code, string $message) {
    $now = CarbonImmutable::parse('2026-09-30 12:00:00.250000');
    $this->travelTo($now);
    $database = app(Configuration::class)->database;
    mkdir(dirname($database), recursive: true);
    $write($database);

    Cache::get('dropped');
    Nightwatch::digest();

    expect(failureLines())->toBe([[
        'at' => (float) $now->format('U.u'),
        'entry' => 'batch',
        'kind' => $kind,
        'code' => $code,
        'message' => sprintf($message, $database),
        'dropped' => 1,
    ]]);
})->with([
    'a foreign SQLite file' => [
        'write' => fn (string $database) => (new SQLite3($database))->exec('CREATE TABLE orders (id INTEGER)'),
        'kind' => 'foreign',
        'code' => null,
        'message' => 'The file at [%s] is not a Firewatch store.',
    ],
    'a file that is not a database' => [
        'write' => fn (string $database) => file_put_contents($database, str_repeat('not a database ', 100)),
        'kind' => 'foreign',
        'code' => null,
        'message' => 'The file at [%s] is not a Firewatch store.',
    ],
]);

it('keeps the latest 100 failure lines', function (int $earlier, int $kept) {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $lines = array_map(fn (int $index) => json_encode(['at' => $index, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 1]), range(1, $earlier));
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

it('writes one notice however many batches it drops', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $database = app(Configuration::class)->database;

    Cache::get('first');
    Nightwatch::digest();
    Cache::get('second');
    Nightwatch::digest();

    expect(failureLines())->toHaveCount(2)
        ->and(notices())->toBe(["Firewatch could not store a batch of 1 records: The file at [{$database}] is not a Firewatch store."]);
    Exceptions::assertNothingReported();
});

it('keeps a batch a test run dropped out of the records of the next test', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Fixtures/TestRun/DroppedBatchTest.php'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_BUSY_TIMEOUT' => '0', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite'],
        timeout: 30,
    );

    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(array_column(failureLines(), 'dropped'))->toBe([1])
        ->and(storeRows("SELECT sql FROM queries WHERE sql LIKE '%dropped' OR sql LIKE '%kept'"))->toBe([['sql' => 'select 2 as kept']])
        ->and(storeRows('SELECT message FROM exceptions'))->toBe([])
        ->and(storeRows('SELECT message FROM logs'))->toBe([])
        ->and($process->getErrorOutput())->toContain('Firewatch could not store a batch of 1 records: database is locked');
})->group('process');

it('creates the failure file private to its owner', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));

    Cache::get('dropped');
    Nightwatch::digest();

    expect(fileperms(dirname(app(Configuration::class)->database).'/failures.jsonl') & 0777)->toBe(0600);
})->group('posix');

it('still writes a notice about a dropped batch when its failure line can\'t be written', function (Closure $break) {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $failures = dirname(app(Configuration::class)->database).'/failures.jsonl';
    $break($failures);

    // Digest through the ingest itself, as Nightwatch would swallow what escapes it.
    Cache::get('dropped');
    app(Core::class)->ingest->digest();

    expect(notices())->toHaveCount(1)
        ->and(notices()[0])->toStartWith('Firewatch could not store a batch of 1 records: The file at [');
    Exceptions::assertNothingReported();
})->with([
    'a failure file that is a directory' => [fn (string $failures) => mkdir($failures)],
]);

it('loses a failure line rather than wait for a lock held elsewhere', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $failures = dirname(app(Configuration::class)->database).'/failures.jsonl';
    $handle = fopen($failures, 'c+');
    flock($handle, LOCK_EX);

    Cache::get('dropped');
    Nightwatch::digest();
    fclose($handle);

    expect(file_get_contents($failures))->toBe('')
        ->and(notices())->toHaveCount(1);
    Exceptions::assertNothingReported();
});

it('keeps recording dropped batches when the notice can\'t be written', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    app()->bind(Notices::class, fn () => new class implements Notices
    {
        public function write(string $notice): void
        {
            throw new RuntimeException('The error log failed.');
        }
    });
    registerFirewatch();

    // Digest through the ingest itself, as Nightwatch would swallow what escapes it.
    Cache::get('dropped');
    app(Core::class)->ingest->digest();
    Cache::get('dropped again');
    app(Core::class)->ingest->digest();

    expect(failureLines())->toHaveCount(2);
});
