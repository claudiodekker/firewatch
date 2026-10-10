<?php

use ClaudioDekker\Firewatch\Actions\ClearStore;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

const CLEAR_NOW = '2026-09-30 14:00:00';

function clearIds(string $where = '1 = 1'): array
{
    return array_column(storeRows("SELECT id FROM records WHERE {$where} ORDER BY id"), 'id');
}

function clearMarkers(): Markers
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection));
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
 * Fill the store with 3 requests, 2 logs, a user, a drift row and a dropped batch.
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

/**
 * @param  array<string, mixed>  $options
 * @return array{exit: int, output: string}
 */
function clearResult(array $options = ['--force' => true]): array
{
    $exit = Artisan::call('firewatch:clear', $options);

    return ['exit' => $exit, 'output' => trim(Artisan::output())];
}

/**
 * Run the command from the words a shell would pass, which parses options the way a terminal does.
 *
 * @param  list<string>  $words
 * @return array{exit: int, output: string}
 */
function clearArgvResult(array $words): array
{
    $output = new BufferedOutput;
    $exit = Artisan::handle(new ArgvInput(['artisan', 'firewatch:clear', ...$words]), $output);

    return ['exit' => $exit, 'output' => trim($output->fetch())];
}

/**
 * @param  array<string, string|int>  $replace
 */
function clearedPattern(string $key, array $replace): string
{
    $text = __("firewatch::messages.clear.{$key}", [...$replace, 'before' => '@@SIZE@@', 'after' => '@@SIZE@@']);

    return '/'.str_replace('@@SIZE@@', '[\d.,]+ \w+', preg_quote($text, '/')).'/';
}

beforeEach(function () {
    $this->travelTo(CLEAR_NOW);
    config()->set('app.timezone', 'UTC');
});

describe('a store in every state', function () {
    it('has nothing to clear when there is no store, and creates nothing', function () {
        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.nothing'))
            ->and(file_exists(dirname(clearStorePath())))->toBeFalse();
    });

    it('has nothing to clear in an empty file, and leaves it', function () {
        mkdir(dirname(clearStorePath()), recursive: true);
        touch(clearStorePath());

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.nothing'))
            ->and(filesize(clearStorePath()))->toBe(0)
            ->and(scandir(dirname(clearStorePath())))->toBe(['.', '..', basename(clearStorePath())]);
    });

    it('refuses a store of another schema, naming the one it has found', function (int $version) {
        clearPopulatedStore();
        $store = new SQLite3(clearStorePath());
        $store->exec("PRAGMA user_version = {$version}");
        $store->close();
        $before = md5_file(clearStorePath());

        $result = clearResult();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.schema', ['found' => $version, 'expected' => Schema::VERSION]))
            ->and(md5_file(clearStorePath()))->toBe($before);
    })->with([
        'an older schema' => 0,
        'a newer schema' => Schema::VERSION + 1,
    ]);

    it('refuses a damaged store', function () {
        clearPopulatedStore();
        $this->endCapture();
        $handle = fopen(clearStorePath(), 'r+b');
        fseek($handle, 4096);
        fwrite($handle, str_repeat("\xff", 8192));
        fclose($handle);
        $before = md5_file(clearStorePath());

        $result = clearResult();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.damaged'))
            ->and(md5_file(clearStorePath()))->toBe($before)
            ->and(file_exists(clearStorePath().'.corrupt'))->toBeFalse();
    });

    it('refuses a file that is not a Firewatch store, whatever it is', function (Closure $arrange) {
        mkdir(dirname(clearStorePath()), recursive: true);
        $arrange(clearStorePath());
        $before = md5_file(clearStorePath());

        $result = clearResult();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.foreign', ['path' => clearStorePath()]))
            ->and(md5_file(clearStorePath()))->toBe($before);
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

        $result = clearResult();
        app()->forgetInstance(Reader::class);

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.sqlite', ['version' => '3.30.0', 'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION]))
            ->and(clearIds())->toHaveCount(6);
    });

    it('says the store is busy after its fixed wait, and deletes nothing', function () {
        clearPopulatedStore();
        $this->endCapture();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class), app(FailureLog::class)) extends ClearStore
        {
            protected const BUSY_TIMEOUT_MILLISECONDS = 20;
        });
        $connection = new SQLite3(clearStorePath());
        $connection->busyTimeout(20);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        $result = clearResult();
        $connection->exec('ROLLBACK');

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.busy'))
            ->and(clearIds())->toHaveCount(6);
    });

    it('runs while Firewatch is off', function () {
        config()->set('firewatch.enabled', false);
        registerFirewatch();

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.nothing'));
    });
});

describe('clearing everything', function () {
    it('removes the records and the users and the failure lines, keeps the drift, and says how many', function () {
        clearPopulatedStore();

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(clearedPattern('cleared', ['records' => 6, 'users' => 1]))
            ->and(clearIds())->toBe([])
            ->and(storeRows('SELECT id FROM users'))->toBe([])
            ->and(storeRows('SELECT kind, type FROM drift'))->toBe([['kind' => 'unknown_field', 'type' => 'cache-event']])
            ->and(filesize(clearFailuresPath()))->toBe(0);
    });

    it('also removes the records of unknown start and the error placeholders', function () {
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'soon'])]);

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(clearedPattern('cleared', ['records' => 1, 'users' => 0]))
            ->and(clearIds())->toBe([]);
    });

    it('stamps the clear before it deletes, and the history of every type starts there', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');

        runClear()->run();

        expect(clearMarkers()->clearedAt)->toBe(1790778600.0)
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
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class), app(FailureLog::class)) extends ClearStore
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

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(clearedPattern('cleared', ['records' => 6, 'users' => 1]))
            ->and(clearIds())->toBe([$last + 1]);
    });

    it('deletes in chunks until none are left up to the newest id it saw', function () {
        clearPopulatedStore();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class), app(FailureLog::class)) extends ClearStore
        {
            protected const CHUNK_ROWS = 2;
        });

        $result = clearResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(clearedPattern('cleared', ['records' => 6, 'users' => 1]))
            ->and(clearIds())->toBe([]);
    });

    it('returns the freed pages to the file', function () {
        ingest(array_map(fn (int $i) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0 + $i]), range(1, 300)));
        $this->endCapture();
        $before = filesize(clearStorePath());

        runClear()->run();

        $pages = storeRows('PRAGMA freelist_count');

        expect($pages)->toBe([['freelist_count' => 0]])
            ->and(filesize(clearStorePath()))->toBeLessThan($before);
    });
});

