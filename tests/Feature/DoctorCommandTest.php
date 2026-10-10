<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Console\Doctor\InstallChecks;
use ClaudioDekker\Firewatch\Console\Doctor\StoreChecks;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreFailure;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Nightwatch\Events\IngestingEvents;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $phpThatStopsTheProbeBeforeItSpawns = $this->storeDirectory.'/no-php';

    $this->app->instance(Availability::class, new Availability(phpBinary: $phpThatStopsTheProbeBeforeItSpawns));
});

/**
 * Run the doctor with --json and get the document and the exit code.
 *
 * @return array{status: string, checks: list<array{id: string, status: string, message: string, fix: string|null}>, exit: int}
 */
function doctorRun(): array
{
    $exit = Artisan::call('firewatch:doctor', ['--json' => true]);

    return [...json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR), 'exit' => $exit];
}

/**
 * Get the results one check reported in a doctor run.
 *
 * @return list<array{id: string, status: string, message: string, fix: string|null}>
 */
function doctorResults(string $id): array
{
    return array_values(array_filter(doctorRun()['checks'], fn (array $check) => $check['id'] === $id));
}

/**
 * Get the one result a check reported in a doctor run.
 *
 * @return array{id: string, status: string, message: string, fix: string|null}
 */
function doctorCheck(string $id): array
{
    $results = doctorResults($id);

    expect($results)->toHaveCount(1);

    return $results[0];
}

/**
 * Bind the install checks with other runtime facts than this process's own.
 *
 * @param  array<string, mixed>  $facts
 */
function doctorInstall(array $facts): void
{
    app()->instance(InstallChecks::class, app()->make(InstallChecks::class, $facts));
}

/**
 * Resolve the configuration again from the given raw settings, as a fresh process would.
 *
 * @param  array<string, mixed>  $settings
 */
function doctorConfigure(array $settings): void
{
    config()->set('firewatch', $settings);

    registerFirewatch();
}

/**
 * Tell the application which Nightwatch release is installed and whether its provider registered first.
 */
function doctorNightwatch(string $version, bool $registeredFirst = false): void
{
    app()->instance(NightwatchInstall::class, new NightwatchInstall(version: $version, registeredFirst: $registeredFirst));
}

/**
 * Remove the veto Firewatch listens with as an Active process, which the doctor's own Off process never registers.
 */
function doctorWithoutVeto(): void
{
    app('events')->forget(IngestingEvents::class);
}

it('reports the nineteen checks in the order of the contract', function () {
    $ids = array_column(doctorRun()['checks'], 'id');

    expect(array_values(array_unique($ids)))->toBe([
        'mode', 'php', 'sqlite', 'nightwatch', 'nightwatch-order', 'config', 'budgets', 'store-path',
        'store-permissions', 'store-gitignore', 'store-identity', 'store-integrity', 'store-activity',
        'store-losses', 'store-drift', 'capture-posture', 'server', 'sql-access', 'client',
    ]);
});

it('fails a check that throws with its message and still runs the others', function () {
    $this->app->bind(InstallChecks::class, fn () => throw new RuntimeException('The install checks could not be built.'));

    $report = doctorRun();

    expect(doctorCheck('mode'))->toBe([
        'id' => 'mode',
        'status' => 'fail',
        'message' => 'The install checks could not be built.',
        'fix' => __('firewatch::messages.doctor.threw_fix'),
    ])
        ->and(doctorCheck('store-identity')['status'])->not->toBe('fail')
        ->and($report['status'])->toBe('fail')
        ->and($report['exit'])->toBe(1);
});

it('prints a line for each result', function () {
    $this->artisan('firewatch:doctor')
        ->expectsOutputToContain('[info] client ')
        ->assertSuccessful();
});

describe('mode', function () {
    it('reports the mode a host process runs in', function () {
        expect(doctorCheck('mode'))->toBe([
            'id' => 'mode',
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.mode.ok', ['environment' => 'testing', 'environments' => 'local, testing', 'mode' => 'active']),
            'fix' => null,
        ]);
    });

    it('warns when production is on the allowlist', function () {
        doctorConfigure(['environments' => 'local,production']);

        expect(doctorCheck('mode'))->toBe([
            'id' => 'mode',
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.mode.production', ['environments' => 'local, production', 'production' => 'production']),
            'fix' => __('firewatch::messages.doctor.mode.production_fix'),
        ]);
    });

    it('warns when Firewatch is disabled', function () {
        doctorConfigure(['enabled' => false]);

        expect(doctorCheck('mode'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.mode.disabled', [
                'environment' => 'testing',
                'environments' => 'local, testing',
            ]),
            'fix' => __('firewatch::messages.doctor.mode.disabled_fix'),
        ]);
    });

    it('warns about production and a disabled Firewatch together', function () {
        doctorConfigure(['environments' => 'testing,prod', 'enabled' => false]);

        expect(array_column(doctorResults('mode'), 'message'))->toBe([
            __('firewatch::messages.doctor.mode.production', ['environments' => 'testing, prod', 'production' => 'prod']),
            __('firewatch::messages.doctor.mode.disabled', ['environment' => 'testing', 'environments' => 'testing, prod']),
        ]);
    });
});

