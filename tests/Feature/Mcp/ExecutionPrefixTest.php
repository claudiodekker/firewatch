<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * Serve a number of requests from one test, each an execution of its own, and get their execution ids in order, grouped by their first character.
 *
 * @return Collection<string, Collection<int, string>>
 */
function prefixRequests(int $requests): Collection
{
    setEnvironmentVariable(name: 'FIREWATCH_CAPTURE_TESTS', value: 'true');

    test()->refreshApplication();
    Route::get('/orders', fn () => 'ok');

    foreach (range(1, $requests) as $request) {
        test()->get('/orders')->assertOk();
    }

    Nightwatch::digest();

    $rows = storeRows('SELECT execution_id FROM requests ORDER BY execution_id');

    return collect($rows)->pluck('execution_id')->groupBy(fn (string $id) => $id[0]);
}

/**
 * Get the text the tool refuses an execution id with.
 */
function prefixRefusal(string $id): string
{
    $response = FirewatchServer::tool(Execution::class, ['execution_id' => $id]);

    return (fn () => $this->content())->call($response)[0];
}

it('refuses a prefix that several executions share, naming them and how many they are', function () {
    $sharing = prefixRequests(17)->first(fn (Collection $ids) => $ids->count() > 1);
    $prefix = $sharing[0][0];

    expect(prefixRefusal($prefix))->toBe(__('firewatch::messages.execution_prefix_ambiguous', [
        'prefix' => $prefix,
        'matched' => $sharing->count(),
        'ids' => $sharing->take(5)->implode(', '),
        'example' => 'execution(execution_id: "<execution id>")',
    ]));
});

it('names the first five executions of a prefix that more of them share, and counts them all', function () {
    $sharing = prefixRequests(81)->first(fn (Collection $ids) => $ids->count() > 5);
    $prefix = $sharing[0][0];

    expect($sharing->count())->toBeGreaterThan(5)
        ->and(prefixRefusal($prefix))->toBe(__('firewatch::messages.execution_prefix_ambiguous', [
            'prefix' => $prefix,
            'matched' => $sharing->count(),
            'ids' => $sharing->take(5)->implode(', '),
            'example' => 'execution(execution_id: "<execution id>")',
        ]));
});

it('refuses what starts no execution id as not found', function () {
    $id = prefixRequests(1)->first()[0];

    $notFound = fn (string $id) => __('firewatch::messages.not_found', ['id' => $id, 'argument' => 'execution_id', 'accepted' => 'an execution id, not a trace id', 'example' => 'execution(execution_id: "<execution id>")']);

    expect(prefixRefusal('zz'))->toBe($notFound('zz'))
        ->and(prefixRefusal("{$id}0"))->toBe($notFound("{$id}0"))
        ->and(prefixRefusal(substr($id, 1)))->toBe($notFound(substr($id, 1)));
});
