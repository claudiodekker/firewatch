<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

// A cold application boot on the Windows runner can take more than 30 ms, and the slow route must still dominate it.
const SLOW_ROUTE_MS = 150;

/**
 * Let the scheduler run a task, then serve the given URIs, by default the slow route three times and the quick route once.
 *
 * The slow route takes SLOW_ROUTE_MS in its action.
 *
 * @param  list<string>|null  $uris
 */
function slowRouteTraffic(?array $uris = null): void
{
    test()->refreshApplication();
    app(Schedule::class)->call(fn () => null)->name('send-digest')->everyMinute();
    runArtisan(['command' => 'schedule:run']);

    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach ($uris ?? ['/slow', '/slow', '/slow', '/quick'] as $uri) {
        // Nightwatch reads a request's start when its provider registers; the process's own start would count every earlier test into the request's duration.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/slow', function () {
            takeAtLeast(SLOW_ROUTE_MS);

            return request()->has('fail') ? abort(500) : 'ok';
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
        ->and($rows['action']['mean_ms'])->toBeGreaterThanOrEqual((float) SLOW_ROUTE_MS)
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

it('walks from the ranking by p95, through the occurrences at or above it and one execution, to the dominant stage of its group', function () {
    slowRouteTraffic([...array_fill(0, 19, '/slow'), '/slow?fail=1', ...array_fill(0, 5, '/quick')]);

    $ranked = slowRouteRanked();
    [$slow, $quick] = $ranked['result']['groups'];

    expect($ranked['result']['by'])->toBe('p95_duration')
        ->and($slow)->toMatchArray(['label' => '/slow', 'occurrences' => 20, 'failure_pct' => 5, 'withheld' => null])
        ->and($slow['p95_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and($quick)->toMatchArray(['label' => '/quick', 'failure_pct' => 0, 'p95_ms' => null])
        ->and($quick['withheld']['p95_ms'])->toMatchArray(['reason' => 'sample_too_small', 'have' => 5, 'needed' => 20]);

    $tail = Envelope::assert(Occurrences::class, ['group' => $slow['group'], 'at_or_above' => 'p95']);
    $rows = $tail['result']['rows'];

    expect($tail['result']['baseline'])->toMatchArray(['percentile' => 'p95', 'samples' => 20, 'withheld' => null])
        ->and($tail['result']['baseline']['threshold_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and(count($rows))->toBeGreaterThanOrEqual(1)->toBeLessThan(20)
        ->and(array_column($rows, 'group'))->each->toBe($slow['group'])
        ->and(array_column($rows, 'duration_ms'))->each->toBeGreaterThanOrEqual(round($tail['result']['baseline']['threshold_ms'], 2))
        ->and(array_column($rows, 'execution_id'))->toContain($slow['slowest_execution_id']);

    $opened = Envelope::assert(Execution::class, ['execution_id' => $rows[0]['execution_id']]);
    $header = $opened['result']['header'];
    $slowestStage = array_search(max($header['stages']), $header['stages'], true);

    expect($header)->toMatchArray(['type' => 'request', 'label' => '/slow', 'group' => $slow['group']])
        ->and($slowestStage)->toBe('action')
        ->and($header['stages']['action'])->toBeGreaterThanOrEqual(30.0)
        ->and(array_column($opened['next'], 'tool'))->toBe(['rank', 'trace']);

    $stages = Envelope::follow($opened['next'][0])['result'];

    expect($stages['group'])->toBe($slow['group'])
        ->and($stages['dominant_stage'])->toBe($slowestStage)
        ->and($stages['slowest_execution_id'])->toBe($slow['slowest_execution_id'])
        ->and($stages['deploys'][0]['p95_ms'])->toBe($slow['p95_ms']);
});