it('says so when a reader keeps the log from being truncated, and still succeeds', function () {
    clearPopulatedStore();
    $reader = new SQLite3(clearStorePath(), SQLITE3_OPEN_READONLY);
    $reader->exec('BEGIN');
    $reader->querySingle('SELECT count(*) FROM records');

    $result = clearResult();
    $reader->exec('COMMIT');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain(__('firewatch::messages.clear.log_in_use'))
        ->and(clearIds())->toBe([]);
});

describe('clearing one type', function () {
    it('removes the records of that type and nothing else, and says how many', function () {
        clearPopulatedStore();
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 'soon'])]);
        $drift = storeRows('SELECT kind, type FROM drift');
        $this->travelTo('2026-09-30 14:30:00');

        $result = clearResult(['--type' => 'log', '--force' => true]);

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(clearedPattern('cleared_type', ['records' => 2, 'type' => 'log']))
            ->and(array_column(storeRows('SELECT DISTINCT type FROM records ORDER BY type'), 'type'))->toBe(['cache-event', 'request'])
            ->and(clearIds("type = 'request'"))->toHaveCount(4)
            ->and(storeRows('SELECT id FROM users'))->toHaveCount(1)
            ->and(storeRows('SELECT kind, type FROM drift'))->toBe($drift)
            ->and(filesize(clearFailuresPath()))->toBeGreaterThan(0);
    });

    it('stamps only that type, so only its history starts at the clear', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');

        runClear(['--type' => 'log', '--force' => true])->run();
        runClear(['--type' => 'request', '--force' => true])->run();

        $markers = clearMarkers();

        expect($markers->clearedAt)->toBeNull()
            ->and($markers->clearedTypes)->toBe(['log' => 1790778600.0, 'request' => 1790778600.0]);
    });

    it('never moves the stamp of a type back', function () {
        clearPopulatedStore();
        $this->travelTo('2026-09-30 14:30:00');
        runClear(['--type' => 'log', '--force' => true])->run();
        $this->travelTo('2026-09-30 14:10:00');

        runClear(['--type' => 'log', '--force' => true])->run();

        expect(clearMarkers()->clearedTypes)->toBe(['log' => 1790778600.0]);
    });

    it('refuses a type that is not one of the twelve, user included, listing them', function (string $type) {
        clearPopulatedStore();

        $result = clearResult(['--type' => $type, '--force' => true]);

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.unknown_type', ['type' => $type, 'types' => 'request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request, queued-job']))
            ->and(clearIds())->toHaveCount(6);
    })->with([
        'the user type' => 'user',
        'another case' => 'Log',
        'a plural' => 'logs',
        'an underscore' => 'job_attempt',
        'an empty value' => '',
    ]);

    it('refuses a type flag without a value, wherever it stands, instead of clearing everything', function (array $words) {
        clearPopulatedStore();

        $result = clearArgvResult($words);

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.unknown_type', ['type' => '', 'types' => 'request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request, queued-job']))
            ->and(clearIds())->toHaveCount(6)
            ->and(storeRows('SELECT id FROM users'))->toHaveCount(1);
    })->with([
        'before another option' => [['--type', '--force']],
        'last' => [['--force', '--type']],
    ]);
});

describe('the confirmation', function () {
    it('asks first, with no as the default, and deletes nothing when it is declined', function () {
        clearPopulatedStore();

        $command = runClear([])
            ->expectsConfirmation(__('firewatch::messages.clear.confirm', ['path' => clearStorePath()]), 'no')
            ->expectsOutput(__('firewatch::messages.clear.declined'));

        $exit = $command->run();

        expect($exit)->toBe(1)
            ->and(clearIds())->toHaveCount(6);
    });

    it('clears once it is confirmed, and asks about the type when there is one', function () {
        clearPopulatedStore();

        $command = runClear(['--type' => 'log'])
            ->expectsConfirmation(__('firewatch::messages.clear.confirm_type', ['path' => clearStorePath(), 'type' => 'log']), 'yes');

        $exit = $command->run();

        expect($exit)->toBe(0)
            ->and(clearIds())->toHaveCount(4);
    });

    it('aborts without asking when it can\'t, unless it is forced', function () {
        clearPopulatedStore();

        $unforced = clearResult(['--no-interaction' => true]);
        $idsAfterUnforced = clearIds();
        $forced = clearResult(['--no-interaction' => true, '--force' => true]);

        expect($unforced['exit'])->toBe(1)
            ->and($unforced['output'])->toBe(__('firewatch::messages.clear.not_forced'))
            ->and($idsAfterUnforced)->toHaveCount(6)
            ->and($forced['exit'])->toBe(0)
            ->and(clearIds())->toBe([]);
    });

    it('does not ask when there is nothing to clear', function () {
        $command = runClear([])->expectsOutput(__('firewatch::messages.clear.nothing'));

        $exit = $command->run();

        expect($exit)->toBe(0);
    });
});
