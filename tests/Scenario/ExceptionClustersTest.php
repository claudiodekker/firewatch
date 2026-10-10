<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Workbench\App\Jobs\ChargeCard;

/**
 * Serve the requests in a fresh application, so an exception a route throws reaches the real handler.
 *
 * @param  list<string>  $uris
 */
function exceptionClustersRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/orders', fn () => 'ok');
    Route::get('/boom', fn () => abort(500));
    Route::get('/reports', function () {
        report(new DomainException('The report is stale.'));

        return 'ok';
    });
    Route::get('/exports/nightly', function () {
        Nightwatch::report(new FatalError('Allowed memory size of 134217728 bytes exhausted', 0, ['type' => E_ERROR, 'file' => __FILE__, 'line' => __LINE__]));

        return 'ok';
    });

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

/**
 * Let a worker run one attempt of a job that throws, in a fresh application whose queue is a table of its own.
 */
function exceptionClustersJob(): void
{
    test()->refreshApplication();

    config()->set('queue.default', 'database');
    config()->set('queue.failed.driver', 'null');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    dispatch(new ChargeCard);

    Nightwatch::digest();

    runArtisan(['command' => 'queue:work', '--once' => true]);
}

/**
 * Let a job throw, then serve requests that throw, report and die.
 */
function exceptionClustersTraffic(): void
{
    exceptionClustersJob();

    $labelsTheSharedExecution = '/invoices/8';

    exceptionClustersRequests(['/reports', '/reports', '/reports', '/exports/nightly', '/orders', '/invoices/7', $labelsTheSharedExecution]);
}

it('flags the exception groups, those that escaped before the one that was only reported, and says where each occurred', function () {
    exceptionClustersTraffic();
    [$attempt] = storeRows('SELECT execution_id FROM job_attempts');
    [$request] = storeRows('SELECT DISTINCT execution_id FROM requests');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);
    $findings = $envelope['result']['findings'];
    $evidence = array_column($findings, 'evidence');

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 8, 'total' => 4, 'saw' => [], 'caveats' => []])
        ->and(array_column($evidence, 'message'))->toBe(['Invoice [8] could not be rendered.', 'Allowed memory size of 134217728 bytes exhausted', 'The card was declined.', 'The report is stale.'])
        ->and(array_column($findings, 'name'))->toBe([RuntimeException::class, FatalError::class, RuntimeException::class, DomainException::class])
        ->and(array_column($findings, 'count'))->toBe([2, 1, 1, 3])
        ->and(array_column($findings, 'latest_execution_id'))->toBe([$request['execution_id'], $request['execution_id'], $attempt['execution_id'], $request['execution_id']])
        ->and(array_column($evidence, 'escaped'))->toBe([2, 1, 1, 0])
        ->and(array_column($evidence, 'reported'))->toBe([0, 0, 0, 3])
        ->and(array_column($evidence, 'fatal'))->toBe([false, true, false, false])
        ->and($evidence[0]['app_frame'])->toEndWith(nativePath("workbench/routes/web.php:{$evidence[0]['line']}"))
        ->and($evidence[1]['app_frame'])->toBeNull()
        ->and($evidence[2]['app_frame'])->toEndWith(nativePath("workbench/app/Jobs/ChargeCard.php:{$evidence[2]['line']}"))
        ->and($evidence[3]['app_frame'])->toEndWith(nativePath("tests/Scenario/ExceptionClustersTest.php:{$evidence[3]['line']}"))
        ->and($evidence[0]['units'])->toBe([['source' => 'request', 'label' => '/invoices/{invoice}', 'occurrences' => 2]])
        ->and($evidence[2]['units'])->toBe([['source' => 'job', 'label' => ChargeCard::class, 'occurrences' => 1]])
        ->and($findings[2]['reaches'])->toBe(['signed_in_actors' => 0, 'without_actor' => 1])
        ->and($evidence[3]['units'])->toHaveCount(1)
        ->and($evidence[3]['units'][0])->toMatchArray(['source' => 'request', 'occurrences' => 3]);
});

it('finds nothing in requests that threw nothing, and says it examined them', function () {
    exceptionClustersRequests(['/orders', '/orders']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []]);
});

it('finds the exception a scheduled task throws, and has no record of one a task reports without throwing', function () {
    test()->refreshApplication();

    $schedule = app(Schedule::class);
    $schedule->call(fn () => throw new RuntimeException('The carts table is locked.'))->name('prune-carts')->everyMinute();
    $schedule->call(function () {
        report(new LogicException('The digest had no readers.'));
    })->name('send-digest')->everyMinute();

    runArtisan(['command' => 'schedule:run']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
        ->and(array_column(array_column($envelope['result']['findings'], 'evidence'), 'units', 'message'))->toBe([
            'The carts table is locked.' => [['source' => 'schedule', 'label' => 'prune-carts', 'occurrences' => 1]],
        ])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('exceptions-unreported');
});

it('is clean over a request that failed without an exception record, and says that it cannot see one', function () {
    exceptionClustersRequests(['/boom']);
    [$failed] = storeRows('SELECT status_code FROM requests');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($failed['status_code'])->toBe(500)
        ->and($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0])
        ->and($blindSpots)->toHaveKey('exceptions-unreported')
        ->and($blindSpots['exceptions-unreported'])->toBe(__('firewatch::messages.blind_spots.exceptions-unreported'));
});

it('does not call a store without an execution clean', function () {
    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'examined' => 0, 'total' => 0, 'findings' => []]);
});

it('opens what a finding points at', function () {
    exceptionClustersTraffic();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'exception-clusters']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution', 'execution']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();
});

it('puts the verdict in the overview next to the other shapes', function () {
    exceptionClustersTraffic();

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'exception-clusters');

    expect($row)->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 8, 'total' => 4])
        ->and($row['worst']['name'])->toBe(RuntimeException::class);
});
