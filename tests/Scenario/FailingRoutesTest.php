<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

function failingRoutesTraffic(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/orders', fn () => 'ok');
    Route::get('/boom', fn () => abort(500));
    Route::get('/teapot', fn () => abort(418));

    test()->get('/orders');
    test()->get('/orders');
    test()->get('/boom');
    test()->get('/teapot');
    test()->get('/nowhere');
}

it('flags the routes whose requests failed, server errors first, and not the route that worked', function () {
    failingRoutesTraffic();

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes']);
    $findings = collect($answer['result']['findings'])->keyBy('name');

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 5, 'total' => 3])
        ->and($answer['result']['findings'][0]['name'])->toBe('/boom')
        ->and($findings->keys()->sort()->values()->all())->toBe(collect(['/boom', '/teapot', __('firewatch::messages.rank_no_route')])->sort()->values()->all())
        ->and($findings['/boom']['evidence'])->toMatchArray(['failed' => 1, 'requests' => 1, 'server_errors' => 1, 'unmatched' => false])
        ->and($findings['/boom']['evidence']['status_counts'])->toEqual([500 => 1])
        ->and($findings['/teapot']['evidence']['status_counts'])->toEqual([418 => 1])
        ->and($findings[__('firewatch::messages.rank_no_route')]['evidence'])->toMatchArray(['unmatched' => true])
        ->and($findings[__('firewatch::messages.rank_no_route')]['evidence']['status_counts'])->toEqual([404 => 1]);
});

it('opens what a finding points at', function () {
    failingRoutesTraffic();

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes']);

    expect(array_map(fn (array $call) => Answers::follow($call)['empty'], $answer['next']))->each->toBeNull();
});

it('puts the verdict in the overview next to the other shapes', function () {
    failingRoutesTraffic();

    $row = Answers::assert(Overview::class)['result']['detectors'][0];

    expect($row)->toMatchArray(['detector' => 'failing-routes', 'verdict' => 'findings', 'examined' => 5, 'total' => 3])
        ->and($row['worst']['name'])->toBe('/boom');
});

it('judges a request that failed validation on the status it answered and records nothing about its fields', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::post('/signup', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);

        return redirect('/welcome');
    });

    test()->post('/signup', ['email' => 'not-an-address'])->assertStatus(302);
    test()->postJson('/signup', ['email' => 'not-an-address'])->assertStatus(422);

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes']);
    $rows = Answers::assert(Occurrences::class, ['type' => 'request'])['result']['rows'];
    $executions = array_map(fn (array $row) => Answers::assert(Execution::class, ['execution_id' => $row['execution_id']])['result'], $rows);

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1, 'caveats' => []])
        ->and($answer['result']['findings'][0]['evidence'])->toMatchArray(['failed' => 1, 'requests' => 2, 'failure_pct' => 50.0, 'server_errors' => 0])
        ->and($answer['result']['findings'][0]['evidence']['status_counts'])->toEqual([422 => 1])
        ->and(array_map(fn (array $row) => $row['detail']['status_code'], $rows))->toBe([422, 302])
        ->and(json_encode([$answer, $rows, $executions]))->not->toContain('email');
});
