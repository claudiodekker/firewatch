<?php

use ClaudioDekker\Firewatch\Console\Doctor\InstallChecks;
use ClaudioDekker\Firewatch\Sql\Availability;
use Illuminate\Support\Facades\Artisan;

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
