<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/**
 * Let the scheduler run a task and skip another, then serve the slow and the quick route once each, then configure the budgets the answers judge them against.
 *
 * The slow route sleeps past its 1 ms ceiling and the quick one stays far under its ceiling, so the verdicts do not depend on how fast the machine is.
 */
function overBudgetCell(string $state, string $details, int $ignored = 0): string
{
    return __('firewatch::messages.budget_cell', [
        'state' => $state,
        'details' => $details.($ignored > 0 ? __('firewatch::messages.budget_ignored', ['count' => $ignored]) : ''),
    ]);
}

function overBudgetTraffic(): void
{
    test()->refreshApplication();

    $schedule = app(Schedule::class);
    $schedule->call(fn () => null)->name('send-digest')->everyMinute();
    $schedule->call(fn () => null)->name('sync-stock')->everyMinute()->skip(true);

    runArtisan(['command' => 'schedule:run']);

    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach (['/slow', '/quick'] as $uri) {
        // Nightwatch reads a request's start when its provider registers; the process's own start would count every earlier test into the request's duration.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/slow', function () {
            usleep(30_000);

            return 'ok';
        });
        Route::get('/quick', fn () => 'ok');

        test()->get($uri);
    }

    config()->set('firewatch.budgets', [
        ['type' => 'request', 'path' => 'slow', 'duration' => 1],
        ['type' => 'request', 'path' => 'quick', 'duration' => 60_000],
        ['type' => 'scheduled-task', 'duration' => 60_000, 'memory' => 4096],
        ['type' => 'bogus'],
    ]);
    registerFirewatch();
}

it('judges the groups of a ranking against their budgets, from the real sensors', function () {
    overBudgetTraffic();

    $verdicts = collect(Envelope::assert(Rank::class, ['type' => 'request'])['result']['groups'])->mapWithKeys(fn (array $row) => [$row['label'] => $row['budget']]);

    expect($verdicts['/slow'])->toBe(overBudgetCell('exceeded', 'max', 1))
        ->and($verdicts['/quick'])->toBe(overBudgetCell('within', 'max', 1));
});

it('does not judge a task that was skipped, and judges the one that ran', function () {
    overBudgetTraffic();

    $verdicts = collect(Envelope::assert(Rank::class, ['type' => 'scheduled-task'])['result']['groups'])->mapWithKeys(fn (array $row) => [$row['label'] => $row['budget']]);

    expect($verdicts['send-digest'])->toBe(overBudgetCell('within', 'max', 1))
        ->and($verdicts['sync-stock'])->toBe(overBudgetCell('not_evaluated', 'not_run', 1));
});

it('lists the exceeded groups in the overview and counts the rest', function () {
    overBudgetTraffic();

    $envelope = Envelope::assert(Overview::class);
    $rows = $envelope['result']['budgets_exceeded'];

    expect($envelope['result']['budgets'])->toMatchArray(['exceeded' => 1, 'ignored_entries' => 1])
        ->and($envelope['result']['budgets']['within'])->toBeGreaterThanOrEqual(2)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['type' => 'request', 'label' => '/slow', 'measure' => 'duration', 'ceiling' => 1, 'measured_on' => 'max'])
        ->and($envelope['summary'])->toContain(trans_choice('firewatch::messages.overview_budgets_over', 1, ['count' => 1]));
});

it('opens the group an overview row points at, and finds it exceeded', function () {
    overBudgetTraffic();

    $row = Envelope::assert(Overview::class)['result']['budgets_exceeded'][0];

    expect($row['next'])->toBe('rank(group: "'.$row['group'].'")');

    $deploys = Envelope::assert(Rank::class, ['group' => $row['group']])['result']['deploys'];

    expect($deploys[0]['budget'])->toBe(overBudgetCell('exceeded', 'max', 1));
});
