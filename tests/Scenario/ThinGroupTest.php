<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Route;

// A warm request's boot on the Windows runner can stall for over 300 ms, so the cold run is compared with the third-fastest run, not the second-slowest.
const COLD_RUN_MS = 400;

/**
 * Serve the cold route the given number of times, its first run deliberately slow, as a route just added is.
 */
function coldRouteTraffic(int $runs): void
{
    test()->refreshApplication();

    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    for ($run = 1; $run <= $runs; $run++) {
        // Nightwatch reads a request's start when its provider registers; the process's own start would count every earlier test into the request's duration.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/cold', function () use ($run) {
            if ($run === 1) {
                takeAtLeast(COLD_RUN_MS);
            }

            return 'ok';
        });

        test()->get('/cold');
    }
}

/**
 * @return array<string, mixed>
 */
function coldRouteRow(): array
{
    return Envelope::assert(Rank::class, ['type' => 'request'])['result']['groups'][0];
}

it('lists the runs of a route served a few times, so the cold first run reads straight off the ranking', function () {
    coldRouteTraffic(6);

    $row = coldRouteRow();
    $durations = $row['values_ms'];

    expect($row['occurrences'])->toBe(6)
        ->and($row['p95_ms'])->toBeNull()
        ->and($row['withheld']['p95_ms'])->toMatchArray(['reason' => 'sample_too_small', 'have' => 6, 'needed' => 20])
        ->and($durations)->toHaveCount(6)
        ->and($durations)->toEqual(collect($durations)->sort()->values()->all())
        ->and(end($durations))->toBeGreaterThan($durations[2] * 2);
});

it('lists the runs behind a withheld at_or_above baseline of the same route', function () {
    coldRouteTraffic(6);

    $baseline = Envelope::assert(Occurrences::class, ['group' => coldRouteRow()['group'], 'at_or_above' => 'p95'])['result']['baseline'];

    expect($baseline['threshold_ms'])->toBeNull()
        ->and($baseline['values_ms'])->toHaveCount($baseline['withheld']['have'])
        ->and(end($baseline['values_ms']))->toBeGreaterThan($baseline['values_ms'][2] * 2);
});

it('replaces the list with the p95 once the route has 20 measured runs', function () {
    coldRouteTraffic(20);

    $row = coldRouteRow();

    expect($row['occurrences'])->toBe(20)
        ->and($row['values_ms'])->toBeNull()
        ->and($row['withheld'])->toBeNull()
        ->and($row['p95_ms'])->not->toBeNull();
});
