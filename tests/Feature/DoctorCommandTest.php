<?php

use ClaudioDekker\Firewatch\Console\Doctor\InstallChecks;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Sql\Availability;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Nightwatch\Events\IngestingEvents;

// Outside the sql-access tests the probe is cut short before it spawns, so only tests tagged `process` start a process.
beforeEach(function () {
    $this->app->instance(Availability::class, new Availability(phpBinary: $this->storeDirectory.'/no-php'));
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
            'message' => __('firewatch::messages.doctor.mode.disabled', ['environment' => 'testing']),
            'fix' => __('firewatch::messages.doctor.mode.disabled_fix'),
        ]);
    });

    it('puts production before a disabled Firewatch', function () {
        doctorConfigure(['environments' => 'prod', 'enabled' => false]);

        expect(doctorCheck('mode')['message'])->toBe(__('firewatch::messages.doctor.mode.production', ['environments' => 'prod', 'production' => 'prod']));
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
        'a release before the floor' => ['3.37.2', 'fail'],
        'the floor itself, which is in the reset range' => ['3.38.0', 'warn'],
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
