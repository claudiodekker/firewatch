<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\ShipOrder;
use Workbench\App\Jobs\SyncInventory;

/**
 * Dispatch the jobs to the database queue and let a worker run one attempt for each run.
 *
 * The application is a fresh one, so the exceptions its jobs throw reach the real handler, and with it the sensor. It keeps no failed jobs, as it has no table for them.
 *
 * @param  list<ShouldQueue>  $jobs
 */
function failingJobsWorked(array $jobs, int $runs): void
{
    test()->refreshApplication();
    test()->loadLaravelMigrations();

    config()->set('queue.default', 'database');
    config()->set('queue.failed.driver', 'null');

    foreach ($jobs as $job) {
        dispatch($job);
    }

    Nightwatch::digest();

    foreach (range(1, $runs) as $run) {
        runArtisan(['command' => 'queue:work', '--once' => true]);
    }
}

it('flags the job that failed for good before the one that recovered, and not the one that worked', function () {
    failingJobsWorked([new ChargeCard, new SyncInventory, new ShipOrder], runs: 5);
    [$failed] = storeRows("SELECT execution_id FROM job_attempts WHERE status = 'failed'");

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);
    $findings = $envelope['result']['findings'];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 5, 'total' => 2])
        ->and(array_column($findings, 'name'))->toBe([ChargeCard::class, SyncInventory::class])
        ->and($findings[0])->toMatchArray(['count' => 2, 'latest_execution_id' => $failed['execution_id']])
        ->and($findings[0]['evidence'])->toMatchArray(['failed_attempts' => 1, 'retried_attempts' => 1, 'jobs_failed' => 1, 'jobs_recovered' => 0, 'jobs_retrying' => 0, 'jobs' => 1, 'max_attempt' => 2])
        ->and($findings[0]['evidence']['last_exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'The card was declined.'])
        ->and($findings[0]['evidence']['last_exception']['location'])->toContain('ChargeCard.php:')
        ->and($findings[1]['count'])->toBe(1)
        ->and($findings[1]['evidence'])->toMatchArray(['failed_attempts' => 0, 'retried_attempts' => 1, 'jobs_failed' => 0, 'jobs_recovered' => 1, 'jobs_retrying' => 0, 'jobs' => 1, 'max_attempt' => 2])
        ->and($findings[1]['evidence']['last_exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'The warehouse timed out.']);
});

it('says a job is still retrying while its last attempt is a release', function () {
    failingJobsWorked([new ChargeCard], runs: 1);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['failed_attempts' => 0, 'retried_attempts' => 1, 'jobs_failed' => 0, 'jobs_recovered' => 0, 'jobs_retrying' => 1, 'jobs' => 1, 'max_attempt' => 1]);
});

it('finds nothing in a job that worked, and says it looked at its attempt', function () {
    failingJobsWorked([new ShipOrder], runs: 1);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0, 'findings' => []]);
});

it('states what it cannot see of queued jobs, on the real attempts', function () {
    failingJobsWorked([new ChargeCard], runs: 2);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($envelope['result']['caveats'])->toBe([])
        ->and($blindSpots)->toHaveKeys(['sync-jobs-unrecorded', 'uninstrumented-dispatcher'])
        ->and($blindSpots['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
        ->and($blindSpots['uninstrumented-dispatcher'])->toBe(__('firewatch::messages.blind_spots.uninstrumented-dispatcher'));
});

it('opens what a finding points at', function () {
    failingJobsWorked([new ChargeCard, new SyncInventory], runs: 4);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution']);

    foreach ($envelope['next'] as $call) {
        $tool = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

        expect(Envelope::assert($tool, $call['arguments'])['empty'])->toBeNull();
    }
});

it('puts the verdict in the overview next to the other shapes', function () {
    failingJobsWorked([new ChargeCard, new ShipOrder], runs: 3);

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'failing-jobs');

    expect($row)->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1])
        ->and($row['worst']['name'])->toBe(ChargeCard::class);
});
