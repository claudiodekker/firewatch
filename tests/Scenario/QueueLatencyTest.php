<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Jobs\ShipOrder;

/**
 * Let a request queue a shipment on the connection, which no worker picks up.
 *
 * The application the request forces is a fresh one, with an empty database.
 */
function queueLatencyShipment(string $connection = 'database'): void
{
    forceRequests();
    test()->loadLaravelMigrations();

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('queue.default', $connection);

    Route::get('/ship', function () {
        dispatch(new ShipOrder);

        return 'ok';
    });

    test()->get('/ship');
    Nightwatch::digest();
}

/**
 * Queue a shipment and let a worker run it at once.
 *
 * The worker is the first command of the process, so that its run is recorded as a job attempt.
 */
function queueLatencyWorkedShipment(): void
{
    config()->set('queue.default', 'database');

    dispatch(new ShipOrder);
    Nightwatch::digest();

    runArtisan(['command' => 'queue:work', '--once' => true]);
}

/**
 * Move the store clock to a minute after the shipment was queued.
 */
function queueLatencyAMinuteLater(): void
{
    [$dispatch] = storeRows('SELECT started_at FROM queued_jobs');

    test()->travelTo(Date::createFromTimestamp($dispatch['started_at'] + 60));
}

it('flags a job no worker picked up as pending, and says the store cannot tell why', function () {
    queueLatencyShipment();
    queueLatencyAMinuteLater();
    [$dispatch] = storeRows('SELECT execution_id, group_hash FROM queued_jobs');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency']);
    $finding = $envelope['result']['findings'][0];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0])
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait'), __('firewatch::messages.detect_caveat_pending')])
        ->and($finding)->toMatchArray(['group' => $dispatch['group_hash'], 'name' => ShipOrder::class, 'latest_execution_id' => $dispatch['execution_id']])
        ->and($finding['count'])->toBeGreaterThanOrEqual(5000)
        ->and($finding['evidence'])->toMatchArray(['jobs' => 1, 'waits_over_threshold' => 0, 'pending' => 1, 'worst_wait_ms' => null, 'median_wait_ms' => null, 'queues' => ['default'], 'connections' => ['database']])
        ->and($finding['evidence']['oldest_pending_age_ms'])->toBe($finding['count']);
});

it('finds nothing in a job a worker picked up at once, on a queue whose jobs do not fail either', function () {
    queueLatencyWorkedShipment();
    queueLatencyAMinuteLater();

    $latency = Envelope::assert(Detect::class, ['shape' => 'queue-latency']);
    $failing = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);

    expect($latency['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0, 'findings' => []])
        ->and($latency['result']['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0])
        ->and($latency['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')])
        ->and($failing['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
});

it('flags the job a worker picked up at once when a millisecond is too long, by its wait', function () {
    queueLatencyWorkedShipment();
    [$attempt] = storeRows('SELECT execution_id FROM job_attempts');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency', 'threshold' => 1]);
    $finding = $envelope['result']['findings'][0];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')])
        ->and($finding['latest_execution_id'])->toBe($attempt['execution_id'])
        ->and($finding['evidence'])->toMatchArray(['jobs' => 1, 'waits_over_threshold' => 1, 'pending' => 0, 'oldest_pending_age_ms' => null])
        ->and($finding['evidence']['worst_wait_ms'])->toBe($finding['count']);
});

it('has nothing to examine for a job on the sync connection, whose dispatch the sensors do not record', function () {
    queueLatencyShipment('sync');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency']);

    expect(storeRows('SELECT id FROM queued_jobs'))->toBe([])
        ->and($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
        ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded');
});

it('is not evaluated for a job whose dispatch was not recorded, and counts the attempt it cannot place', function () {
    Nightwatch::rejectQueuedJobs(fn () => true);
    queueLatencyWorkedShipment();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'prerequisite_missing', 'examined' => 0, 'total' => 0])
        ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 1, 'without_first_attempt' => 0])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('uninstrumented-dispatcher');
});

it('states what it cannot see of queued jobs, on the real dispatch', function () {
    queueLatencyWorkedShipment();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($blindSpots)->toHaveKeys(['sync-jobs-unrecorded', 'uninstrumented-dispatcher'])
        ->and($blindSpots['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
        ->and($blindSpots['uninstrumented-dispatcher'])->toBe(__('firewatch::messages.blind_spots.uninstrumented-dispatcher'));
});

it('opens what a finding points at', function (Closure $shipment, array $arguments) {
    $shipment();
    queueLatencyAMinuteLater();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'queue-latency', ...$arguments]);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank']);

    foreach ($envelope['next'] as $call) {
        $tool = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

        expect(Envelope::assert($tool, $call['arguments'])['empty'])->toBeNull();
    }
})->with([
    'the request that queued a pending job' => [fn () => queueLatencyShipment(), []],
    'the first attempt of a job that waited' => [fn () => queueLatencyWorkedShipment(), ['threshold' => 1]],
]);

it('puts the verdict in the overview next to the other shapes', function () {
    queueLatencyShipment();
    queueLatencyAMinuteLater();

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'queue-latency');

    expect($row)->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 1, 'total' => 1])
        ->and($row['worst']['name'])->toBe(ShipOrder::class);
});
