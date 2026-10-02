<?php

use ClaudioDekker\Firewatch\Actions\ClearStore;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use Illuminate\Testing\PendingCommand;

const DROP_NOW = '2026-09-30 14:00:00';

/**
 * @return list<array<string, mixed>>
 */
function dropRows(string $sql): array
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

function dropPath(): string
{
    return app(Configuration::class)->database;
}

function dropFailuresPath(): string
{
    return dirname(dropPath()).'/failures.jsonl';
}

/**
 * A store with records, a user, drift, a clear marker and a dropped batch.
 */
function dropPopulatedStore(): void
{
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0]),
        syntheticRecord(RecordType::LOG)->with(['timestamp' => 1790776003.0]),
        syntheticRecord(RecordType::CACHE_EVENT)->with(['timestamp' => 1790776005.0, 'colour' => 'red']),
        syntheticRecord(RecordType::USER),
    ]);
    test()->artisan('firewatch:clear', ['--type' => 'log', '--force' => true])->run();
    file_put_contents(dropFailuresPath(), json_encode(['at' => 1.0, 'kind' => 'busy', 'code' => 5, 'message' => 'busy', 'dropped' => 2])."\n");
}

/**
 * @param  array<string, mixed>  $options
 */
function runDrop(array $options = ['--force' => true]): PendingCommand
{
    return test()->artisan('firewatch:clear', ['--drop' => true, ...$options]);
}

function dropCorrupt(): void
{
    $handle = fopen(dropPath(), 'r+b');
    fseek($handle, 4096);
    fwrite($handle, str_repeat("\xff", 8192));
    fclose($handle);
}

beforeEach(function () {
    $this->travelTo(DROP_NOW);
    config()->set('app.timezone', 'UTC');
});

it('can\'t be combined with a type, and changes nothing', function () {
    dropPopulatedStore();

    runDrop(['--type' => 'log', '--force' => true])->expectsOutput('`--drop` can\'t be combined with `--type`.')->assertExitCode(1);

    expect(dropRows('SELECT id FROM records'))->toHaveCount(2);
});

describe('a healthy store', function () {
    it('is rebuilt in place as a new one, and says so with its size before and after', function () {
        dropPopulatedStore();
        $this->travelTo('2026-09-30 15:00:00');

        runDrop()->expectsOutputToContain('Rebuilt the store at '.dropPath().'. Store size ')->assertExitCode(0);

        expect(dropRows('SELECT id FROM records'))->toBe([])
            ->and(dropRows('SELECT id FROM users'))->toBe([])
            ->and(dropRows('SELECT kind FROM drift'))->toBe([])
            ->and(array_column(dropRows('SELECT key, value FROM meta'), 'value', 'key'))->toBe(['created_at' => '1790780400.000000'])
            ->and(filesize(dropFailuresPath()))->toBe(0)
            ->and(dropRows('PRAGMA user_version'))->toBe([['user_version' => Schema::VERSION]]);
    });

    it('restarts the ids at 1, and never unlinks the file', function () {
        dropPopulatedStore();
        $inode = fileinode(dropPath());

        runDrop()->assertExitCode(0);
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776100.0])]);

        expect(array_column(dropRows('SELECT id FROM records'), 'id'))->toBe([1])
            ->and(fileinode(dropPath()))->toBe($inode)
            ->and(file_exists(dropPath().'.corrupt'))->toBeFalse();
    });

    it('returns every freed page to the file', function () {
        ingest(array_map(fn (int $i) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0 + $i]), range(1, 300)));
        $before = filesize(dropPath());

        runDrop()->run();

        expect(dropRows('PRAGMA freelist_count'))->toBe([['freelist_count' => 0]])
            ->and(filesize(dropPath()))->toBeLessThan($before);
    });

    it('says the history starts again at the rebuild', function () {
        dropPopulatedStore();
        $this->travelTo('2026-09-30 15:00:00');

        runDrop()->run();

        expect(dropRows("SELECT value FROM meta WHERE key = 'created_at'"))->toBe([['value' => '1790780400.000000']]);
    });
});

