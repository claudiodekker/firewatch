<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Notices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
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

/**
 * Fill the failure file with the given lines, as earlier failures left them.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function writeFailureLines(array $lines): void
{
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));

    file_put_contents(dirname(app(Configuration::class)->database).'/failures.jsonl', implode("\n", array_map(json_encode(...), $lines))."\n");
}

it('appends a failure line while the file holds fewer than 100', function () {
    writeFailureLines(array_map(fn (int $at) => ['at' => $at, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 2], range(1, 99)));

    Cache::get('dropped');
    Nightwatch::digest();

    $failures = failureLines();

    expect($failures)->toHaveCount(100)
        ->and($failures[0])->toBe(['at' => 1, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 2])
        ->and(end($failures)['kind'])->toBe('foreign');
});

it('keeps 100 failure lines by folding the oldest dropped batches of one kind into a line that counts them', function () {
    writeFailureLines(array_map(fn (int $at) => ['at' => $at, 'entry' => 'batch', 'kind' => $at === 1 ? 'full' : 'busy', 'code' => 5, 'message' => "failure {$at}", 'dropped' => 2], range(1, 100)));

    Cache::get('dropped');
    Nightwatch::digest();
    Cache::get('dropped again');
    Nightwatch::digest();

    $failures = failureLines();

    expect($failures)->toHaveCount(100)
        ->and($failures[0])->toBe(['at' => 1, 'entry' => 'batch', 'kind' => 'full', 'code' => 5, 'message' => 'failure 1', 'dropped' => 2])
        ->and($failures[1])->toBe(['at' => 2.0, 'until' => 4.0, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'failure 4', 'dropped' => 6, 'batches' => 3])
        ->and($failures[2]['at'])->toBe(5)
        ->and(array_column(array_slice($failures, -2), 'kind'))->toBe(['foreign', 'foreign'])
        ->and(array_sum(array_column($failures, 'dropped')))->toBe(202);
});

it('drops the oldest failed prune before a dropped batch that has no other of its kind to fold into', function () {
    writeFailureLines([
        ['at' => 1, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 7],
        ...array_map(fn (int $at) => ['at' => $at, 'entry' => 'prune', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked'], range(2, 100)),
    ]);

    Cache::get('dropped');
    Nightwatch::digest();

    $failures = failureLines();

    expect($failures)->toHaveCount(100)
        ->and($failures[0]['dropped'])->toBe(7)
        ->and($failures[1]['at'])->toBe(3)
        ->and(end($failures)['dropped'])->toBe(1);
});

it('drops a line it can\'t read before it folds or drops one it can', function () {
    writeFailureLines(array_map(fn (int $at) => ['at' => $at, 'entry' => 'batch', 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => 2], range(1, 99)));
    file_put_contents(dirname(app(Configuration::class)->database).'/failures.jsonl', "not a line\n", FILE_APPEND);

    Cache::get('dropped');
    Nightwatch::digest();

    $failures = failureLines();

    expect($failures)->toHaveCount(100)
        ->and(array_sum(array_column($failures, 'dropped')))->toBe(199)
        ->and(array_column($failures, 'batches'))->toBe([]);
});

it('keeps a line whose message holds a character outside ASCII when it rewrites the file', function () {
    writeFailureLines(array_map(fn (int $at) => ['at' => $at, 'entry' => 'batch', 'kind' => "kind {$at}", 'code' => null, 'message' => 'The file at [/Users/Åsa/firewatch.sqlite] is not a Firewatch store.', 'dropped' => 2], range(1, 100)));

    Cache::get('dropped');
    Nightwatch::digest();

    expect(array_sum(array_column(failureLines(), 'dropped')))->toBe(199);
});

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

it('waits 50 ms for the failure file, then loses the line rather than hold up the request', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $failures = dirname(app(Configuration::class)->database).'/failures.jsonl';
    $handle = fopen($failures, 'c+');
    flock($handle, LOCK_EX);

    Cache::get('dropped');
    Nightwatch::digest();
    fclose($handle);

    expect(file_get_contents($failures))->toBe('')
        ->and(notices())->toHaveCount(1);
    Sleep::assertSequence(array_fill(0, 10, Sleep::usleep(5_000)));
    Exceptions::assertNothingReported();
});

it('records the failure once the failure file is free again', function () {
    writeStoreFile(fn (SQLite3 $connection) => $connection->exec('CREATE TABLE orders (id INTEGER)'));
    $handle = fopen(dirname(app(Configuration::class)->database).'/failures.jsonl', 'c+');
    flock($handle, LOCK_EX);
    Sleep::whenFakingSleep(fn () => flock($handle, LOCK_UN));

    Cache::get('dropped');
    Nightwatch::digest();
    fclose($handle);

    expect(array_column(failureLines(), 'dropped'))->toBe([1]);
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

it('loses no records when the workers of a parallel test run share the store', function () {
    $workers = array_map(fn () => new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Fixtures/TestRun/ParallelWorkerTest.php'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite'],
        timeout: 120,
    ), range(1, 4));

    array_map(fn (Process $worker) => $worker->start(), $workers);
    $exitCodes = array_map(fn (Process $worker) => $worker->wait(), $workers);

    expect($exitCodes)->toBe([0, 0, 0, 0])
        ->and(storeRows('SELECT type, count(*) AS records FROM records GROUP BY type ORDER BY type'))->toBe([
            ['type' => 'query', 'records' => 320],
            ['type' => 'request', 'records' => 160],
        ])->and(file_exists(dirname(app(Configuration::class)->database).'/failures.jsonl'))->toBeFalse();
})->group('process');