describe('php', function () {
    it('reports the release and the binary', function () {
        expect(doctorCheck('php'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.php.ok', ['version' => PHP_VERSION, 'binary' => PHP_BINARY]),
        ]);
    });

    it('fails below 8.3', function (string $version, string $status) {
        doctorInstall(['phpVersion' => $version]);

        expect(doctorCheck('php')['status'])->toBe($status);
    })->with([
        'a release before the floor' => ['8.2.29', 'fail'],
        'the floor itself' => ['8.3.0', 'ok'],
    ]);

    it('says what to do', function () {
        doctorInstall(['phpVersion' => '8.2.29']);

        expect(doctorCheck('php'))->toMatchArray([
            'message' => __('firewatch::messages.doctor.php.too_old', ['version' => '8.2.29', 'binary' => PHP_BINARY, 'minimum' => '8.3.0']),
            'fix' => __('firewatch::messages.doctor.php.too_old_fix', ['minimum' => '8.3.0']),
        ]);
    });
});

describe('sqlite', function () {
    it('reports the release', function () {
        doctorInstall(['sqliteVersion' => fn () => '3.50.8']);

        expect(doctorCheck('sqlite'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.sqlite.ok', ['version' => '3.50.8']),
        ]);
    });

    it('fails when the extension is missing', function () {
        doctorInstall(['sqliteVersion' => fn () => null]);

        expect(doctorCheck('sqlite'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.sqlite.missing'),
            'fix' => __('firewatch::messages.doctor.sqlite.missing_fix', ['binary' => PHP_BINARY]),
        ]);
    });

    it('fails below the floor', function (string $version, string $status) {
        doctorInstall(['sqliteVersion' => fn () => $version]);

        expect(doctorCheck('sqlite')['status'])->toBe($status);
    })->with([
        'the last release before the floor' => ['3.40.1', 'fail'],
        'the floor itself, which is in the reset range' => ['3.41.0', 'warn'],
    ]);

    it('warns in the write-ahead log reset range', function () {
        doctorInstall(['sqliteVersion' => fn () => '3.44.0']);

        expect(doctorCheck('sqlite'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.sqlite.wal_reset', ['version' => '3.44.0']),
            'fix' => __('firewatch::messages.doctor.sqlite.wal_reset_fix'),
        ]);
    });
});

describe('nightwatch', function () {
    it('accepts the verified line', function () {
        doctorNightwatch('1.30.2');

        expect(doctorCheck('nightwatch'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.nightwatch.ok', ['version' => '1.30.2', 'line' => '1.30']),
        ]);
    });

    it('warns about a release past the verified line', function () {
        doctorNightwatch('1.31.0');

        expect(doctorCheck('nightwatch'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.nightwatch.unverified', ['version' => '1.31.0', 'line' => '1.30']),
            'fix' => __('firewatch::messages.doctor.nightwatch.unverified_fix', ['line' => '1.30']),
        ]);
    });

    it('fails when the event the veto listens on is missing or has no records', function (string $event) {
        app()->instance(NightwatchInstall::class, new class('1.30.2', false, $event) extends NightwatchInstall
        {
            public function __construct(string $version, bool $registeredFirst, protected string $event)
            {
                parent::__construct($version, $registeredFirst);
            }

            public function vetoEvent(): string
            {
                return $this->event;
            }
        });

        expect(doctorCheck('nightwatch'))->toMatchArray([
            'status' => 'fail',
            'fix' => __('firewatch::messages.doctor.nightwatch.api_fix', ['line' => '1.30']),
        ]);
    })->with([
        'an event that does not exist' => ['Laravel\Nightwatch\Events\Missing'],
        'an event without records' => [stdClass::class],
    ]);
});

