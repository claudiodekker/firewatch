<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;

/**
 * Serve one request that runs a query with each of the bindings. A process records one execution, so one request is all a test can serve.
 *
 * @param  list<mixed>  $bindings
 */
function nPlusOneRequest(array $bindings): void
{
    nPlusOneServe(function () use ($bindings) {
        foreach ($bindings as $binding) {
            DB::select('select ? as author', [$binding]);
        }

        return 'ok';
    });
}

/**
 * Serve one request to the route.
 *
 * Nightwatch reads the testbench skeleton as the application, so this checkout's own vendor frames would be the call site of every query. Unless asked to keep the skeleton's layout, Nightwatch is pointed at the checkout, as an application outside a package has it.
 */
function nPlusOneServe(Closure $route, bool $skeleton = false): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    if (! $skeleton) {
        app(Core::class)->sensor->location->setBasePath(dirname(__DIR__, 2));
    }

    Route::get('/posts', $route);

    test()->get('/posts');
}

/**
 * Read three avatars, each from its own line of code.
 */
function nPlusOneAvatars(): string
{
    DB::select('select ? as avatar', ['user']);
    DB::select('select ? as avatar', ['team']);
    DB::select('select ? as avatar', ['project']);

    return 'ok';
}

it('finds the query a request ran once for each of forty posts, with forty different bindings', function () {
    nPlusOneRequest(range(1, 40));

    $answer = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);
    $finding = $answer['result']['findings'][0];

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($finding['name'])->toBe('/posts: select ? as author')
        ->and($finding['evidence'])->toMatchArray(['worst_runs' => 40, 'executions_affected' => 1, 'total_runs' => 40, 'distinct_bindings' => 40, 'unit' => ['source' => 'request', 'label' => '/posts']])
        ->and($finding['evidence']['call_sites'])->toHaveCount(1)
        ->and($finding['evidence']['call_sites'][0]['runs'])->toBe(40)
        ->and($answer['next'][0])->toMatchArray(['tool' => 'execution', 'why' => 'Open the latest execution behind this finding.']);
});

it('reads the same query three times with the same bindings as a redundant repeat', function () {
    nPlusOneRequest(['hello', 'hello', 'hello']);

    $evidence = Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result']['findings'][0]['evidence'];

    expect($evidence)->toMatchArray(['worst_runs' => 3, 'distinct_bindings' => 1]);
});

it('calls the query one line ran for each of forty posts a loop', function () {
    nPlusOneRequest(range(1, 40));

    expect(Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result']['findings'][0]['evidence']['pattern'])->toBe('loop');
});

it('tells a read that three lines of code each ran once from a loop', function () {
    nPlusOneServe(nPlusOneAvatars(...));

    $evidence = Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result']['findings'][0]['evidence'];

    expect($evidence)->toMatchArray(['worst_runs' => 3, 'distinct_bindings' => 3, 'pattern' => 'repeated'])
        ->and(array_column($evidence['call_sites'], 'runs'))->toBe([1, 1, 1]);
});

it('cannot tell a loop from a repeat when every run is attributed to the same vendor line', function () {
    nPlusOneServe(nPlusOneAvatars(...), skeleton: true);

    $evidence = Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result']['findings'][0]['evidence'];

    expect($evidence)->toMatchArray(['worst_runs' => 3, 'pattern' => 'unknown'])
        ->and($evidence['call_sites'])->toHaveCount(1)
        ->and($evidence['call_sites'][0]['file'])->toContain(nativePath('vendor/'));
});

it('finds nothing in a request that ran a query twice, and says it looked at the request', function () {
    nPlusOneRequest([1, 2]);

    expect(Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
});

it('opens what a finding points at', function () {
    nPlusOneRequest(range(1, 5));

    $answer = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);

    expect(array_map(fn (array $call) => Answers::follow($call)['empty'], $answer['next']))->each->toBeNull();
});

it('puts the verdict in the overview before the other shapes', function () {
    nPlusOneRequest(range(1, 5));

    $row = Answers::assert(Overview::class)['result']['detectors'][0];

    expect($row)->toMatchArray(['detector' => 'n-plus-one', 'verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($row['worst']['name'])->toBe('/posts: select ? as author');
});
