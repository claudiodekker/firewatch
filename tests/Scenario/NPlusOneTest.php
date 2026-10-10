<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Serve one request that runs a query with each of the bindings. A process records one execution, so one request is all a test can serve.
 *
 * @param  list<mixed>  $bindings
 */
function nPlusOneRequest(array $bindings): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/posts', function () use ($bindings) {
        foreach ($bindings as $binding) {
            DB::select('select ? as author', [$binding]);
        }

        return 'ok';
    });

    test()->get('/posts');
}

it('finds the query a request ran once for each of forty posts, with forty different bindings', function () {
    nPlusOneRequest(range(1, 40));

    $envelope = Envelope::assert(Detect::class, ['shape' => 'n-plus-one']);
    $finding = $envelope['result']['findings'][0];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($finding['name'])->toBe('/posts: select ? as author')
        ->and($finding['evidence'])->toMatchArray(['worst_runs' => 40, 'executions_affected' => 1, 'total_runs' => 40, 'distinct_bindings' => 40, 'unit' => ['source' => 'request', 'label' => '/posts']])
        ->and($finding['evidence']['call_sites'])->toHaveCount(1)
        ->and($finding['evidence']['call_sites'][0]['runs'])->toBe(40);
});

it('reads the same query three times with the same bindings as a redundant repeat', function () {
    nPlusOneRequest(['hello', 'hello', 'hello']);

    $evidence = Envelope::assert(Detect::class, ['shape' => 'n-plus-one'])['result']['findings'][0]['evidence'];

    expect($evidence)->toMatchArray(['worst_runs' => 3, 'distinct_bindings' => 1]);
});

it('finds nothing in a request that ran a query twice, and says it looked at the request', function () {
    nPlusOneRequest([1, 2]);

    expect(Envelope::assert(Detect::class, ['shape' => 'n-plus-one'])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
});

it('opens what a finding points at', function () {
    nPlusOneRequest(range(1, 5));

    $envelope = Envelope::assert(Detect::class, ['shape' => 'n-plus-one']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();
});

it('puts the verdict in the overview before the other shapes', function () {
    nPlusOneRequest(range(1, 5));

    $row = Envelope::assert(Overview::class)['result']['detectors'][0];

    expect($row)->toMatchArray(['detector' => 'n-plus-one', 'verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($row['worst']['name'])->toBe('/posts: select ? as author');
});