describe('nightwatch-order', function () {
    it('passes when Firewatch registered first and nothing else listens', function () {
        doctorWithoutVeto();

        expect(doctorCheck('nightwatch-order'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.nightwatch-order.ok'),
        ]);
    });

    it('fails when Nightwatch registered first', function () {
        doctorNightwatch('1.30.2', registeredFirst: true);

        expect(doctorCheck('nightwatch-order'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.nightwatch-order.registered_first'),
            'fix' => __('firewatch::messages.doctor.nightwatch-order.registered_first_fix'),
        ]);
    });

    it('warns about the application\'s own listeners without failing the run', function () {
        doctorWithoutVeto();
        Event::listen(IngestingEvents::class, fn () => null);

        $report = doctorRun();

        expect(doctorCheck('nightwatch-order'))->toMatchArray([
            'status' => 'warn',
            'message' => trans_choice('firewatch::messages.doctor.nightwatch-order.listeners', 1, ['count' => 1]),
            'fix' => __('firewatch::messages.doctor.nightwatch-order.listeners_fix'),
        ])
            ->and($report['status'])->toBe('warn')
            ->and($report['exit'])->toBe(0);
    });
});

describe('config', function () {
    it('passes a valid configuration', function () {
        expect(doctorResults('config'))->toBe([[
            'id' => 'config',
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.config.ok'),
            'fix' => null,
        ]]);
    });

    it('warns once for every issue', function () {
        doctorConfigure(['busy_timeout' => 'soon', 'deploy' => ['v1']]);

        $results = doctorResults('config');

        expect($results)->toHaveCount(2)
            ->and($results[0]['status'])->toBe('warn')
            ->and($results[0]['message'])->toStartWith('firewatch.busy_timeout: ')
            ->and($results[0]['fix'])->toBe(__('firewatch::messages.doctor.config.issue_fix', ['key' => 'busy_timeout']))
            ->and($results[1]['message'])->toStartWith('firewatch.deploy: ');
    });

    it('leaves the store path and the budgets to their own checks', function () {
        doctorConfigure(['database' => 12, 'budgets' => 'many']);

        expect(doctorResults('config'))->toHaveCount(1)
            ->and(doctorCheck('config')['status'])->toBe('ok');
    });
});

describe('budgets', function () {
    it('passes when none are configured', function () {
        expect(doctorCheck('budgets'))->toMatchArray([
            'status' => 'ok',
            'message' => trans_choice('firewatch::messages.doctor.budgets.ok', 0, ['count' => 0]),
        ]);
    });

    it('counts the entries', function () {
        doctorConfigure(['budgets' => [
            ['type' => 'request', 'duration' => 800],
            ['type' => 'command', 'duration' => 900],
        ]]);

        expect(doctorCheck('budgets'))->toMatchArray([
            'status' => 'ok',
            'message' => trans_choice('firewatch::messages.doctor.budgets.ok', 2, ['count' => 2]),
        ]);
    });

    it('warns for every ignored entry', function () {
        doctorConfigure(['budgets' => [['type' => 'nonsense', 'duration' => 1], ['type' => 'request', 'duration' => 'slow']]]);

        $results = doctorResults('budgets');

        expect($results)->toHaveCount(2)
            ->and($results[0]['status'])->toBe('warn')
            ->and($results[0]['message'])->toStartWith('firewatch.budgets[1]: ')
            ->and($results[0]['fix'])->toBe(__('firewatch::messages.doctor.budgets.issue_fix', ['key' => 'budgets[1]']))
            ->and($results[1]['message'])->toStartWith('firewatch.budgets[2]: ');
    });

    it('informs about a global entry that an earlier one of its type shadows', function () {
        doctorConfigure(['budgets' => [
            ['type' => 'request', 'duration' => 800],
            ['type' => 'request', 'path' => 'checkout/*', 'duration' => 100],
            ['type' => 'request', 'duration' => 900],
        ]]);

        $report = doctorRun();

        expect(doctorResults('budgets'))->toBe([[
            'id' => 'budgets',
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.budgets.shadowed', ['number' => 3]),
            'fix' => null,
        ]])
            ->and($report['exit'])->toBe(0);
    });
});

describe('store-path', function () {
    it('reports the resolved path', function () {
        expect(doctorCheck('store-path'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-path.ok', ['path' => $this->storeDirectory.'/firewatch.sqlite']),
        ]);
    });

    it('warns when the configured path was refused', function () {
        doctorConfigure(['database' => 12]);

        $check = doctorCheck('store-path');

        expect($check['status'])->toBe('warn')
            ->and($check['message'])->toStartWith('firewatch.database: 12 is not a file path; using ')
            ->and($check['fix'])->toBe(__('firewatch::messages.doctor.store-path.refused_fix'));
    });
});

