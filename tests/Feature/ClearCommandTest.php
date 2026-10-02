<?php

use ClaudioDekker\Firewatch\Actions\ClearStore;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Testing\PendingCommand;

const CLEAR_NOW = '2026-09-30 14:00:00';

/**
 * @return list<array<string, mixed>>
 */
function clearRows(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    });
}

function clearIds(string $where = '1 = 1'): array
{
    return array_column(clearRows("SELECT id FROM records WHERE {$where} ORDER BY id"), 'id');
}

function clearMeta(): array
{
    return array_column(clearRows('SELECT key, value FROM meta'), 'value', 'key');
}

function clearStorePath(): string
{
    return app(Configuration::class)->database;
}

function clearFailuresPath(): string
{
    return dirname(clearStorePath()).'/failures.jsonl';
}

/**
 * A store with 3 requests, 2 logs, a signed-in user, a drift row and a dropped batch, one of the records of unknown start.
 */
function clearPopulatedStore(): void
{
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0]),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776001.0]),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776002.0]),
        syntheticRecord(RecordType::LOG)->with(['timestamp' => 1790776003.0]),
        syntheticRecord(RecordType::LOG)->with(['timestamp' => 1790776004.0]),
        syntheticRecord(RecordType::CACHE_EVENT)->with(['timestamp' => 1790776005.0, 'colour' => 'red']),
        syntheticRecord(RecordType::USER),
    ]);
    file_put_contents(clearFailuresPath(), json_encode(['at' => 1.0, 'kind' => 'busy', 'code' => 5, 'message' => 'busy', 'dropped' => 2])."\n");
}

/**
 * @param  array<string, mixed>  $options
 */
function runClear(array $options = ['--force' => true]): PendingCommand
{
    return test()->artisan('firewatch:clear', $options);
}

beforeEach(function () {
    $this->travelTo(CLEAR_NOW);
    config()->set('app.timezone', 'UTC');
});

describe('a store in every state', function () {
    it('has nothing to clear when there is no store, and creates nothing', function () {
        runClear()->expectsOutput('Nothing to clear.')->assertExitCode(0);

        expect(file_exists(dirname(clearStorePath())))->toBeFalse();
    });

    it('has nothing to clear in an empty file, and leaves it', function () {
        mkdir(dirname(clearStorePath()), recursive: true);
        touch(clearStorePath());

        runClear()->expectsOutput('Nothing to clear.')->assertExitCode(0);

        expect(filesize(clearStorePath()))->toBe(0)
            ->and(scandir(dirname(clearStorePath())))->toBe(['.', '..', basename(clearStorePath())]);
    });

    it('refuses a store of another schema, naming the one it has found', function (int $version) {
        clearPopulatedStore();
        $store = new SQLite3(clearStorePath());
        $store->exec("PRAGMA user_version = {$version}");
        $store->close();
        $before = md5_file(clearStorePath());

        runClear()
            ->expectsOutput("The store was written by another Firewatch schema (found {$version}, expected ".Schema::VERSION.'). Run with --drop to rebuild it now, or let the next captured batch do it.')
            ->assertExitCode(1);

        expect(md5_file(clearStorePath()))->toBe($before);
    })->with([0, Schema::VERSION + 1]);

    it('refuses a damaged store', function () {
        clearPopulatedStore();
        $handle = fopen(clearStorePath(), 'r+b');
        fseek($handle, 4096);
        fwrite($handle, str_repeat("\xff", 8192));
        fclose($handle);
        $before = md5_file(clearStorePath());

        runClear()->expectsOutput('The store file is damaged; run with --drop or let the next capture replace it.')->assertExitCode(1);

        expect(md5_file(clearStorePath()))->toBe($before)
            ->and(file_exists(clearStorePath().'.corrupt'))->toBeFalse();
    });

    it('refuses a file that is not a Firewatch store, whatever it is', function (Closure $arrange) {
        mkdir(dirname(clearStorePath()), recursive: true);
        $arrange(clearStorePath());
        $before = md5_file(clearStorePath());

        runClear()->expectsOutput(clearStorePath().' is not a Firewatch store; nothing was changed.')->assertExitCode(1);

        expect(md5_file(clearStorePath()))->toBe($before);
    })->with([
        'a text file' => [fn (string $path) => file_put_contents($path, 'not a database')],
        'another application\'s SQLite file' => [function (string $path) {
            $store = new SQLite3($path);
            $store->exec('CREATE TABLE orders (id INTEGER); INSERT INTO orders VALUES (1)');
            $store->close();
        }],
    ]);

    it('refuses with the reason when SQLite is too old', function () {
        clearPopulatedStore();
        app()->instance(Reader::class, new Reader(app(Configuration::class), '3.30.0'));

        runClear()->expectsOutputToContain('SQLite 3.30.0 is older than')->assertExitCode(1);
        app()->forgetInstance(Reader::class);

        expect(clearIds())->toHaveCount(6);
    });

    it('says the store is busy after its fixed wait, and deletes nothing', function () {
        clearPopulatedStore();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class)) extends ClearStore
        {
            protected const BUSY_TIMEOUT_MILLISECONDS = 20;
        });
        $connection = new SQLite3(clearStorePath());
        $connection->busyTimeout(20);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        runClear()->expectsOutput('The store is busy; try again.')->assertExitCode(1);
        $connection->exec('ROLLBACK');

        expect(clearIds())->toHaveCount(6);
    });

    it('runs while Firewatch is off', function () {
        config()->set('firewatch.enabled', false);
        registerFirewatch();

        runClear()->expectsOutput('Nothing to clear.')->assertExitCode(0);
    });
});

