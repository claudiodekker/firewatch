<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Serve the requests in a fresh application whose routes read a key nothing fills, one that is filled once, forget a key that is not there and write to two stores that refuse.
 *
 * @param  list<string>  $uris
 */
function cacheRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('cache.stores.sessions', ['driver' => 'refusing']);
    config()->set('cache.stores.void', ['driver' => 'null']);

    Cache::extend('refusing', fn ($app, array $config) => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            return false;
        }
    }, $config));

    Route::get('/prices', fn () => Cache::get('prices', 'none'));
    Route::get('/settings', fn () => Cache::remember('settings', 60, fn () => 'dark'));
    Route::get('/logout', fn () => Cache::forget('token') ? 'forgotten' : 'absent');
    Route::get('/login', fn () => Cache::store('sessions')->put('session', 'open', 60) ? 'stored' : 'refused');
    Route::get('/discard', fn () => Cache::store('void')->put('draft', 'text', 60) ? 'stored' : 'refused');
    Route::get('/restart', fn () => Cache::get('illuminate:queue:restart', 'never'));

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

it('flags the key that is read and never filled, and not the one that is filled once', function () {
    cacheRequests(['/prices', '/settings', '/prices', '/settings', '/settings', '/prices', '/settings']);
    [$miss] = storeRows("SELECT execution_id, group_hash FROM cache_events WHERE \"key\" = 'prices' LIMIT 1");

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);
    $findings = $envelope['result']['findings'];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 8, 'total' => 1])
        ->and($envelope['result']['threshold'])->toMatchArray(['name' => 'percent', 'value' => 50, 'is_default' => true])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0])->toMatchArray(['group' => $miss['group_hash'], 'name' => 'prices', 'count' => 3, 'latest_execution_id' => $miss['execution_id']])
        ->and($findings[0]['reaches'])->toBe(['signed_in_actors' => 0, 'without_actor' => 3])
        ->and($findings[0]['evidence'])->toBe([
            'reasons' => ['low_hit_rate'],
            'store' => 'array',
            'key' => 'prices',
            'hits' => 0,
            'misses' => 3,
            'writes' => 0,
            'write_failures' => 0,
            'deletes' => 0,
            'delete_failures' => 0,
            'hit_rate_pct' => 0,
        ])
        ->and($envelope['result']['saw'])->toBe(['activity' => [
            'stores' => [['store' => 'array', 'hits' => 3, 'misses' => 4, 'writes' => 1, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 0, 'hit_rate_pct' => 42.9]],
            'total' => ['hits' => 3, 'misses' => 4, 'writes' => 1, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 0, 'hit_rate_pct' => 42.9],
        ]]);
});

it('finds nothing in a key that is filled once and hit after, and shows a cache that works by its activity', function () {
    cacheRequests(['/settings', '/settings', '/settings', '/settings']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 5, 'total' => 0, 'findings' => []])
        ->and($envelope['result']['saw']['activity']['total'])->toBe(['hits' => 3, 'misses' => 1, 'writes' => 1, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 0, 'hit_rate_pct' => 75]);
});

it('does not flag a key that was read only twice, however it missed', function () {
    cacheRequests(['/prices', '/prices']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0]);
});

it('does not see a key of the framework, and says that it cannot', function () {
    cacheRequests(['/restart']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
        ->and($envelope['result']['saw']['activity']['stores'])->toBe([])
        ->and($blindSpots)->toHaveKey('vendor-defaults-unrecorded')
        ->and($blindSpots['vendor-defaults-unrecorded'])->toBe(__('firewatch::messages.blind_spots.vendor-defaults-unrecorded'));
});

it('flags a key whose delete failed, with no hit rate as it was never read', function () {
    cacheRequests(['/logout']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($envelope['result']['findings'][0])->toMatchArray(['name' => 'token', 'count' => 1])
        ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['reasons' => ['delete_failing'], 'store' => 'array', 'delete_failures' => 1, 'hit_rate_pct' => null]);
});

it('flags a key whose write a store refused, and names the store as the wire did', function () {
    cacheRequests(['/login', '/discard', '/login']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);
    $evidence = array_column($envelope['result']['findings'], 'evidence');

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 2])
        ->and(array_column($envelope['result']['findings'], 'count', 'name'))->toBe(['session' => 2, 'draft' => 1])
        ->and($evidence[0])->toMatchArray(['reasons' => ['write_failing'], 'store' => 'sessions', 'write_failures' => 2, 'hit_rate_pct' => null])
        ->and($evidence[1])->toMatchArray(['reasons' => ['write_failing'], 'store' => '', 'write_failures' => 1, 'hit_rate_pct' => null])
        ->and(array_column($envelope['result']['saw']['activity']['stores'], 'write_failures', 'store'))->toBe(['' => 1, 'sessions' => 2])
        ->and($envelope['result']['saw']['activity']['total'])->toMatchArray(['write_failures' => 3, 'hit_rate_pct' => null]);
});

it('lists a key with a failure before one that is only read badly', function () {
    cacheRequests(['/prices', '/prices', '/logout', '/prices']);

    $findings = Envelope::assert(Detect::class, ['shape' => 'cache'])['result']['findings'];

    expect(array_column($findings, 'name'))->toBe(['token', 'prices'])
        ->and(array_column(array_column($findings, 'evidence'), 'reasons'))->toBe([['delete_failing'], ['low_hit_rate']]);
});

it('judges one key alone when its group is named', function () {
    cacheRequests(['/settings', '/settings', '/logout']);
    [$settings] = storeRows("SELECT group_hash FROM cache_events WHERE \"key\" = 'settings' LIMIT 1");

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache', 'group' => $settings['group_hash']]);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 3, 'total' => 0])
        ->and($envelope['result']['saw']['activity']['stores'])->toBe([['store' => 'array', 'hits' => 1, 'misses' => 1, 'writes' => 1, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 0, 'hit_rate_pct' => 50]]);
});

it('shows the same key when the cache events are ranked', function () {
    cacheRequests(['/prices', '/settings', '/prices', '/prices']);

    $finding = Envelope::assert(Detect::class, ['shape' => 'cache'])['result']['findings'][0];
    $groups = collect(Envelope::assert(Rank::class, ['type' => 'cache-event'])['result']['groups'])->keyBy('group');

    expect($groups->keys()->all())->toContain($finding['group'])
        ->and($groups[$finding['group']])->toMatchArray(['label' => 'prices', 'occurrences' => 3]);
});

it('says that keys that embed ids fragment into groups of one, whatever the verdict', function (array $uris, string $verdict) {
    cacheRequests($uris);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);

    expect($envelope['result']['verdict'])->toBe($verdict)
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_cache_keys')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('vendor-defaults-unrecorded');
})->with([
    'with findings' => [['/logout'], 'findings'],
    'when clean' => [['/settings'], 'clean'],
    'with nothing examined' => [['/restart'], 'not_evaluated'],
]);

it('opens what a finding points at', function () {
    cacheRequests(['/prices', '/settings', '/prices', '/prices']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'cache']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();
});

it('puts the verdict in the overview next to the other shapes', function () {
    cacheRequests(['/prices', '/login', '/prices', '/prices']);
    [$write] = storeRows("SELECT group_hash FROM cache_events WHERE \"key\" = 'session' LIMIT 1");

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'cache');

    expect($row)->toBe([
        'detector' => 'cache',
        'verdict' => 'findings',
        'reason' => null,
        'examined' => 4,
        'total' => 2,
        'worst' => ['name' => 'session', 'group' => $write['group_hash']],
    ]);
});