describe('capture-posture', function () {
    it('informs about the posture in effect', function () {
        doctorConfigure(['capture' => ['redact_payload_fields' => ['password'], 'request_payload' => false]]);

        expect(doctorCheck('capture-posture'))->toBe([
            'id' => 'capture-posture',
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.capture-posture.posture', ['fields' => 'password', 'headers' => __('firewatch::messages.doctor.none'), 'payload' => __('firewatch::messages.doctor.off'), 'logs' => __('firewatch::messages.doctor.on')]),
            'fix' => null,
        ]);
    });

    it('warns that Nightwatch\'s defaults apply when it registered first', function () {
        doctorNightwatch('1.30.2', registeredFirst: true);

        expect(doctorCheck('capture-posture'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.capture-posture.nightwatch_defaults'),
            'fix' => __('firewatch::messages.doctor.nightwatch-order.registered_first_fix'),
        ]);
    });
});

/**
 * Store a request that finished just now, through Firewatch's real ingest.
 */
function doctorCapture(): void
{
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => Instant::now()])]);
}

/**
 * Get the path of the store, with its directory created.
 */
function doctorStorePath(): string
{
    $path = app(Configuration::class)->database;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    return $path;
}

/**
 * Create a store with one record whose every page is in the one file, and overwrite the file from an offset.
 */
function doctorDamagedStore(int $offset): void
{
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('cache-event', '{}')"));

    $path = app(Configuration::class)->database;
    clearstatcache(true, $path);
    $handle = fopen($path, 'r+b');
    fseek($handle, $offset);
    fwrite($handle, str_repeat("\xff", filesize($path) - $offset));
    fclose($handle);
}

/**
 * Bind a reader whose store is held locked by another connection, which the test releases with the returned closure.
 */
function doctorBusyStore(): Closure
{
    doctorCapture();
    test()->endCapture();

    $connection = new SQLite3(app(Configuration::class)->database);
    $connection->exec('PRAGMA journal_mode = DELETE');
    $connection->exec('BEGIN EXCLUSIVE');

    app()->instance(Reader::class, new class(app(Configuration::class)) extends Reader
    {
        protected const BUSY_TIMEOUT_MILLISECONDS = 20;
    });

    return fn () => $connection->exec('ROLLBACK');
}

describe('store-permissions', function () {
    it('passes the modes a writer creates', function () {
        doctorCapture();

        expect(doctorCheck('store-permissions'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-permissions.ok'),
        ]);
    })->group('posix');

    it('warns when the directory or the file is looser', function (string $loosen) {
        doctorCapture();
        $path = app(Configuration::class)->database;
        chmod($loosen === 'directory' ? dirname($path) : $path, $loosen === 'directory' ? 0755 : 0644);

        expect(doctorCheck('store-permissions'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.store-permissions.looser', [
                'directory_mode' => $loosen === 'directory' ? '0755' : '0700',
                'file_mode' => $loosen === 'directory' ? '0600' : '0644',
            ]),
            'fix' => __('firewatch::messages.doctor.store-permissions.looser_fix', ['directory' => dirname($path), 'path' => $path]),
        ]);
    })->with(['directory', 'file'])->group('posix');

    it('accepts a stricter mode', function () {
        doctorCapture();
        chmod(app(Configuration::class)->database, 0400);

        expect(doctorCheck('store-permissions')['status'])->toBe('ok');
    })->group('posix');

    it('informs when there is no store directory yet', function () {
        expect(doctorCheck('store-permissions'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-permissions.absent'),
        ]);
    })->group('posix');

    it('is not applicable on Windows', function () {
        doctorCapture();
        app()->instance(StoreChecks::class, app()->make(StoreChecks::class, ['osFamily' => 'Windows']));
        chmod(dirname(app(Configuration::class)->database), 0777);

        expect(doctorCheck('store-permissions'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-permissions.windows'),
        ]);
    });

    it('is not applicable on the Windows host it runs on', function () {
        doctorCapture();

        expect(doctorCheck('store-permissions'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-permissions.windows'),
            'fix' => null,
        ]);
    })->onlyOnWindows();
});

describe('store-gitignore', function () {
    it('passes a directory with its own ignore file', function () {
        doctorCapture();
        $directory = dirname(app(Configuration::class)->database);

        expect(doctorCheck('store-gitignore'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-gitignore.ok', ['directory' => $directory]),
        ]);
    });

    it('warns when the directory has none', function () {
        doctorCapture();
        $directory = dirname(app(Configuration::class)->database);
        unlink($directory.'/.gitignore');

        expect(doctorCheck('store-gitignore'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.store-gitignore.missing', ['directory' => $directory]),
            'fix' => __('firewatch::messages.doctor.store-gitignore.missing_fix', ['directory' => $directory]),
        ]);
    });

    it('informs when there is no store directory yet', function () {
        expect(doctorCheck('store-gitignore'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-gitignore.absent'),
        ]);
    });
});

