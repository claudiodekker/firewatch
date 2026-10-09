<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Serve each request in an application of its own that started when the request did, under the deploy identity the package sets, with the routes of that deploy.
 *
 * @param  list<string>  $uris
 */
function afterTheDeployServe(string $deploy, array $uris): void
{
    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach ($uris as $uri) {
        // Nightwatch reads a request's start once, when its provider registers.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        setEnvironmentVariable(name: 'FIREWATCH_DEPLOY', value: $deploy);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/orders', function () {
            DB::select('select 1');

            return 'ok';
        });
        Route::get($deploy === 'v2' ? '/orders/export' : '/legacy', fn () => 'ok');
        Route::get('/up', fn () => 'ok');

        test()->get($uri);
    }
}

/**
 * Serve deploy v1: the request that creates the store, then the order list three times and the legacy report twice; then deploy v2: the order list three times and the export that replaced the legacy report twice.
 */
function afterTheDeployServeBoth(): void
{
    afterTheDeployServe(deploy: 'v1', uris: ['/up', '/orders', '/orders', '/orders', '/legacy', '/legacy']);
    afterTheDeployServe(deploy: 'v2', uris: ['/orders', '/orders', '/orders', '/orders/export', '/orders/export']);
}

/**
 * Run a call an answer offers and get what it answers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function afterTheDeployFollow(array $call): array
{
    $tool = ['occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

    return Envelope::assert($tool, $call['arguments']);
}

it('compares the routes of two deploys, finds what the same question by time split finds, and every call it offers runs', function () {
    afterTheDeployServe(deploy: 'v1', uris: ['/up', '/orders', '/orders', '/orders', '/legacy', '/legacy']);
    $split = Envelope::assert(Overview::class)['now'];
    afterTheDeployServe(deploy: 'v2', uris: ['/orders', '/orders', '/orders', '/orders/export', '/orders/export']);

    $pair = Envelope::assert(Compare::class, ['type' => 'request', 'deploy_before' => 'v1', 'deploy_after' => 'v2']);
    $bySplit = Envelope::assert(Compare::class, ['type' => 'request', 'split_at' => $split, 'until' => $pair['now']]);
    $result = $pair['result'];
    $rows = collect($result['groups'])->keyBy('label');

    expect($result)->toMatchArray(['type' => 'request', 'by' => 'p95_duration', 'change' => null, 'reason' => null, 'side' => null, 'deploys' => null])
        ->and($result['before'])->toMatchArray(['deploy' => 'v1', 'since_at' => $pair['window']['since'], 'until_at' => $pair['now'], 'clipped' => false, 'records' => 5])
        ->and($result['after'])->toMatchArray(['deploy' => 'v2', 'since_at' => $pair['window']['since'], 'until_at' => $pair['now'], 'clipped' => false, 'records' => 5])
        ->and($result['before'])->toMatchArray(['earlier_records' => 1, 'earlier_more' => false])
        ->and($result['after'])->toMatchArray(['earlier_records' => 0, 'earlier_more' => false])
        ->and($result['rollup'])->toMatchArray(['groups' => 3, 'new' => 1, 'gone' => 1, 'one_side_only' => 2, 'cut' => 0])
        ->and(array_keys($rows->all()))->toEqualCanonicalizing(['/orders', '/orders/export', '/legacy'])
        ->and($rows['/orders/export'])->toMatchArray(['before_records' => 0, 'after_records' => 2, 'change' => 'new'])
        ->and($rows['/legacy'])->toMatchArray(['before_records' => 2, 'after_records' => 0, 'change' => 'gone'])
        ->and($rows['/orders'])->toMatchArray(['before_records' => 3, 'after_records' => 3, 'measured_on' => 'p50', 'reason' => null])
        ->and($rows['/orders']['change'])->toBeIn(['slower', 'faster', 'steady'])
        ->and($pair['summary'])->toStartWith(explode(':changes', trans_choice('firewatch::messages.compare_pair_summary', 3, ['groups' => 3, 'type' => 'request', 'by' => 'p95_duration', 'before' => 'v1', 'after' => 'v2']))[0])
        ->and($pair['coverage']['straddling'])->toBeNull()
        ->and(array_column($pair['blind_spots'], 'id'))->toContain('console-requests')
        ->and(array_column($pair['blind_spots'], 'id'))->not->toContain('visible-at-completion')
        ->and($pair['notes'])->toBe([trans_choice('firewatch::messages.compare_earlier_deploy_note', 1, ['count' => 1, 'type' => 'request', 'deploy' => 'v1']), __('firewatch::messages.compare_deploy_pair_note')]);

    expect($bySplit['result']['groups'])->toBe($result['groups'])
        ->and($bySplit['result']['rollup'])->toBe($result['rollup'])
        ->and($bySplit['result']['before'])->toMatchArray(['records' => 5, 'earlier_records' => 1])
        ->and($bySplit['coverage']['straddling'])->toBe(0)
        ->and($bySplit['notes'])->not->toContain(__('firewatch::messages.compare_deploy_pair_note'));

    $first = $result['groups'][0];
    $held = array_values(array_filter([['v1', $first['before_records']], ['v2', $first['after_records']]], fn (array $side) => $side[1] > 0));
    $answers = array_map(afterTheDeployFollow(...), $pair['next']);
    $breakdown = array_pop($answers);

    expect(array_column($pair['next'], 'tool'))->toBe([...array_fill(0, count($held), 'occurrences'), 'rank'])
        ->and(array_column(array_column($pair['next'], 'arguments'), 'group'))->each->toBe($first['group'])
        ->and(array_map(fn (array $answer) => [$answer['result']['rows'][0]['deploy'], count($answer['result']['rows'])], $answers))->toBe($held)
        ->and($breakdown['result']['records'])->toBe($first['before_records'] + $first['after_records']);

    foreach ([...$answers, $breakdown] as $answer) {
        expect($answer['empty'])->toBeNull()
            ->and($answer['window'])->toMatchArray(['since' => $pair['window']['since'], 'until' => $pair['window']['until']]);
    }
});

it('evaluates nothing for a deploy that served nothing, lists the deploys that did, and never says no regression', function () {
    afterTheDeployServeBoth();

    $envelope = Envelope::assert(Compare::class, ['type' => 'request', 'deploy_before' => 'v2', 'deploy_after' => 'v3']);

    expect($envelope['empty'])->toBeNull()
        ->and($envelope['result'])->toMatchArray(['change' => 'not_evaluated', 'reason' => 'empty_side', 'side' => 'after', 'rollup' => null, 'groups' => []])
        ->and($envelope['result']['before'])->toMatchArray(['deploy' => 'v2', 'records' => 5])
        ->and($envelope['result']['after'])->toMatchArray(['deploy' => 'v3', 'records' => 0, 'observed_span_ms' => null])
        ->and(array_map(fn (array $deploy) => [$deploy['deploy'], $deploy['records']], $envelope['result']['deploys']))->toBe([['v1', 5], ['v2', 5]])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.compare_empty_deploy_summary', ['deploy' => 'v3', 'side' => 'after', 'type' => 'request']))
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_pair_not_evaluated_note'), __('firewatch::messages.compare_deploy_pair_note')])
        ->and($envelope['next'])->toBe([]);
});

it('answers that no job ran under either deploy, with the blind spots that say why an absence is no proof', function () {
    afterTheDeployServeBoth();

    $envelope = Envelope::assert(Compare::class, ['type' => 'job-attempt', 'deploy_before' => 'v1', 'deploy_after' => 'v2']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => $envelope['empty']['population'], 'filters' => 'type: job-attempt, deploy_before: v1, deploy_after: v2'])])
        ->and($envelope['result'])->toBe([])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_deploy_pair_note')])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded');
});
