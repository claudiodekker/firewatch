<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/**
 * Let the scheduler run a task, then serve the slow route three times and the quick route once.
 *
 * The slow route sleeps 30 ms in its action, which dwarfs every other stage however fast the machine is.
 */
function slowRouteTraffic(): void
{
    test()->refreshApplication();
    app(Schedule::class)->call(fn () => null)->name('send-digest')->everyMinute();
    runArtisan(['command' => 'schedule:run']);

    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach (['/slow', '/slow', '/slow', '/quick'] as $uri) {
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
}

/**
 * Rank the routes by their 95th percentile, as an assistant asked why a route is slow starts.
 *
 * @return array<string, mixed>
 */
function slowRouteRanked(): array
{
    return Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'p95_duration']);
}

/**
 * Break the worst route down by deploy and stage, through the call the ranking offers.
 *
 * @param  array<string, mixed>  $ranked
 * @return array<string, mixed>
 */
function slowRouteStages(array $ranked): array
{
    return Envelope::follow($ranked['next'][0]);
}

/**
 * Get the stage rows of a stage view by stage.
 *
 * @param  array<string, mixed>  $result
 * @return array<string, array<string, mixed>>
 */
function slowRouteStageRows(array $result): array
{
    return collect($result['stages'])->keyBy('stage')->all();
}

it('ranks the slow route worst', function () {
    slowRouteTraffic();

    $ranked = slowRouteRanked();

    expect(array_column($ranked['result']['groups'], 'label'))->toBe(['/slow', '/quick'])
        ->and($ranked['result']['groups'][0]['occurrences'])->toBe(3);
});

it('finds the time of the slow route in its action, with means that sum to its average', function () {
    slowRouteTraffic();

    $ranked = slowRouteRanked();
    $stages = slowRouteStages($ranked);
    $result = $stages['result'];
    $rows = slowRouteStageRows($result);
    $shares = array_column($result['stages'], 'share_pct');

    expect($result['dominant_stage'])->toBe('action')
        ->and(array_keys($rows))->toBe(['bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating'])
        ->and($rows['action']['mean_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and($rows['action']['share_pct'])->toBe(max($shares))
        ->and($result['stage_executions'])->toBe(3)
        ->and($result['stage_avg_ms'])->toBe($ranked['result']['groups'][0]['avg_ms'])
        ->and(array_sum(array_column($result['stages'], 'mean_ms')))->toEqualWithDelta($result['stage_avg_ms'], 0.035)
        ->and(array_sum($shares))->toEqualWithDelta(100.0, 0.35)
        ->and($stages['notes'])->toBe([]);
});

it('shows the stages of the slowest execution as the execution answer does', function () {
    slowRouteTraffic();

    $result = slowRouteStages(slowRouteRanked())['result'];
    $header = Envelope::assert(Execution::class, ['execution_id' => $result['slowest_execution_id']])['result']['header'];

    expect($header['stages'])->toEqual(array_column($result['stages'], 'slowest_ms', 'stage'))
        ->and($header['duration_ms'])->toEqual($result['slowest_duration_ms']);
});

it('gives no stages for a scheduled task, which records none', function () {
    slowRouteTraffic();

    $group = Envelope::assert(Rank::class, ['type' => 'scheduled-task'])['result']['groups'][0]['group'];
    $result = Envelope::assert(Rank::class, ['group' => $group])['result'];

    expect($result['dominant_stage'])->toBeNull()
        ->and($result['stage_executions'])->toBeNull()
        ->and($result['stages'])->toBeNull();
});

it('states that Octane zeroes the bootstrap, and adds no note while the bootstrap took time', function () {
    slowRouteTraffic();

    $stages = slowRouteStages(slowRouteRanked());

    expect(array_column($stages['blind_spots'], 'id'))->toContain('octane-bootstrap')
        ->and(slowRouteStageRows($stages['result'])['bootstrap']['mean_ms'])->toBeGreaterThan(0.0)
        ->and($stages['notes'])->not->toContain(__('firewatch::messages.rank_stages_bootstrap_zero'));
});
