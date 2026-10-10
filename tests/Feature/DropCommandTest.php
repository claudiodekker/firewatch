<?php

use ClaudioDekker\Firewatch\Actions\ClearStore;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;

const DROP_NOW = '2026-09-30 14:00:00';

function dropMarkers(): Markers
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection));
}

function rebuiltPattern(): string
{
    $text = __('firewatch::messages.clear.rebuilt', ['path' => dropPath(), 'before' => '@@SIZE@@', 'after' => '@@SIZE@@']);

    return '/'.str_replace('@@SIZE@@', '[\d.,]+ \w+', preg_quote($text, '/')).'/';
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
 * Fill the store with records, a user, drift, a clear marker and a dropped batch.
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

/**
 * @param  array<string, mixed>  $options
 * @return array{exit: int, output: string}
 */
function dropResult(array $options = ['--force' => true]): array
{
    $exit = Artisan::call('firewatch:clear', ['--drop' => true, ...$options]);

    return ['exit' => $exit, 'output' => trim(Artisan::output())];
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

    $result = dropResult(['--type' => 'log', '--force' => true]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe(__('firewatch::messages.clear.drop_with_type'))
        ->and(storeRows('SELECT id FROM records'))->toHaveCount(2);
});

describe('a healthy store', function () {
    it('is rebuilt in place as a new one, and says so with its size before and after', function () {
        dropPopulatedStore();
        $this->travelTo('2026-09-30 15:00:00');

        $result = dropResult();
        $markers = dropMarkers();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(rebuiltPattern())
            ->and($markers)->toEqual(new Markers(createdAt: 1790780400.0, firewatchVersion: firewatchVersion()))
            ->and(storeRows('SELECT id FROM records'))->toBe([])
            ->and(storeRows('SELECT id FROM users'))->toBe([])
            ->and(storeRows('SELECT kind FROM drift'))->toBe([])
            ->and(filesize(dropFailuresPath()))->toBe(0)
            ->and(storeRows('PRAGMA user_version'))->toBe([['user_version' => Schema::VERSION]]);
    });

    it('restarts the ids at 1, and never unlinks the file', function () {
        dropPopulatedStore();
        $inode = fileinode(dropPath());

        $result = dropResult();
        ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776100.0])]);

        expect($result['exit'])->toBe(0)
            ->and(array_column(storeRows('SELECT id FROM records'), 'id'))->toBe([1])
            ->and(fileinode(dropPath()))->toBe($inode)
            ->and(file_exists(dropPath().'.corrupt'))->toBeFalse();
    });

    it('returns every freed page to the file', function () {
        ingest(array_map(fn (int $i) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0 + $i]), range(1, 300)));
        $this->endCapture();
        $before = filesize(dropPath());

        runDrop()->run();

        expect(storeRows('PRAGMA freelist_count'))->toBe([['freelist_count' => 0]])
            ->and(filesize(dropPath()))->toBeLessThan($before);
    });

    it('says the history starts again at the rebuild', function () {
        dropPopulatedStore();
        $this->travelTo('2026-09-30 15:00:00');

        runDrop()->run();

        expect(dropMarkers()->createdAt)->toBe(1790780400.0);
    });
});

