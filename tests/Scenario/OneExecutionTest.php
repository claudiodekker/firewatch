<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Jobs\ShipOrder;

function oneExecutionApplication(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
}

function oneExecutionRequest(): void
{
    oneExecutionApplication();

    Route::get('/orders', function () {
        DB::select('select 1');
        DB::select('select 1');
        DB::select('select 2');

        return 'ok';
    });

    test()->get('/orders');
}

it('shows what happened in a request: its header, a matching accounting and its queries in order', function () {
    oneExecutionRequest();

    $answer = Answers::assert(Execution::class);
    $result = $answer['result'];
    $queries = array_values(array_filter($result['timeline'], fn (array $row) => $row['type'] === 'query'));

    expect($answer['empty'])->toBeNull()
        ->and($result['header'])->toMatchArray(['type' => 'request', 'source' => 'request', 'label' => '/orders', 'outcome' => 200])
        ->and($result['header']['execution_id'])->toBe($result['header']['trace_id'])
        ->and(array_keys($result['header']['stages']))->toBe(['bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating'])
        ->and($result['accounting']['counters'][0])->toBe(['counter' => 'queries', 'counted' => 3, 'captured' => 3, 'state' => 'match'])
        ->and(array_unique(array_column($result['accounting']['counters'], 'state')))->toBe(['match'])
        ->and($result['accounting']['lines'])->toBe([])
        ->and(array_column($queries, 'name'))->toBe(['select 1', 'select 2'])
        ->and(array_column($queries, 'count'))->toBe([2, null])
        ->and($result['request']['headers'])->toBeArray()->toHaveKey('host');
});

it('offers a next call that runs', function () {
    oneExecutionRequest();

    $answer = Answers::assert(Execution::class);
    $call = $answer['next'][0];
    $followed = Answers::follow($call);

    expect($call['tool'])->toBe('rank')
        ->and($call['arguments'])->toBe(['group' => $answer['result']['header']['group']])
        ->and($followed['empty'])->toBeNull();
});

it('opens the request by its trace id, which is its execution id', function () {
    oneExecutionRequest();
    $id = Answers::assert(Execution::class)['result']['header']['trace_id'];

    $answer = Answers::assert(Execution::class, ['execution_id' => $id]);

    expect($answer['result']['header']['execution_id'])->toBe($id);
});

it('opens the latest command, which has stages but no request details', function () {
    runArtisan(['command' => 'env']);
    Nightwatch::digest();

    $answer = Answers::assert(Execution::class, ['type' => 'command']);

    expect($answer['result']['header'])->toMatchArray(['type' => 'command', 'source' => 'command', 'outcome' => 0])
        ->and(array_keys($answer['result']['header']['stages']))->toBe(['bootstrap', 'action', 'terminating'])
        ->and($answer['result'])->not->toHaveKey('request');
});

it('shows the exception a failing request raised, with the frames Nightwatch stored', function () {
    oneExecutionApplication();
    Route::get('/boom', fn () => throw new DomainException('No stock left.'));

    $this->get('/boom');

    $answer = Answers::assert(Execution::class);
    $exception = $answer['result']['exceptions'][0];
    $counters = array_column($answer['result']['accounting']['counters'], 'state', 'counter');

    expect($answer['result']['header']['outcome'])->toBe(500)
        ->and($exception)->toMatchArray(['class' => 'DomainException', 'message' => 'No stock left.', 'handled' => false])
        ->and($exception['location'])->toContain('OneExecutionTest.php')
        ->and($exception['frames'])->not->toBe([])
        ->and($counters['exceptions'])->toBe('match');
});

