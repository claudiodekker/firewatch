<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
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

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-routes']);
    $findings = collect($envelope['result']['findings'])->keyBy('name');

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 5, 'total' => 3])
        ->and($envelope['result']['findings'][0]['name'])->toBe('/boom')
        ->and($findings->keys()->sort()->values()->all())->toBe(collect(['/boom', '/teapot', __('firewatch::messages.rank_no_route')])->sort()->values()->all())
        ->and($findings['/boom']['evidence'])->toMatchArray(['failed' => 1, 'requests' => 1, 'server_errors' => 1, 'unmatched' => false])
        ->and($findings['/boom']['evidence']['status_counts'])->toEqual([500 => 1])
        ->and($findings['/teapot']['evidence']['status_counts'])->toEqual([418 => 1])
        ->and($findings[__('firewatch::messages.rank_no_route')]['evidence'])->toMatchArray(['unmatched' => true])
        ->and($findings[__('firewatch::messages.rank_no_route')]['evidence']['status_counts'])->toEqual([404 => 1]);
});

it('opens what a finding points at', function () {
    failingRoutesTraffic();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-routes']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();
});

it('puts the verdict in the overview next to the other shapes', function () {
    failingRoutesTraffic();

    $row = Envelope::assert(Overview::class)['result']['detectors'][0];

    expect($row)->toMatchArray(['detector' => 'failing-routes', 'verdict' => 'findings', 'examined' => 5, 'total' => 3])
        ->and($row['worst']['name'])->toBe('/boom');
});
