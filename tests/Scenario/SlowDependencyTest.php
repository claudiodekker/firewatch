<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Serve the requests in a fresh application whose report route calls a host that answers after 30 ms and whose stock route calls one that answers at once.
 *
 * @param  list<string>  $uris
 */
function slowDependencyRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Http::fake([
        'https://reports.example.com/*' => function () {
            usleep(30_000);

            return Http::response('ok');
        },
        'https://stock.example.com/*' => Http::response('ok'),
    ]);

    Route::get('/report/{id}', function (string $id) {
        Http::get("https://reports.example.com/build?id={$id}");

        return 'ok';
    });
    Route::get('/stock', function () {
        Http::get('https://stock.example.com/levels');

        return 'ok';
    });

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

it('ranks the slow host first however it is measured, and says why p95 fell back to the maximum', function (array $arguments) {
    slowDependencyRequests(['/stock', '/report/1', '/report/2', '/stock', '/report/3', '/stock']);

    $ranked = Envelope::assert(Rank::class, ['type' => 'outgoing-request', ...$arguments]);
    [$slow, $fast] = $ranked['result']['groups'];

    expect(array_column($ranked['result']['groups'], 'label'))->toBe(['reports.example.com', 'stock.example.com'])
        ->and($slow)->toMatchArray(['occurrences' => 3, 'failure_pct' => 0])
        ->and($slow['p50_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and($slow['max_ms'])->toBeGreaterThanOrEqual(30.0)
        ->and($fast['max_ms'])->toBeLessThan(30.0)
        ->and($ranked['result']['failure_definition'])->toBe('status >= 400')
        ->and(array_column($ranked['blind_spots'], 'id'))->toContain('unanswered-outgoing-requests');
})->with([
    'by the default measure' => [[]],
    'by p95' => [['by' => 'p95_duration']],
    'by p50' => [['by' => 'p50_duration']],
    'by total time' => [['by' => 'total_duration']],
    'by the maximum' => [['by' => 'max_duration']],
]);

it('withholds p95 below its sample floor instead of passing the maximum off as it', function () {
    slowDependencyRequests(['/report/1', '/report/2', '/report/3']);

    $ranked = Envelope::assert(Rank::class, ['type' => 'outgoing-request']);
    $slow = $ranked['result']['groups'][0];

    expect($slow['p95_ms'])->toBeNull()
        ->and($slow['withheld']['p95_ms'])->toMatchArray(['reason' => 'sample_too_small', 'have' => 3, 'needed' => 20])
        ->and($ranked['notes'])->not->toBeEmpty();
});

it('drills from the slow host to the calls that took the time', function () {
    slowDependencyRequests(['/stock', '/report/1', '/report/2', '/stock', '/report/3', '/stock']);

    $ranked = Envelope::assert(Rank::class, ['type' => 'outgoing-request']);
    $slow = $ranked['result']['groups'][0];
    $breakdown = Envelope::follow($ranked['next'][0]);

    expect($breakdown['result'])->toMatchArray(['group' => $slow['group'], 'label' => 'reports.example.com', 'records' => 3]);

    $calls = Envelope::assert(Occurrences::class, ['group' => $slow['group']]);
    $rows = $calls['result']['rows'];

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'type'))->each->toBe('outgoing-request')
        ->and(array_column($rows, 'name'))->each->toBe('reports.example.com')
        ->and(array_column($rows, 'duration_ms'))->each->toBeGreaterThanOrEqual(30.0);

    $over = Envelope::assert(Occurrences::class, ['type' => 'outgoing-request', 'slower_than_ms' => 30]);

    expect(array_column($over['result']['rows'], 'group'))->each->toBe($slow['group'])
        ->and($over['result']['rows'])->toHaveCount(3);
});