describe('a store in another state', function () {
    it('has nothing to drop when there is no store, or an empty file, and creates nothing', function () {
        runDrop()->expectsOutput('Nothing to clear.')->assertExitCode(0);

        expect(file_exists(dirname(dropPath())))->toBeFalse();

        mkdir(dirname(dropPath()), recursive: true);
        touch(dropPath());

        runDrop()->expectsOutput('Nothing to clear.')->assertExitCode(0);

        expect(filesize(dropPath()))->toBe(0);
    });

    it('rebuilds a store of another schema in place, and says why it starts there', function (int $version) {
        dropPopulatedStore();
        $store = new SQLite3(dropPath());
        $store->exec("PRAGMA user_version = {$version}");
        $store->close();
        $inode = fileinode(dropPath());

        runDrop()->expectsOutputToContain('Rebuilt the store at')->assertExitCode(0);

        expect(dropRows('SELECT id FROM records'))->toBe([])
            ->and(dropRows('PRAGMA user_version'))->toBe([['user_version' => Schema::VERSION]])
            ->and(array_column(dropRows('SELECT key, value FROM meta'), 'value', 'key'))->toHaveKey('rebuilt_why', 'schema')
            ->and(fileinode(dropPath()))->toBe($inode);
    })->with([0, Schema::VERSION + 1]);

    it('moves a damaged store aside, keeping at most one earlier copy, and creates a new one', function () {
        dropPopulatedStore();
        dropCorrupt();
        $damaged = md5_file(dropPath());
        file_put_contents(dropPath().'.corrupt', 'an earlier copy');
        file_put_contents(dropPath().'-wal.corrupt', 'an earlier log');

        runDrop()->expectsOutput('The store file was damaged; moved to '.basename(dropPath()).'.corrupt and created a new store.')->assertExitCode(0);

        expect(md5_file(dropPath().'.corrupt'))->toBe($damaged)
            ->and(dropRows('SELECT id FROM records'))->toBe([])
            ->and(array_column(dropRows('SELECT key, value FROM meta'), 'value', 'key'))->toHaveKey('rebuilt_why', 'corrupt')
            ->and(filesize(dropFailuresPath()))->toBe(0);
    });

    it('leaves a file that is not a Firewatch store as it is', function (Closure $arrange) {
        mkdir(dirname(dropPath()), recursive: true);
        $arrange(dropPath());
        $before = md5_file(dropPath());

        runDrop()->expectsOutput(dropPath().' is not a Firewatch store; nothing was changed.')->assertExitCode(1);

        expect(md5_file(dropPath()))->toBe($before)
            ->and(file_exists(dropPath().'.corrupt'))->toBeFalse();
    })->with([
        'a text file' => [fn (string $path) => file_put_contents($path, 'not a database')],
        'another application\'s SQLite file' => [function (string $path) {
            $store = new SQLite3($path);
            $store->exec('CREATE TABLE orders (id INTEGER); INSERT INTO orders VALUES (1)');
            $store->close();
        }],
    ]);

    it('refuses with the reason when SQLite is too old', function () {
        dropPopulatedStore();
        app()->instance(Reader::class, new Reader(app(Configuration::class), '3.30.0'));

        runDrop()->expectsOutputToContain('SQLite 3.30.0 is older than')->assertExitCode(1);
        app()->forgetInstance(Reader::class);

        expect(dropRows('SELECT id FROM records'))->toHaveCount(2);
    });

    it('says the store is busy after its fixed wait, and rebuilds nothing', function () {
        dropPopulatedStore();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class)) extends ClearStore
        {
            protected const BUSY_TIMEOUT_MILLISECONDS = 20;
        });
        $connection = new SQLite3(dropPath());
        $connection->busyTimeout(20);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        runDrop()->expectsOutput('The store is busy; try again.')->assertExitCode(1);
        $connection->exec('ROLLBACK');

        expect(dropRows('SELECT id FROM records'))->toHaveCount(2);
    });
});

describe('the confirmation', function () {
    it('asks first, with no as the default, and rebuilds nothing when it is declined', function () {
        dropPopulatedStore();

        runDrop([])
            ->expectsConfirmation('This drops and rebuilds the store at '.dropPath().', discarding everything including diagnostics. Continue?', 'no')
            ->expectsOutput('Aborted.')
            ->assertExitCode(1);

        expect(dropRows('SELECT id FROM records'))->toHaveCount(2);
    });

    it('rebuilds once it is confirmed', function () {
        dropPopulatedStore();

        runDrop([])
            ->expectsConfirmation('This drops and rebuilds the store at '.dropPath().', discarding everything including diagnostics. Continue?', 'yes')
            ->assertExitCode(0);

        expect(dropRows('SELECT id FROM records'))->toBe([]);
    });

    it('aborts without asking when it can\'t, unless it is forced', function () {
        dropPopulatedStore();

        $this->artisan('firewatch:clear', ['--drop' => true, '--no-interaction' => true])
            ->expectsOutput('Aborted: pass --force to clear without confirmation.')
            ->assertExitCode(1);

        expect(dropRows('SELECT id FROM records'))->toHaveCount(2);
    });
});