describe('store-identity', function () {
    it('informs when no store was written yet', function () {
        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-identity.absent', ['path' => app(Configuration::class)->database]),
        ]);
    });

    it('passes a Firewatch store of this schema', function () {
        doctorCapture();

        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-identity.ok', ['path' => app(Configuration::class)->database, 'version' => Schema::VERSION]),
        ]);
    });

    it('fails on a file that is not a Firewatch store', function (string $contents) {
        file_put_contents(doctorStorePath(), $contents);

        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.store-identity.foreign', ['path' => app(Configuration::class)->database]),
            'fix' => __('firewatch::messages.doctor.store-identity.foreign_fix'),
        ]);
    })->with([
        'a file that is not a database' => [str_repeat('not a database ', 100)],
    ]);

    it('fails on a SQLite file of another application', function () {
        (new SQLite3(doctorStorePath()))->exec('CREATE TABLE orders (id INTEGER)');

        expect(doctorCheck('store-identity')['status'])->toBe('fail');
    });

    it('warns about a store of another schema and says whether a writer rebuilds it', function (int $version, string $case) {
        (new SQLite3(doctorStorePath()))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = '.$version.'; CREATE TABLE records (id INTEGER)');

        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'warn',
            'message' => __("firewatch::messages.doctor.store-identity.{$case}", ['found' => $version, 'expected' => Schema::VERSION]),
            'fix' => __("firewatch::messages.doctor.store-identity.{$case}_fix"),
        ]);
    })->with([
        'an older schema' => [0, 'older'],
        'a newer schema' => [2, 'newer'],
    ]);

    it('leaves the failure of a damaged store to store-integrity', function () {
        doctorDamagedStore(offset: 100);

        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-identity.damaged'),
        ]);
    });

    it('warns when the store stays busy', function () {
        $release = doctorBusyStore();

        expect(doctorCheck('store-identity'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.store.busy'),
            'fix' => __('firewatch::messages.doctor.store.busy_fix'),
        ]);

        $release();
    });
});

describe('store-integrity', function () {
    it('passes a store quick_check finds nothing in', function () {
        doctorCapture();

        expect(doctorCheck('store-integrity'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-integrity.ok'),
        ]);
    });

    it('fails a damaged store', function (int $offset) {
        doctorDamagedStore($offset);

        expect(doctorCheck('store-integrity'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.store-integrity.damaged'),
            'fix' => __('firewatch::messages.doctor.store-integrity.damaged_fix'),
        ]);
    })->with([
        'in its tables' => [4096],
        'in its first page' => [100],
    ]);

    it('fails the run for a damaged store', function () {
        doctorDamagedStore(offset: 4096);

        $report = doctorRun();

        expect($report['status'])->toBe('fail')
            ->and($report['exit'])->toBe(1);
    });

    it('informs when there is no store, for another check\'s reason and for SQLite below the floor', function (string $arrange, string $key) {
        match ($arrange) {
            'absent' => null,
            'foreign' => file_put_contents(doctorStorePath(), str_repeat('not a database ', 100)),
            'old' => app()->instance(Reader::class, new Reader(app(Configuration::class), sqliteVersion: '3.37.2')),
        };

        expect(doctorCheck('store-integrity'))->toMatchArray([
            'status' => 'info',
            'message' => __("firewatch::messages.doctor.{$key}"),
        ]);
    })->with([
        'no store' => ['absent', 'store.absent'],
        'a foreign file' => ['foreign', 'store.see_identity'],
        'SQLite below the floor' => ['old', 'store.unavailable'],
    ]);
});

