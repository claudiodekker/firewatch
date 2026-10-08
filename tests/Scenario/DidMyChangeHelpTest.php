<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Serve each request in an application of its own that started when the request did, as a server does, with the routes of the code before or after the change.
 *
 * @param  list<string>  $uris
 */
function didMyChangeHelpServe(bool $changed, array $uris): void
{
    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach ($uris as $uri) {
        // Nightwatch reads a request's start once, when its provider registers.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/orders', function () {
            DB::select('select 1');

            return 'ok';
        });
        Route::get($changed ? '/orders/export' : '/legacy', fn () => 'ok');
        Route::get('/up', fn () => 'ok');

        test()->get($uri);
    }
}

/**
 * Serve the application before the change: the request that creates the store, then the order list three times and the legacy report twice.
 */
function didMyChangeHelpBefore(): void
{
    didMyChangeHelpServe(changed: false, uris: ['/up', '/orders', '/orders', '/orders', '/legacy', '/legacy']);
}

/**
 * Serve the application after the change: the order list three times and the export that replaced the legacy report twice.
 */
function didMyChangeHelpAfter(): void
{
    didMyChangeHelpServe(changed: true, uris: ['/orders', '/orders', '/orders', '/orders/export', '/orders/export']);
}

/**
 * Run a call an answer offers and get what it answers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function didMyChangeHelpFollow(array $call): array
{
    $tool = ['occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

    return Envelope::assert($tool, $call['arguments']);
}

it('compares the routes before and after the change at the now of an earlier answer, and every call it offers runs', function () {
    didMyChangeHelpBefore();
    $split = Envelope::assert(Overview::class)['now'];
    didMyChangeHelpAfter();

    $envelope = Envelope::assert(Compare::class, ['type' => 'request', 'split_at' => $split]);
    $result = $envelope['result'];
    $rows = collect($result['groups'])->keyBy('label');

    expect($result)->toMatchArray(['type' => 'request', 'by' => 'p95_duration', 'change' => null, 'reason' => null, 'side' => null, 'deploys' => null])
        ->and($result['before'])->toMatchArray(['until_at' => $split, 'clipped' => false, 'records' => 5])
        ->and($result['after'])->toMatchArray(['since_at' => $split, 'until_at' => $envelope['now'], 'clipped' => false, 'records' => 5])
        ->and($result['rollup'])->toMatchArray(['groups' => 3, 'new' => 1, 'gone' => 1, 'one_side_only' => 2, 'cut' => 0])
        ->and(array_keys($rows->all()))->toEqualCanonicalizing(['/orders', '/orders/export', '/legacy'])
        ->and($rows['/orders/export'])->toMatchArray(['method' => 'GET', 'before_records' => 0, 'after_records' => 2, 'change' => 'new', 'change_pct' => null])
        ->and($rows['/legacy'])->toMatchArray(['method' => 'GET', 'before_records' => 2, 'after_records' => 0, 'change' => 'gone', 'change_pct' => null])
        ->and($rows['/orders'])->toMatchArray(['before_records' => 3, 'after_records' => 3, 'measured_on' => 'p50', 'reason' => null])
        ->and($rows['/orders']['change'])->toBeIn(['slower', 'faster', 'steady'])
        ->and($rows['/orders']['before_ms'])->toBeGreaterThan(0)
        ->and($envelope['summary'])->toStartWith(explode(':changes', trans_choice('firewatch::messages.compare_summary', 3, ['groups' => 3, 'type' => 'request', 'by' => 'p95_duration']))[0])
        ->and($envelope['coverage']['straddling'])->toBe(0)
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion', 'console-requests')
        ->and($envelope['notes'])->toBe([])
        ->and(array_column($envelope['next'], 'tool'))->toBe(['occurrences', 'rank']);

    $first = collect($result['groups'])->firstWhere('group', $envelope['next'][0]['arguments']['group']);

    foreach ($envelope['next'] as $call) {
        $answer = didMyChangeHelpFollow($call);

        expect($answer['empty'])->toBeNull()
            ->and($answer['window'])->toMatchArray(['since' => $envelope['window']['since'], 'until' => $envelope['window']['until']]);
    }

    expect($first)->toBe($result['groups'][0])
        ->and(didMyChangeHelpFollow($envelope['next'][0])['result']['rows'])->toHaveCount($first['before_records'] + $first['after_records'])
        ->and(didMyChangeHelpFollow($envelope['next'][1])['result']['records'])->toBe($first['before_records'] + $first['after_records']);
});

it('evaluates nothing at the latest clock, where the after side is empty, and never says no regression', function () {
    didMyChangeHelpBefore();
    didMyChangeHelpAfter();
    $split = Envelope::assert(Overview::class)['now'];

    $envelope = Envelope::assert(Compare::class, ['type' => 'request', 'split_at' => $split]);

    expect($envelope['empty'])->toBeNull()
        ->and($envelope['result'])->toMatchArray(['change' => 'not_evaluated', 'reason' => 'empty_side', 'side' => 'after', 'rollup' => null, 'groups' => [], 'deploys' => []])
        ->and($envelope['result']['before']['records'])->toBe(10)
        ->and($envelope['result']['after']['records'])->toBe(0)
        ->and($envelope['summary'])->toBe(__('firewatch::messages.compare_empty_side_summary', ['side' => 'after', 'type' => 'request']))
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_not_evaluated_note')])
        ->and($envelope['next'])->toBe([]);
});

it('answers that no job ran on either side, with the blind spots that say why an absence is no proof', function () {
    didMyChangeHelpBefore();
    $split = Envelope::assert(Overview::class)['now'];
    didMyChangeHelpAfter();

    $envelope = Envelope::assert(Compare::class, ['type' => 'job-attempt', 'split_at' => $split]);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => $envelope['empty']['population'], 'filters' => 'type: job-attempt'])])
        ->and($envelope['result'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded', 'visible-at-completion');
});