describe('a store in another state', function () {
    it('has nothing to drop when there is no store, or an empty file, and creates nothing', function () {
        $withoutStore = dropResult();
        $directoryCreated = file_exists(dirname(dropPath()));
        mkdir(dirname(dropPath()), recursive: true);
        touch(dropPath());

        $withEmptyFile = dropResult();

        expect($withoutStore['exit'])->toBe(0)
            ->and($withoutStore['output'])->toBe(__('firewatch::messages.clear.nothing'))
            ->and($directoryCreated)->toBeFalse()
            ->and($withEmptyFile['exit'])->toBe(0)
            ->and($withEmptyFile['output'])->toBe(__('firewatch::messages.clear.nothing'))
            ->and(filesize(dropPath()))->toBe(0);
    });

    it('rebuilds a store of another schema in place, and says why it starts there', function (int $version) {
        dropPopulatedStore();
        $store = new SQLite3(dropPath());
        $store->exec("PRAGMA user_version = {$version}");
        $store->close();
        $inode = fileinode(dropPath());

        $result = dropResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toMatch(rebuiltPattern())
            ->and(storeRows('SELECT id FROM records'))->toBe([])
            ->and(storeRows('PRAGMA user_version'))->toBe([['user_version' => Schema::VERSION]])
            ->and(dropMarkers()->rebuiltWhy)->toBe('schema')
            ->and(fileinode(dropPath()))->toBe($inode);
    })->with([
        'an older schema' => 0,
        'a newer schema' => Schema::VERSION + 1,
    ]);

    it('moves a damaged store aside, keeping at most one earlier copy, and creates a new one', function () {
        dropPopulatedStore();
        $this->endCapture();
        dropCorrupt();
        $damaged = md5_file(dropPath());
        file_put_contents(dropPath().'.corrupt', 'an earlier copy');
        file_put_contents(dropPath().'-wal.corrupt', 'an earlier log');

        $result = dropResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.replaced_damaged', ['file' => basename(dropPath()).'.corrupt']))
            ->and(md5_file(dropPath().'.corrupt'))->toBe($damaged)
            ->and(storeRows('SELECT id FROM records'))->toBe([])
            ->and(dropMarkers()->rebuiltWhy)->toBe('corrupt')
            ->and(filesize(dropFailuresPath()))->toBe(0);
    });

    it('moves aside a store whose header is damaged, and creates a new one', function () {
        dropPopulatedStore();
        $this->endCapture();
        $handle = fopen(dropPath(), 'r+b');
        fseek($handle, 16);
        fwrite($handle, "\x00\x07");
        fclose($handle);
        $damaged = md5_file(dropPath());

        $result = dropResult();

        expect($result['exit'])->toBe(0)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.replaced_damaged', ['file' => basename(dropPath()).'.corrupt']))
            ->and(md5_file(dropPath().'.corrupt'))->toBe($damaged)
            ->and(storeRows('SELECT id FROM records'))->toBe([])
            ->and(dropMarkers()->rebuiltWhy)->toBe('corrupt');
    });

    it('leaves a file that is not a Firewatch store as it is', function (Closure $arrange) {
        mkdir(dirname(dropPath()), recursive: true);
        $arrange(dropPath());
        $before = md5_file(dropPath());

        $result = dropResult();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.foreign', ['path' => dropPath()]))
            ->and(md5_file(dropPath()))->toBe($before)
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

        $result = dropResult();
        app()->forgetInstance(Reader::class);

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.sqlite', ['version' => '3.30.0', 'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION]))
            ->and(storeRows('SELECT id FROM records'))->toHaveCount(2);
    });

    it('says the store is busy after its fixed wait, and rebuilds nothing', function () {
        dropPopulatedStore();
        $this->endCapture();
        app()->instance(ClearStore::class, new class(app(Reader::class), app(Configuration::class)) extends ClearStore
        {
            protected const BUSY_TIMEOUT_MILLISECONDS = 20;
        });
        $connection = new SQLite3(dropPath());
        $connection->busyTimeout(20);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        $result = dropResult();
        $connection->exec('ROLLBACK');

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.busy'))
            ->and(storeRows('SELECT id FROM records'))->toHaveCount(2);
    });

    it('says the store is busy when its write-ahead log can\'t be moved aside, and moves the damaged store back', function () {
        dropPopulatedStore();
        $this->endCapture();
        $holder = new SQLite3(dropPath());
        $holder->querySingle('SELECT count(*) FROM records');
        dropCorrupt();
        $damaged = md5_file(dropPath());
        mkdir(dropPath().'-wal.corrupt');

        $result = dropResult();
        $holder->close();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.busy'))
            ->and(md5_file(dropPath()))->toBe($damaged)
            ->and(file_exists(dropPath().'.corrupt'))->toBeFalse();
    });

    it('says the store is busy while another connection holds the damaged store open, and leaves it as it is', function () {
        dropPopulatedStore();
        $this->endCapture();
        $holder = new SQLite3(dropPath());
        $holder->querySingle('SELECT count(*) FROM records');
        dropCorrupt();
        $damaged = md5_file(dropPath());

        $result = dropResult();
        $holder->close();

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.busy'))
            ->and(md5_file(dropPath()))->toBe($damaged)
            ->and(file_exists(dropPath().'.corrupt'))->toBeFalse();
    })->skip(PHP_OS_FAMILY !== 'Windows', 'only Windows refuses to rename a file another process holds open');
});

describe('the confirmation', function () {
    it('asks first, with no as the default, and rebuilds nothing when it is declined', function () {
        dropPopulatedStore();

        $command = runDrop([])
            ->expectsConfirmation(__('firewatch::messages.clear.confirm_drop', ['path' => dropPath()]), 'no')
            ->expectsOutput(__('firewatch::messages.clear.declined'));

        $exit = $command->run();

        expect($exit)->toBe(1)
            ->and(storeRows('SELECT id FROM records'))->toHaveCount(2);
    });

    it('rebuilds once it is confirmed', function () {
        dropPopulatedStore();

        $command = runDrop([])
            ->expectsConfirmation(__('firewatch::messages.clear.confirm_drop', ['path' => dropPath()]), 'yes');

        $exit = $command->run();

        expect($exit)->toBe(0)
            ->and(storeRows('SELECT id FROM records'))->toBe([]);
    });

    it('aborts without asking when it can\'t, unless it is forced', function () {
        dropPopulatedStore();

        $result = dropResult(['--no-interaction' => true]);

        expect($result['exit'])->toBe(1)
            ->and($result['output'])->toBe(__('firewatch::messages.clear.not_forced'))
            ->and(storeRows('SELECT id FROM records'))->toHaveCount(2);
    });
});