describe('store-activity', function () {
    it('informs about an empty store', function () {
        doctorCapture();
        Artisan::call('firewatch:clear', ['--force' => true]);

        expect(doctorCheck('store-activity'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store-activity.empty', [
                'age' => '36500d',
                'limit' => '100,000',
                'busy_timeout' => 300,
            ]),
        ]);
    });

    it('informs when there is no store', function () {
        expect(doctorCheck('store-activity'))->toMatchArray([
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.store.absent'),
        ]);
    });

    it('reports what the store holds', function () {
        doctorCapture();

        $check = doctorCheck('store-activity');

        expect($check['status'])->toBe('ok')
            ->and($check['message'])->toStartWith(trans_choice('firewatch::messages.doctor.store-activity.records', 1, ['count' => 1]).' from ')
            ->and($check['message'])->toContain('36500d')->toContain('100,000')
            ->and($check['message'])->toMatch('/ to \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC, /')
            ->and($check['fix'])->toBeNull();
    });

    it('names where a type\'s history starts after a clear', function () {
        doctorCapture();
        Artisan::call('firewatch:clear', ['--force' => true]);
        doctorCapture();

        expect(doctorCheck('store-activity')['message'])->toContain('request')->toContain('cleared');
    });

    it('names no history start on a store that was never cleared or pruned', function () {
        doctorCapture();

        expect(doctorCheck('store-activity')['message'])->not->toContain('complete from');
    });

    it('warns when nothing was captured for a day', function (int $hours, string $status) {
        doctorCapture();
        $this->travelTo(now()->addHours($hours));

        expect(doctorCheck('store-activity')['status'])->toBe($status);
    })->with([
        'just under a day' => [23, 'ok'],
        'past a day' => [25, 'warn'],
    ]);

    it('states the busy timeout in effect', function () {
        doctorConfigure(['busy_timeout' => 750]);
        doctorCapture();

        expect(doctorCheck('store-activity')['message'])->toContain(' 750 ');
    });

    it('keeps the retention facts on a quiet store', function () {
        doctorCapture();
        $this->travelTo(now()->addHours(25));

        expect(doctorCheck('store-activity')['message'])->toContain('36500d')->toContain('100,000')->toContain(' 300 ');
    });

    it('says what to do about a quiet store', function () {
        doctorCapture();
        $this->travelTo(now()->addHours(25));

        expect(doctorCheck('store-activity')['fix'])->toBe(__('firewatch::messages.doctor.store-activity.quiet_fix'));
    });
});

describe('store-losses', function () {
    it('passes when no batch was dropped', function () {
        expect(doctorCheck('store-losses'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-losses.ok'),
        ]);
    });

    it('warns about the batches dropped and quotes the newest', function () {
        doctorStorePath();
        app(FailureLog::class)->record(new StoreFailure(FailureKind::FULL, 'database or disk is full'), dropped: 2);
        app(FailureLog::class)->record(new StoreFailure(FailureKind::BUSY, 'database is locked'), dropped: 3);

        $check = doctorCheck('store-losses');

        expect($check['status'])->toBe('warn')
            ->and($check['message'])->toStartWith('2 batches dropped, holding 5 records in all; the newest at ')
            ->and($check['message'])->toEndWith('(busy): database is locked')
            ->and($check['fix'])->toBe(__('firewatch::messages.doctor.store-losses.busy_fix', ['milliseconds' => 300]));
    });

    it('says what to do for each kind of loss', function (FailureKind $kind, string $fix) {
        doctorStorePath();
        app(FailureLog::class)->record(new StoreFailure($kind, 'it failed'), dropped: 1);

        expect(doctorCheck('store-losses')['fix'])->toBe(__("firewatch::messages.doctor.store-losses.{$fix}"));
    })->with([
        'a full disk' => [FailureKind::FULL, 'full_fix'],
        'another failure' => [FailureKind::IO, 'other_fix'],
    ]);

    it('does not count a damaged store that was replaced with nothing dropped', function () {
        doctorStorePath();
        app(FailureLog::class)->recovered(new RuntimeException('file is not a database'));

        expect(doctorCheck('store-losses')['status'])->toBe('ok');
    });
});

describe('store-drift', function () {
    it('passes a store without drift', function () {
        doctorCapture();

        expect(doctorCheck('store-drift'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.store-drift.ok'),
        ]);
    });

    it('warns about each kind of drift', function () {
        ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['t' => 'future-type'])]);

        expect(doctorCheck('store-drift'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.store-drift.found', ['rows' => 'unknown_type future-type (1)']),
            'fix' => __('firewatch::messages.doctor.store-drift.found_fix', ['line' => '1.30']),
        ]);
    });

    it('informs when there is no store', function () {
        expect(doctorCheck('store-drift')['message'])->toBe(__('firewatch::messages.doctor.store.absent'));
    });
});

