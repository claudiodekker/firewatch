<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Jobs\ShipOrder;

/**
 * Dispatch the jobs to a queue connection and let a worker run one attempt for each run.
 *
 * @param  list<ShouldQueue>  $jobs
 */
function queueLatencyDispatched(array $jobs, int $runs, string $connection = 'database'): void
{
    test()->refreshApplication();
    test()->loadLaravelMigrations();

    config()->set('queue.default', $connection);
    config()->set('queue.failed.driver', 'null');

    foreach ($jobs as $job) {
        dispatch($job);
    }

    Nightwatch::digest();

    for ($run = 0; $run < $runs; $run++) {
        runArtisan(['command' => 'queue:work', '--once' => true]);
    }
}

it('flags a job no worker picked up as pending, and says the store cannot tell why', function () {
    queueLatencyDispatched([new ShipOrder], runs: 0);
    [$dispatch] = storeRows('SELECT execution_id, group_hash, started_at FROM queued_jobs');

    $this->travelTo(Date::createFromTimestamp($dispatch['started_at'] + 60));
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