describe('clearing everything', function () {
    it('removes the records and the users and the failure lines, keeps the drift, and says how many', function () {
        clearPopulatedStore();

        runClear()->expectsOutputToContain('Cleared 6 records and 1 users. Store size ')->assertExitCode(0);

        expect(clearIds())->toBe([])
            ->and(clearRows('SELECT id FROM users'))->toBe([])
            ->and(clearRows('SELECT kind, type FROM drift'))->toBe([['kind' => 'unknown_field', 'type' => 'cache-event']])
            ->and(filesize(clearFailuresPath()))->toBe(0);
    });

    it('also removes the records of unknown start and the error placeholders', function () {
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'soon'])]);

        runClear()->expectsOutputToContain('Cleared 1 records')->assertExitCode(0);

        expect(clearIds())->toBe([]);
    });

    it('stamps the clear before it deletes, and the history of every type starts there', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');

        runClear()->run();

        expect(clearMeta())->toMatchArray(['cleared_at' => '1790778600.000000'])
            ->and(Envelope::assert(Overview::class)['coverage']['history'])->toMatchArray(['from' => 1790778600.0, 'reason' => 'cleared']);
    });

    it('keeps the ids going, so no cursor repeats', function () {
        clearPopulatedStore();
        $last = max(clearIds());

        runClear()->run();
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776100.0])]);

        expect(clearIds())->toBe([$last + 1]);
    });

    it('keeps a batch that arrives while it clears', function () {
        clearPopulatedStore();
        $last = max(clearIds());
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class)) extends ClearStore
        {
            protected bool $arrived = false;

            protected function deleteChunk(?string $type, int $through): int
            {
                if (! $this->arrived) {
                    $this->arrived = true;
                    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776100.0])]);
                }

                return parent::deleteChunk($type, $through);
            }
        });

        runClear()->expectsOutputToContain('Cleared 6 records')->assertExitCode(0);

        expect(clearIds())->toBe([$last + 1]);
    });

    it('deletes in chunks until none are left up to the newest id it saw', function () {
        clearPopulatedStore();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class)) extends ClearStore
        {
            protected const CHUNK_ROWS = 2;
        });

        runClear()->expectsOutputToContain('Cleared 6 records')->assertExitCode(0);

        expect(clearIds())->toBe([]);
    });

    it('returns the freed pages to the file', function () {
        ingest(array_map(fn (int $i) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0 + $i]), range(1, 300)));
        $before = filesize(clearStorePath());

        runClear()->run();

        $pages = clearRows('PRAGMA freelist_count');

        expect($pages)->toBe([['freelist_count' => 0]])
            ->and(filesize(clearStorePath()))->toBeLessThan($before);
    });
});