describe('server', function () {
    it('boots and lists its tools', function () {
        $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();

        expect(doctorCheck('server'))->toBe([
            'id' => 'server',
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.server.ok', ['version' => $listing['server']['version'], 'count' => 12]),
            'fix' => null,
        ]);
    });

    it('fails with the message of a server that cannot be built', function () {
        $this->app->bind(FirewatchServer::class, fn () => throw new RuntimeException('The server could not boot.'));

        expect(doctorCheck('server'))->toBe([
            'id' => 'server',
            'status' => 'fail',
            'message' => 'The server could not boot.',
            'fix' => __('firewatch::messages.doctor.threw_fix'),
        ]);
    });

    it('fails a server that lists no tools', function () {
        $this->app->bind(FirewatchServer::class, fn ($app, array $parameters) => new class($parameters['transport']) extends FirewatchServer
        {
            protected array $tools = [];
        });

        expect(doctorCheck('server'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.server.tools'),
            'fix' => __('firewatch::messages.doctor.server.fix'),
        ]);
    });

    it('fails a server without instructions', function () {
        app('translator')->addLines(['messages.instructions' => ''], app()->getLocale(), 'firewatch');

        expect(doctorCheck('server'))->toMatchArray([
            'status' => 'fail',
            'message' => __('firewatch::messages.doctor.server.instructions'),
        ]);
    });
});

describe('sql-access', function () {
    it('warns with the reason the SQL tool cannot run', function () {
        expect(doctorCheck('sql-access'))->toBe([
            'id' => 'sql-access',
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.php_binary')]),
            'fix' => __('firewatch::messages.doctor.sql-access.php_binary_fix'),
        ]);
    });

    it('has a fix for every reason the SQL tool can be unavailable', function (Unavailable $reason) {
        expect(Lang::has("firewatch::messages.doctor.sql-access.{$reason->value}_fix"))->toBeTrue();
    })->with(Unavailable::cases());

    it('passes when the child starts', function () {
        $this->app->instance(Availability::class, new Availability);

        expect(doctorCheck('sql-access'))->toMatchArray([
            'status' => 'ok',
            'message' => __('firewatch::messages.doctor.sql-access.ok'),
        ]);
    })->group('process');

    it('names the reason the child reports', function () {
        $this->app->instance(Availability::class, new Availability);
        $this->app->instance(ChildRunner::class, new ChildRunner(app(Configuration::class), script: dirname(__DIR__).'/Fixtures/Sql/unavailable.php'));

        expect(doctorCheck('sql-access'))->toMatchArray([
            'status' => 'warn',
            'message' => __('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.heap_limit')]),
            'fix' => __('firewatch::messages.doctor.sql-access.heap_limit_fix'),
        ]);
    })->group('process');
});

describe('client', function () {
    it('prints the absolute launch command', function () {
        expect(doctorCheck('client'))->toBe([
            'id' => 'client',
            'status' => 'info',
            'message' => __('firewatch::messages.doctor.client.launch', ['command' => PHP_BINARY.' '.base_path('artisan').' firewatch:server']),
            'fix' => null,
        ]);
    });
});