it('answers that no command ran, among the records of the request that did', function () {
    oneExecutionRequest();

    $answer = Answers::assert(Execution::class, ['type' => 'command']);

    expect($answer['empty']['kind'])->toBe('no_match')
        ->and($answer['empty']['population'])->toBeGreaterThanOrEqual(1)
        ->and($answer['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => $answer['empty']['population'], 'filters' => 'type: command']));
});

it('says what it cannot see: running work, the process peak of memory and a payload only for server errors', function () {
    oneExecutionRequest();

    $answer = Answers::assert(Execution::class);

    expect(array_column($answer['blind_spots'], 'id'))->toContain('visible-at-completion', 'memory-is-process-peak', 'payload-on-server-error-only', 'dead-counters');
});

function oneExecutionShipment(string $connection): void
{
    oneExecutionApplication();

    // The application the request forced is a fresh one, with an empty database.
    test()->loadLaravelMigrations();

    config()->set('queue.default', $connection);
    Route::get('/ship', function () {
        dispatch(new ShipOrder);

        return 'ok';
    });

    test()->get('/ship');
}

function oneExecutionWorkedJob(): void
{
    config()->set('queue.default', 'database');
    dispatch(new ShipOrder);
    Nightwatch::digest();

    runArtisan(['command' => 'queue:work', '--once' => true]);
}

it('follows a job from its dispatch to the worker attempt that ran it', function () {
    oneExecutionWorkedJob();
    [$dispatch] = storeRows('SELECT trace_id, job_id FROM queued_jobs');

    $answer = Answers::assert(Trace::class, ['trace_id' => $dispatch['trace_id']]);
    $job = $answer['result']['jobs'][0];

    expect(array_column($answer['result']['executions'], 'source'))->toBe(['job'])
        ->and($answer['result']['jobs'])->toHaveCount(1)
        ->and($job)->toMatchArray(['job_id' => $dispatch['job_id'], 'name' => ShipOrder::class, 'lineage' => 'complete', 'outcome' => 'processed'])
        ->and($job['dispatch'])->toMatchArray(['connection' => 'database', 'queue' => 'default'])
        ->and($job['attempts'])->toHaveCount(1)
        ->and($job['attempts'][0])->toMatchArray(['attempt' => 1, 'status' => 'processed', 'trace_id' => null])
        ->and($job['attempts'][0]['wait_ms'])->toBeGreaterThanOrEqual(0)
        ->and($answer['notes'])->toBe([]);
});

it('starts from the job id and reaches the same trace', function () {
    oneExecutionWorkedJob();
    [$dispatch] = storeRows('SELECT trace_id, job_id FROM queued_jobs');

    $answer = Answers::assert(Trace::class, ['job_id' => $dispatch['job_id']]);

    expect($answer['result']['trace_id'])->toBe($dispatch['trace_id'])
        ->and(array_column($answer['result']['executions'], 'source'))->toBe(['job'])
        ->and(array_column($answer['result']['jobs'], 'job_id'))->toBe([$dispatch['job_id']]);
});

it('shows a job queued by a request and not yet taken by a worker as pending', function () {
    oneExecutionShipment('database');
    Nightwatch::digest();
    [$request] = storeRows('SELECT trace_id FROM requests');

    $answer = Answers::assert(Trace::class, ['trace_id' => $request['trace_id']]);

    expect(array_column($answer['result']['executions'], 'source'))->toBe(['request'])
        ->and($answer['result']['executions'][0])->toMatchArray(['execution_id' => $request['trace_id'], 'label' => '/ship', 'outcome' => 200])
        ->and($answer['result']['jobs'][0])->toMatchArray(['lineage' => 'no_attempts', 'outcome' => 'pending', 'attempts' => []])
        ->and($answer['result']['jobs'][0]['dispatch'])->toMatchArray(['execution_id' => $request['trace_id'], 'connection' => 'database', 'queue' => 'default'])
        ->and($answer['notes'])->toBe([__('firewatch::messages.trace_partial_no_attempts')]);
});

it('finds no job for a request that ran its job on the sync connection, and says so', function () {
    oneExecutionShipment('sync');
    Nightwatch::digest();
    [$request] = storeRows('SELECT trace_id FROM requests');

    $answer = Answers::assert(Trace::class, ['trace_id' => $request['trace_id']]);

    expect($answer['result']['jobs'])->toBe([])
        ->and(array_column($answer['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded');
});

it('offers next calls', function () {
    oneExecutionShipment('database');
    Nightwatch::digest();
    [$request] = storeRows('SELECT trace_id FROM requests');

    $answer = Answers::assert(Trace::class, ['trace_id' => $request['trace_id']]);
    expect(array_column($answer['next'], 'tool'))->not->toBe([])->each->toBe('execution');
});
