<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Route;

const WHICH_DEPLOY_SLOW_MS = 150;

/**
 * Serve the route three times in an application of its own per request, under the deploy identity the package sets, taking the given time.
 */
function whichDeployServe(string $deploy, int $milliseconds): void
{
    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach (range(1, 3) as $ignored) {
        // Nightwatch reads a request's start when its provider registers; the process's own start would count every earlier test into the request's duration.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        setEnvironmentVariable(name: 'FIREWATCH_DEPLOY', value: $deploy);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/orders', function () use ($milliseconds) {
            takeAtLeast($milliseconds);

            return 'ok';
        });
        Route::get('/up', fn () => 'ok');

        test()->get('/orders');
        test()->get('/up');
    }
}

it('names the route that got slow in the block of the deploy that slowed it, and the comparison it offers runs', function () {
    whichDeployServe('v1', 0);
    whichDeployServe('v2', WHICH_DEPLOY_SLOW_MS);

    $envelope = Envelope::assert(Overview::class, ['by_deploy' => true]);
    [$first, $second] = $envelope['result']['slowest_by_total_time'];

    expect([$first['deploy'], $second['deploy']])->toBe(['v1', 'v2'])
        ->and($second['groups'][0]['label'])->toBe('/orders')
        ->and($second['groups'][0]['avg_ms'])->toBeGreaterThan(collect($first['groups'])->firstWhere('label', '/orders')['avg_ms'])
        ->and($envelope['next'][0])->toMatchArray(['tool' => 'compare', 'arguments' => ['type' => 'request', 'deploy_before' => 'v1', 'deploy_after' => 'v2']]);

    $compared = Envelope::follow($envelope['next'][0]);

    expect($compared['empty'])->toBeNull()
        ->and($compared['result']['before']['deploy'])->toBe('v1')
        ->and($compared['result']['after']['deploy'])->toBe('v2')
        ->and(array_column($compared['result']['groups'], 'label'))->toContain('/orders');
});
