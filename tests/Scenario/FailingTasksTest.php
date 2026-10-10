<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Put the tasks on the schedule and let the scheduler run them once.
 *
 * The application is a fresh one, so the exception a task throws reaches the real handler, and the scheduler is the first command of the process.
 *
 * @param  list<string>  $tasks
 */
function failingTasksRan(array $tasks): void
{
    test()->refreshApplication();

    $schedule = app(Schedule::class);

    foreach ($tasks as $task) {
        match ($task) {
            'send-digest' => $schedule->call(fn () => null)->name('send-digest')->everyMinute(),
            'prune-carts' => $schedule->call(fn () => throw new RuntimeException('The carts table is locked.'))->name('prune-carts')->everyMinute(),
            'sync-stock' => $schedule->call(fn () => null)->name('sync-stock')->everyMinute()->skip(true),
        };
    }

    runArtisan(['command' => 'schedule:run']);
}

it('flags the task that failed before the one that was only skipped, and not the one that ran', function () {
    failingTasksRan(['send-digest', 'sync-stock', 'prune-carts']);
    [$failed] = storeRows("SELECT execution_id FROM scheduled_tasks WHERE status = 'failed'");
    [$skipped] = storeRows("SELECT execution_id FROM scheduled_tasks WHERE status = 'skipped'");

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);
    $findings = $envelope['result']['findings'];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'threshold' => null, 'examined' => 3, 'total' => 2, 'saw' => []])
        ->and(array_column($findings, 'name'))->toBe(['prune-carts', 'sync-stock'])
        ->and($findings[0])->toMatchArray(['count' => 1, 'latest_execution_id' => $failed['execution_id']])
        ->and($findings[0]['evidence'])->toBe(['kind' => 'failed', 'failed' => 1, 'skipped' => 0, 'runs' => 1])
        ->and($findings[1])->toMatchArray(['count' => 1, 'latest_execution_id' => $skipped['execution_id']])
        ->and($findings[1]['evidence'])->toBe(['kind' => 'skipped_only', 'failed' => 0, 'skipped' => 1, 'runs' => 1]);
});

it('finds nothing in a task that was processed, and says it examined it', function () {
    failingTasksRan(['send-digest']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0, 'findings' => []]);
});

it('does not call a store without a scheduled task clean', function () {
    runArtisan(['command' => 'env']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []]);
});

it('says that a skip is often intended and that a task that did not fire is not seen, whatever the verdict', function (Closure $traffic, string $verdict) {
    $traffic();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);

    expect($envelope['result']['verdict'])->toBe($verdict)
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_skipped'), __('firewatch::messages.detect_caveat_not_fired')]);
})->with([
    'with findings' => [fn () => failingTasksRan(['prune-carts']), 'findings'],
    'when clean' => [fn () => failingTasksRan(['send-digest']), 'clean'],
    'with nothing examined' => [fn () => runArtisan(['command' => 'env']), 'not_evaluated'],
]);

it('states what it cannot see of scheduled tasks, on the real runs', function () {
    failingTasksRan(['prune-carts']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($blindSpots)->toHaveKeys(['dead-counters', 'memory-is-process-peak'])
        ->and($blindSpots['dead-counters'])->toBe(__('firewatch::messages.blind_spots.dead-counters'))
        ->and($blindSpots['memory-is-process-peak'])->toBe(__('firewatch::messages.blind_spots.memory-is-process-peak'));
});

it('opens what a finding points at', function () {
    failingTasksRan(['send-digest', 'sync-stock', 'prune-carts']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-tasks']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();
});

it('puts the verdict in the overview next to the other shapes', function () {
    failingTasksRan(['send-digest', 'sync-stock', 'prune-carts']);

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'failing-tasks');

    expect($row)->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 2])
        ->and($row['worst']['name'])->toBe('prune-carts');
});
