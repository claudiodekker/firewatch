<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;

function oneExecutionRequest(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

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

    $envelope = Envelope::assert(Execution::class);
    $result = $envelope['result'];
    $queries = array_values(array_filter($result['timeline'], fn (array $row) => $row['type'] === 'query'));

    expect($envelope['empty'])->toBeNull()
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

    $envelope = Envelope::assert(Execution::class);
    $call = $envelope['next'][0];
    $answer = Envelope::assert(Rank::class, $call['arguments']);

    expect($call['tool'])->toBe('rank')
        ->and($call['arguments'])->toBe(['group' => $envelope['result']['header']['group']])
        ->and($answer['empty'])->toBeNull();
});

it('opens the request by its trace id, which is its execution id', function () {
    oneExecutionRequest();
    $id = Envelope::assert(Execution::class)['result']['header']['trace_id'];

    $envelope = Envelope::assert(Execution::class, ['execution_id' => $id]);

    expect($envelope['result']['header']['execution_id'])->toBe($id);
});

it('opens the latest command, which has stages but no request details', function () {
    runArtisan(['command' => 'env']);
    Nightwatch::digest();

    $envelope = Envelope::assert(Execution::class, ['type' => 'command']);

    expect($envelope['result']['header'])->toMatchArray(['type' => 'command', 'source' => 'command', 'outcome' => 0])
        ->and(array_keys($envelope['result']['header']['stages']))->toBe(['bootstrap', 'action', 'terminating'])
        ->and($envelope['result'])->not->toHaveKey('request');
});

it('shows the exception a failing request raised, with the frames Nightwatch stored', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/boom', fn () => throw new DomainException('No stock left.'));

    $this->get('/boom');

    $envelope = Envelope::assert(Execution::class);
    $exception = $envelope['result']['exceptions'][0];
    $counters = array_column($envelope['result']['accounting']['counters'], 'state', 'counter');

    expect($envelope['result']['header']['outcome'])->toBe(500)
        ->and($exception)->toMatchArray(['class' => 'DomainException', 'message' => 'No stock left.', 'handled' => false])
        ->and($exception['location'])->toContain('OneExecutionTest.php')
        ->and($exception['frames'])->not->toBe([])
        ->and($counters['exceptions'])->toBe('match');
});

it('answers that no command ran, among the records of the request that did', function () {
    oneExecutionRequest();

    $envelope = Envelope::assert(Execution::class, ['type' => 'command']);

    expect($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['empty']['message'])->toContain('type: command')
        ->and($envelope['empty']['population'])->toBeGreaterThanOrEqual(1);
});

it('says what it cannot see: running work, the process peak of memory and a payload only for server errors', function () {
    oneExecutionRequest();

    $envelope = Envelope::assert(Execution::class);

    expect(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion', 'memory-is-process-peak', 'payload-on-server-error-only', 'dead-counters');
});
