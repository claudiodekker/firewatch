<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Serve the requests in a fresh application whose routes call a payment dependency that answers errors, a stock dependency that works and a mail dependency that never answers.
 *
 * @param  list<string>  $uris
 */
function failingHttpRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Http::fake([
        'https://payments.example.com/charges*' => Http::response('unavailable', 503),
        'https://payments.example.com/rates*' => Http::response('slow down', 429),
        'https://stock.example.com/*' => Http::response('ok'),
        'https://mail.example.com/*' => Http::failedConnection(),
    ]);

    Route::get('/checkout/{order}', function (string $order) {
        Http::post("https://payments.example.com/charges?order={$order}");

        return 'ok';
    });
    Route::get('/rates', function () {
        Http::get('https://payments.example.com/rates');

        return 'ok';
    });
    Route::get('/stock', function () {
        Http::get('https://stock.example.com/levels?sku=7');

        return 'ok';
    });
    Route::get('/welcome', function () {
        try {
            Http::post('https://mail.example.com/send');
        } catch (ConnectionException) {
            //
        }

        return 'ok';
    });

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

it('flags the dependency that answered errors, and not the one that worked', function () {
    failingHttpRequests(['/checkout/7', '/stock', '/checkout/12', '/rates', '/stock']);
    [$rates] = storeRows("SELECT execution_id, group_hash FROM outgoing_requests WHERE url LIKE '%/rates'");

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-http']);
    $findings = $envelope['result']['findings'];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 5, 'total' => 1, 'saw' => []])
        ->and($envelope['result']['threshold'])->toMatchArray(['name' => 'status', 'value' => 400, 'is_default' => true])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0])->toMatchArray(['group' => $rates['group_hash'], 'name' => 'payments.example.com', 'count' => 3, 'latest_execution_id' => $rates['execution_id']])
        ->and($findings[0]['reaches'])->toBe(['signed_in_actors' => 0, 'without_actor' => 3])
        ->and($findings[0]['evidence'])->toMatchArray([
            'host' => 'payments.example.com',
            'calls' => 3,
            'failures' => 3,
            'failure_pct' => 100,
            'status_counts' => [429 => 1, 503 => 2],
            'top_urls' => [
                ['url' => 'https://payments.example.com/charges', 'failures' => 2],
                ['url' => 'https://payments.example.com/rates', 'failures' => 1],
            ],
        ]);
});

it('names the route the failing outgoing requests ran in', function () {
    failingHttpRequests(['/checkout/7', '/checkout/12']);

    $finding = Envelope::assert(Detect::class, ['shape' => 'failing-http'])['result']['findings'][0];

    // The requests of one test process share an execution id, so the routes of several cannot be told apart here.
    expect($finding['evidence']['ran_in'])->toBe([['source' => 'request', 'label' => '/checkout/{order}', 'calls' => 2]]);
});

it('shows the same host when the outgoing requests are ranked', function () {
    failingHttpRequests(['/checkout/7', '/stock', '/checkout/12']);

    $finding = Envelope::assert(Detect::class, ['shape' => 'failing-http'])['result']['findings'][0];
    $groups = collect(Envelope::assert(Rank::class, ['type' => 'outgoing-request'])['result']['groups'])->keyBy('group');

    expect($groups->keys()->all())->toContain($finding['group'])
        ->and($groups[$finding['group']])->toMatchArray(['label' => 'payments.example.com', 'occurrences' => 2, 'failure_pct' => 100.0]);
});

it('finds nothing in a dependency that worked, and says how many outgoing requests it examined', function () {
    failingHttpRequests(['/stock', '/stock']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-http']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []]);
});

it('does not see a dependency that never answered, and says that it cannot', function () {
    failingHttpRequests(['/welcome']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-http']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
        ->and($blindSpots)->toHaveKey('unanswered-outgoing-requests')
        ->and($blindSpots['unanswered-outgoing-requests'])->toBe(__('firewatch::messages.blind_spots.unanswered-outgoing-requests'));
});

it('says what clean means and that an unanswered outgoing request leaves no record, whatever the verdict', function (array $uris, string $verdict) {
    failingHttpRequests($uris);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-http']);

    expect($envelope['result']['verdict'])->toBe($verdict)
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_unanswered')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('unanswered-outgoing-requests');
})->with([
    'with findings' => [['/checkout/7'], 'findings'],
    'when clean' => [['/stock'], 'clean'],
    'with nothing examined' => [['/welcome'], 'not_evaluated'],
]);

it('opens what a finding points at', function () {
    failingHttpRequests(['/checkout/7', '/stock', '/rates']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'failing-http']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank']);

    foreach ($envelope['next'] as $call) {
        $tool = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

        expect(Envelope::assert($tool, $call['arguments'])['empty'])->toBeNull();
    }
});

it('puts the verdict in the overview next to the other shapes', function () {
    failingHttpRequests(['/checkout/7', '/stock', '/rates']);
    [$call] = storeRows("SELECT group_hash FROM outgoing_requests WHERE host = 'payments.example.com' LIMIT 1");

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'failing-http');

    expect($row)->toBe([
        'detector' => 'failing-http',
        'verdict' => 'findings',
        'reason' => null,
        'examined' => 3,
        'total' => 1,
        'worst' => ['name' => 'payments.example.com', 'group' => $call['group_hash']],
    ]);
});