it('says so when a reader keeps the log from being truncated, and still succeeds', function () {
    clearPopulatedStore();
    $reader = new SQLite3(clearStorePath(), SQLITE3_OPEN_READONLY);
    $reader->exec('BEGIN');
    $reader->querySingle('SELECT count(*) FROM records');

    runClear()->expectsOutput('The write-ahead log was not truncated because the store is in use.')->assertExitCode(0);
    $reader->exec('COMMIT');

    expect(clearIds())->toBe([]);
});

describe('clearing one type', function () {
    it('removes the records of that type and nothing else, and says how many', function () {
        clearPopulatedStore();
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'soon'])]);
        $drift = clearRows('SELECT kind, type FROM drift');
        $this->travelTo('2026-09-30 14:30:00');

        runClear(['--type' => 'log', '--force' => true])->expectsOutputToContain('Cleared 2 log records. Store size ')->assertExitCode(0);

        expect(array_column(clearRows('SELECT DISTINCT type FROM records ORDER BY type'), 'type'))->toBe(['cache-event', 'request'])
            ->and(clearIds("type = 'request'"))->toHaveCount(4)
            ->and(clearRows('SELECT id FROM users'))->toHaveCount(1)
            ->and(clearRows('SELECT kind, type FROM drift'))->toBe($drift)
            ->and(filesize(clearFailuresPath()))->toBeGreaterThan(0);
    });

    it('stamps only that type, so only its history starts at the clear', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');

        runClear(['--type' => 'log', '--force' => true])->run();
        runClear(['--type' => 'request', '--force' => true])->run();

        expect(clearMeta())->not->toHaveKey('cleared_at')
            ->and(json_decode(clearMeta()['cleared_types'], associative: true))->toBe(['log' => 1790778600.0, 'request' => 1790778600.0]);
    });

    it('never moves the stamp of a type back', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');
        runClear(['--type' => 'log', '--force' => true])->run();
        $this->travelTo('2026-09-30 14:10:00');

        runClear(['--type' => 'log', '--force' => true])->run();

        expect(json_decode(clearMeta()['cleared_types'], associative: true))->toBe(['log' => 1790778600.0]);
    });

    it('refuses a type that is not one of the twelve, user included, listing them', function (string $type) {
        clearPopulatedStore();

        runClear(['--type' => $type, '--force' => true])
            ->expectsOutput("Unknown type \"{$type}\". Valid types: request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request, queued-job.")
            ->assertExitCode(1);

        expect(clearIds())->toHaveCount(6);
    })->with(['user', 'Log', 'logs', 'job_attempt', '']);
});

describe('the confirmation', function () {
    it('asks first, with no as the default, and deletes nothing when it is declined', function () {
        clearPopulatedStore();

        runClear([])
            ->expectsConfirmation('This deletes all captured records, users and failure lines from '.clearStorePath().'. Continue?', 'no')
            ->expectsOutput('Aborted.')
            ->assertExitCode(1);

        expect(clearIds())->toHaveCount(6);
    });

    it('clears once it is confirmed, and asks about the type when there is one', function () {
        clearPopulatedStore();

        runClear(['--type' => 'log'])
            ->expectsConfirmation('This deletes all log records from '.clearStorePath().'. Continue?', 'yes')
            ->assertExitCode(0);

        expect(clearIds())->toHaveCount(4);
    });

    it('aborts without asking when it can\'t, unless it is forced', function () {
        clearPopulatedStore();

        $this->artisan('firewatch:clear', ['--no-interaction' => true])
            ->expectsOutput('Aborted: pass --force to clear without confirmation.')
            ->assertExitCode(1);

        expect(clearIds())->toHaveCount(6);

        $this->artisan('firewatch:clear', ['--no-interaction' => true, '--force' => true])->assertExitCode(0);

        expect(clearIds())->toBe([]);
    });

    it('does not ask when there is nothing to clear', function () {
        runClear([])->expectsOutput('Nothing to clear.')->assertExitCode(0);
    });
});