describe('the report', function () {
    it('prints a line for each result and the fix beneath a warning', function () {
        doctorInstall(['sqliteVersion' => fn () => '3.44.0']);

        $this->artisan('firewatch:doctor')
            ->expectsOutputToContain('[warn] sqlite '.__('firewatch::messages.doctor.sqlite.wal_reset', ['version' => '3.44.0']))
            ->expectsOutputToContain('  fix: '.__('firewatch::messages.doctor.sqlite.wal_reset_fix'))
            ->expectsOutputToContain('[info] client ')
            ->assertSuccessful();
    });

    it('is ok when every check is ok or informs, and the command succeeds', function () {
        doctorNightwatch('1.30.2');
        doctorInstall(['sqliteVersion' => fn () => '3.50.8']);
        doctorWithoutVeto();
        $this->app->instance(Availability::class, new Availability);

        $report = doctorRun();

        expect($report['status'])->toBe('ok')
            ->and($report['exit'])->toBe(0);
    })->group('process');

    it('is a warning when a check warns, and the command still succeeds', function () {
        $report = doctorRun();

        expect($report['status'])->toBe('warn')
            ->and($report['exit'])->toBe(0);
    });

    it('is a failure when a check fails even beside warnings, and the command fails', function () {
        doctorInstall(['phpVersion' => '8.2.29']);

        $report = doctorRun();

        expect(array_column($report['checks'], 'status'))->toContain('warn', 'fail')
            ->and($report['status'])->toBe('fail')
            ->and($report['exit'])->toBe(1);
    });

    it('prints the whole document with --json', function () {
        doctorNightwatch('1.30.2');
        doctorInstall(['sqliteVersion' => fn () => '3.50.8']);
        doctorWithoutVeto();
        $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
        $directory = $this->storeDirectory;

        $report = doctorRun();

        expect($report)->toBe([
            'status' => 'warn',
            'checks' => [
                ['id' => 'mode', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.mode.ok', ['environment' => 'testing', 'environments' => 'local, testing', 'mode' => 'active']), 'fix' => null],
                ['id' => 'php', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.php.ok', ['version' => PHP_VERSION, 'binary' => PHP_BINARY]), 'fix' => null],
                ['id' => 'sqlite', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.sqlite.ok', ['version' => '3.50.8']), 'fix' => null],
                ['id' => 'nightwatch', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.nightwatch.ok', ['version' => '1.30.2', 'line' => '1.30']), 'fix' => null],
                ['id' => 'nightwatch-order', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.nightwatch-order.ok'), 'fix' => null],
                ['id' => 'config', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.config.ok'), 'fix' => null],
                ['id' => 'budgets', 'status' => 'ok', 'message' => trans_choice('firewatch::messages.doctor.budgets.ok', 0, ['count' => 0]), 'fix' => null],
                ['id' => 'store-path', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.store-path.ok', ['path' => $directory.'/firewatch.sqlite']), 'fix' => null],
                ['id' => 'store-permissions', 'status' => 'info', 'message' => __(PHP_OS_FAMILY === 'Windows' ? 'firewatch::messages.doctor.store-permissions.windows' : 'firewatch::messages.doctor.store-permissions.absent'), 'fix' => null],
                ['id' => 'store-gitignore', 'status' => 'info', 'message' => __('firewatch::messages.doctor.store-gitignore.absent'), 'fix' => null],
                ['id' => 'store-identity', 'status' => 'info', 'message' => __('firewatch::messages.doctor.store-identity.absent', ['path' => $directory.'/firewatch.sqlite']), 'fix' => null],
                ['id' => 'store-integrity', 'status' => 'info', 'message' => __('firewatch::messages.doctor.store.absent'), 'fix' => null],
                ['id' => 'store-activity', 'status' => 'info', 'message' => __('firewatch::messages.doctor.store.absent'), 'fix' => null],
                ['id' => 'store-losses', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.store-losses.ok'), 'fix' => null],
                ['id' => 'store-drift', 'status' => 'info', 'message' => __('firewatch::messages.doctor.store.absent'), 'fix' => null],
                ['id' => 'capture-posture', 'status' => 'info', 'message' => __('firewatch::messages.doctor.capture-posture.posture', ['fields' => __('firewatch::messages.doctor.none'), 'headers' => __('firewatch::messages.doctor.none'), 'payload' => __('firewatch::messages.doctor.on'), 'logs' => __('firewatch::messages.doctor.on')]), 'fix' => null],
                ['id' => 'server', 'status' => 'ok', 'message' => __('firewatch::messages.doctor.server.ok', ['version' => $listing['server']['version'], 'count' => 12]), 'fix' => null],
                ['id' => 'sql-access', 'status' => 'warn', 'message' => __('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.php_binary')]), 'fix' => __('firewatch::messages.doctor.sql-access.php_binary_fix')],
                ['id' => 'client', 'status' => 'info', 'message' => __('firewatch::messages.doctor.client.launch', ['command' => PHP_BINARY.' '.base_path('artisan').' firewatch:server']), 'fix' => null],
            ],
            'exit' => 0,
        ]);
    });
});

it('runs as an Off process in a real application and creates nothing', function () {
    $packagesCache = 'bootstrap/cache/firewatch-packages-'.bin2hex(random_bytes(8)).'.php';
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'firewatch:doctor', '--json'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite', 'APP_PACKAGES_CACHE' => $packagesCache],
        timeout: 30,
    );

    $process->run();
    (new Filesystem)->delete(base_path($packagesCache));

    $report = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
    $results = array_column($report['checks'], null, 'id');

    expect($process->getExitCode())->toBe($report['status'] === 'fail' ? 1 : 0)
        ->and($results['mode']['status'])->toBe('ok')
        ->and($results['store-identity']['status'])->toBe('info')
        ->and($this->storeDirectory)->not->toBeDirectory();
})->group('process');

describe('reading', function () {
    it('creates no store, directory or lock on an empty checkout', function () {
        $directory = dirname(app(Configuration::class)->database);

        doctorRun();

        expect($directory)->not->toBeDirectory();
    });

    it('changes nothing beside a file that is not a store', function () {
        $path = doctorStorePath();
        file_put_contents($path, str_repeat('not a database ', 100));
        $before = [md5_file($path), scandir(dirname($path))];

        doctorRun();

        expect([md5_file($path), scandir(dirname($path))])->toBe($before);
    });

    it('warns without failing the command', function () {
        doctorStorePath();
        app(FailureLog::class)->record(new StoreFailure(FailureKind::IO, 'it failed'), dropped: 1);

        $report = doctorRun();

        expect($report['status'])->toBe('warn')
            ->and($report['exit'])->toBe(0);
    });
});
