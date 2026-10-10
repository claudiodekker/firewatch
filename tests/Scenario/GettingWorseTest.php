<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;

/**
 * Create the store two hours before the real clock, so that every request starts before it ends, and get the instant it was created.
 */
function gettingWorseCreate(): float
{
    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    $created = floor(microtime(true)) - 7200;
    test()->travelTo(Date::createFromTimestamp($created));

    return $created;
}

/**
 * Serve the order list in an application of its own per request, each slice of requests starting together at its offset after the store's creation.
 *
 * @param  array<int, int>  $slices  how many requests start at each offset in seconds
 */
function gettingWorseServe(float $created, array $slices): void
{
    foreach ($slices as $offset => $count) {
        foreach (range(1, $count) as $ignored) {
            // Nightwatch reads a request's start once, when its provider registers, which makes the start the test's to set.
            $_SERVER['REQUEST_TIME_FLOAT'] = $created + $offset;
            forceRequests();
            config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

            Route::get('/orders', fn () => 'ok');

            test()->get('/orders');
        }
    }
}

it('says the order list is requested more and more, derives the window from the requests, and lists the busiest bucket', function () {
    $created = gettingWorseCreate();
    gettingWorseServe($created, [10 => 1, 70 => 1, 130 => 2, 190 => 2, 250 => 4, 310 => 5, 370 => 7, 430 => 8]);
    $this->travelTo(Date::createFromTimestamp($created + 5000));

    $envelope = Envelope::assert(Trend::class, ['type' => 'request', 'buckets' => 8]);
    $result = $envelope['result'];
    $peak = Envelope::follow($envelope['next'][0]);

    expect($envelope['window']['derived'])->toBe(['since', 'until'])
        ->and($envelope['window']['since'])->toBeLessThan($envelope['window']['until'])
        ->and(array_column($result['buckets'], 'occurrences'))->toBe([1, 1, 2, 2, 4, 5, 7, 8])
        ->and(array_column($result['buckets'], 'partial'))->toBe(array_fill(0, 8, false))
        ->and($result)->toMatchArray(['type' => 'request', 'by' => 'occurrences', 'direction' => 'rose', 'reason' => null, 'peak' => ['index' => 7, 'occurrences' => 8]])
        ->and($envelope['notes'])->toHaveCount(1)
        ->and($envelope['notes'][0])->toStartWith(explode(':duration', __('firewatch::messages.trend_idle_note'))[0])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion', 'console-requests')
        ->and($envelope['next'])->toHaveCount(1)
        ->and($peak['empty'])->toBeNull()
        ->and($peak['result']['rows'])->toHaveCount(8);
});

it('says the order list held when it is requested as often throughout, and names no peak', function () {
    $created = gettingWorseCreate();
    gettingWorseServe($created, [10 => 3, 70 => 3, 130 => 3, 190 => 3, 250 => 3, 310 => 3, 370 => 3, 430 => 3]);
    $this->travelTo(Date::createFromTimestamp($created + 440));

    $envelope = Envelope::assert(Trend::class, ['type' => 'request', 'buckets' => 8]);

    expect(array_column($envelope['result']['buckets'], 'occurrences'))->toBe(array_fill(0, 8, 3))
        ->and($envelope['result'])->toMatchArray(['direction' => 'held', 'reason' => null, 'peak' => null])
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['next'])->toBe([]);
});

it('leaves the buckets before a clear of the requests out of the direction, says so, and evaluates no direction on what is left', function () {
    $created = gettingWorseCreate();
    gettingWorseServe($created, [10 => 2, 110 => 2, 210 => 2]);
    $this->travelTo(Date::createFromTimestamp($created + 250));
    $this->artisan('firewatch:clear', ['--type' => 'request', '--force' => true])->run();
    gettingWorseServe($created, [350 => 1, 450 => 2, 550 => 3]);
    $this->travelTo(Date::createFromTimestamp($created + 5000));

    $envelope = Envelope::assert(Trend::class, ['type' => 'request', 'since' => $created, 'until' => $created + 800, 'buckets' => 8]);
    $result = $envelope['result'];
    $peak = Envelope::follow($envelope['next'][0]);

    expect($envelope['window']['derived'])->toBe([])
        ->and(array_column($result['buckets'], 'partial'))->toBe([true, true, true, false, false, false, false, false])
        ->and(array_column($result['buckets'], 'occurrences'))->toBe([0, 0, 0, 1, 2, 3, 0, 0])
        ->and($result)->toMatchArray(['direction' => null, 'reason' => 'sample_too_small', 'have' => 3, 'needed' => 4, 'peak' => ['index' => 5, 'occurrences' => 3]])
        ->and($envelope['notes'])->toBe([trans_choice('firewatch::messages.trend_partial_note', 3, ['count' => 3, 'type' => 'request'])])
        ->and($peak['result']['rows'])->toHaveCount(3);
});
