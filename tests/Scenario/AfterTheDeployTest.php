<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;

/**
 * Serve each block of requests in an application of its own per request, under the deploy identity the package sets, each request starting the gap of its block after the one before, from the offset after the store's creation.
 *
 * @param  list<array{string, int, int}>  $blocks  the uri, how many requests and the gap in seconds between their starts
 */
function afterTheDeployServe(float $created, string $deploy, float $offset, array $blocks): void
{
    foreach ($blocks as [$uri, $count, $gap]) {
        foreach (range(1, $count) as $ignored) {
            // Nightwatch reads a request's start once, when its provider registers, which makes the start, and so each observed span, the test's to set.
            $_SERVER['REQUEST_TIME_FLOAT'] = $created + $offset;
            setEnvironmentVariable(name: 'FIREWATCH_DEPLOY', value: $deploy);
            forceRequests();
            config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

            foreach (['/up', '/orders', '/invoices', '/stock', '/cart', '/legacy', '/orders/export'] as $route) {
                Route::get($route, fn () => 'ok');
            }

            test()->get($uri);
            $offset += $gap;
        }
    }
}

/**
 * Create the store two hours before the real clock, so that every request starts before it ends, and serve three deploys. Deploy v1 serves the request that creates the store a second before it, then 20 order lists, 20 invoice lists, 5 stock checks, 5 carts and 2 legacy reports, 10 seconds apart. Deploy v2 serves 22 order lists, 23 invoice lists, 6 stock checks, 7 carts and 2 exports that replace the legacy report, 10 seconds apart. Deploy v3 serves 22 order lists 100 seconds apart. Then set the store clock after all of them.
 *
 * @return float the instant the store was created
 */
function afterTheDeployServeAll(): float
{
    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    $created = floor(microtime(true)) - 7200;
    test()->travelTo(Date::createFromTimestamp($created));

    afterTheDeployServe($created, 'v1', -1, [['/up', 1, 0]]);
    afterTheDeployServe($created, 'v1', 10, [['/orders', 20, 10], ['/invoices', 20, 10], ['/stock', 5, 10], ['/cart', 5, 10], ['/legacy', 2, 10]]);
    afterTheDeployServe($created, 'v2', 1000, [['/orders', 22, 10], ['/invoices', 23, 10], ['/stock', 6, 10], ['/cart', 7, 10], ['/orders/export', 2, 10]]);
    afterTheDeployServe($created, 'v3', 2000, [['/orders', 22, 100]]);

    test()->travelTo(Date::createFromTimestamp($created + 5000));

    return $created;
}

/**
 * Get the group of each route, as `rank` lists them.
 *
 * @return array<string, string>
 */
function afterTheDeployGroups(): array
{
    $rows = Envelope::assert(Rank::class, ['type' => 'request', 'limit' => 10])['result']['groups'];

    return array_column($rows, 'group', 'label');
}

/**
 * Get the coverage of an answer over the request records the scenario serves.
 *
 * @return array<string, mixed>
 */
function afterTheDeployCoverage(float $created): array
{
    return [
        'state' => 'ok',
        'reason' => null,
        'oldest_at' => $created - 1,
        'newest_at' => $created + 4100,
        'records' => 135,
        'types_read' => ['request'],
        'history' => [
            'from' => $created,
            'reason' => 'created',
            'retention' => [
                'age_seconds' => 3153600000,
                'records' => 100000,
            ],
        ],
        'straddling' => null,
    ];
}

/**
 * Get the blind spots of an answer over request records, with the one a split adds.
 *
 * @return list<array{id: string, kind: string, message: string}>
 */
function afterTheDeployBlindSpots(bool $split = false): array
{
    $ids = ['console-requests', 'payload-on-server-error-only', 'dead-counters', 'memory-is-process-peak', ...($split ? ['visible-at-completion'] : []), 'octane-bootstrap'];

    return array_map(fn (string $id) => [
        'id' => $id,
        'kind' => 'structural',
        'message' => __("firewatch::messages.blind_spots.{$id}"),
    ], $ids);
}

/**
 * Get the row of a group compared by occurrences.
 *
 * @return array<string, mixed>
 */
function afterTheDeployRow(string $group, string $label, ?int $before, ?int $after, string $change, ?string $reason = null): array
{
    $difference = $before === null || $after === null ? null : $after - $before;

    return [
        'group' => $group,
        'label' => $label,
        'method' => 'GET',
        'before_records' => $before ?? 0,
        'after_records' => $after ?? 0,
        'before_occurrences' => $before,
        'after_occurrences' => $after,
        'difference_occurrences' => $difference,
        'change_pct' => $difference === null ? null : round($difference / $before * 100, 1),
        'change' => $change,
        'measured_on' => null,
        'reason' => $reason,
        'have' => null,
        'needed' => null,
    ];
}

/**
 * Get the rollup of a comparison by its counted changes.
 *
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function afterTheDeployRollup(int $groups, array $counts): array
{
    $tokens = ['slower', 'faster', 'heavier', 'lighter', 'more_calls', 'fewer_calls', 'steady', 'new', 'gone', 'zero_baseline', 'not_evaluated'];

    return [
        'groups' => $groups,
        ...array_merge(array_fill_keys($tokens, 0), $counts),
        'one_side_only' => ($counts['new'] ?? 0) + ($counts['gone'] ?? 0),
        'cut' => 0,
    ];
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

it('moves a count between two deploys only past both the 10% band and the noise floor, finds what the same question by time split finds, and every call it offers runs', function () {
    $created = afterTheDeployServeAll();
    $groups = afterTheDeployGroups();

    $pair = Envelope::assert(Compare::class, ['type' => 'request', 'by' => 'occurrences', 'deploy_before' => 'v1', 'deploy_after' => 'v2']);

    $rows = [
        afterTheDeployRow($groups['/cart'], '/cart', 5, 7, 'more_calls'),
        afterTheDeployRow($groups['/invoices'], '/invoices', 20, 23, 'more_calls'),
        afterTheDeployRow($groups['/orders/export'], '/orders/export', null, 2, 'new'),
        afterTheDeployRow($groups['/legacy'], '/legacy', 2, null, 'gone'),
        afterTheDeployRow($groups['/orders'], '/orders', 20, 22, 'steady'),
        afterTheDeployRow($groups['/stock'], '/stock', 5, 6, 'steady'),
    ];
    $rollup = afterTheDeployRollup(6, ['more_calls' => 2, 'steady' => 2, 'new' => 1, 'gone' => 1]);
    $window = ['since' => $created, 'until' => $created + 5000];

    expect($pair)->toEqual([
        'tool' => 'compare',
        'now' => $created + 5000,
        'window' => ['windowed' => true, 'basis' => 'started_at', ...$window, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => trans_choice('firewatch::messages.compare_pair_summary', 6, ['groups' => 6, 'type' => 'request', 'by' => 'occurrences', 'before' => 'v1', 'after' => 'v2', 'changes' => '2 more_calls, 2 steady, 1 new, 1 gone']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'occurrences',
            'change' => null,
            'reason' => null,
            'side' => null,
            'before' => ['deploy' => 'v1', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 52, 'observed_span_ms' => 510000.0, 'earlier_records' => 1, 'earlier_more' => false],
            'after' => ['deploy' => 'v2', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 60, 'observed_span_ms' => 590000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'rollup' => $rollup,
            'groups' => $rows,
            'deploys' => null,
        ],
        'coverage' => afterTheDeployCoverage($created),
        'blind_spots' => afterTheDeployBlindSpots(),
        'notes' => [
            trans_choice('firewatch::messages.compare_earlier_deploy_note', 1, ['count' => 1, 'type' => 'request', 'deploy' => 'v1']),
            __('firewatch::messages.compare_deploy_pair_note'),
        ],
        'truncated' => [],
        'next' => [
            ['tool' => 'occurrences', 'arguments' => ['group' => $groups['/cart'], 'deploy' => 'v1', ...$window], 'why' => __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => 'v1'])],
            ['tool' => 'occurrences', 'arguments' => ['group' => $groups['/cart'], 'deploy' => 'v2', ...$window], 'why' => __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => 'v2'])],
            ['tool' => 'rank', 'arguments' => ['group' => $groups['/cart'], ...$window], 'why' => __('firewatch::messages.compare_next_rank')],
        ],
    ]);

    $splitWindow = ['since' => $created, 'until' => $created + 1800];
    $bySplit = Envelope::assert(Compare::class, ['type' => 'request', 'by' => 'occurrences', 'split_at' => $created + 800, 'until' => $created + 1800]);

    expect(Arr::except($bySplit, 'coverage.straddling'))->toEqual([
        'tool' => 'compare',
        'now' => $created + 5000,
        'window' => ['windowed' => true, 'basis' => 'started_at', ...$splitWindow, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => trans_choice('firewatch::messages.compare_summary', 6, ['groups' => 6, 'type' => 'request', 'by' => 'occurrences', 'changes' => '2 more_calls, 2 steady, 1 new, 1 gone']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'occurrences',
            'change' => null,
            'reason' => null,
            'side' => null,
            'before' => ['since_at' => $created, 'until_at' => $created + 800, 'clipped' => false, 'records' => 52, 'observed_span_ms' => 510000.0, 'earlier_records' => 1, 'earlier_more' => false],
            'after' => ['since_at' => $created + 800, 'until_at' => $created + 1800, 'clipped' => false, 'records' => 60, 'observed_span_ms' => 590000.0],
            'rollup' => $rollup,
            'groups' => $rows,
            'deploys' => null,
        ],
        'coverage' => Arr::except(afterTheDeployCoverage($created), 'straddling'),
        'blind_spots' => afterTheDeployBlindSpots(split: true),
        'notes' => [trans_choice('firewatch::messages.compare_earlier_note', 1, ['count' => 1, 'type' => 'request'])],
        'truncated' => [],
        'next' => [
            ['tool' => 'occurrences', 'arguments' => ['group' => $groups['/cart'], ...$splitWindow], 'why' => __('firewatch::messages.compare_next_occurrences')],
            ['tool' => 'rank', 'arguments' => ['group' => $groups['/cart'], ...$splitWindow], 'why' => __('firewatch::messages.compare_next_rank')],
        ],
    ])
        // Each request ends on the real clock, after every start the test sets, so all of v1 straddles the split.
        ->and($bySplit['coverage']['straddling'])->toBe(52);

    [$before, $after, $breakdown] = array_map(afterTheDeployFollow(...), $pair['next']);

    expect([$before['empty'], $after['empty'], $breakdown['empty']])->toBe([null, null, null])
        ->and([$before['window'], $after['window'], $breakdown['window']])->each->toMatchArray($window)
        ->and(array_values(array_unique(array_column($before['result']['rows'], 'deploy'))))->toBe(['v1'])
        ->and($before['result']['rows'])->toHaveCount(5)
        ->and(array_values(array_unique(array_column($after['result']['rows'], 'deploy'))))->toBe(['v2'])
        ->and($after['result']['rows'])->toHaveCount(7)
        ->and($breakdown['result']['records'])->toBe(12)
        ->and(array_map(fn (array $deploy) => [$deploy['deploy'], $deploy['occurrences']], $breakdown['result']['deploys']))->toBe([['v1', 5], ['v2', 7]]);
});

it('judges no count between deploys whose observed spans differ more than twofold', function () {
    $created = afterTheDeployServeAll();
    $groups = afterTheDeployGroups();

    $envelope = Envelope::assert(Compare::class, ['group' => $groups['/orders'], 'by' => 'occurrences', 'deploy_before' => 'v2', 'deploy_after' => 'v3']);

    expect($envelope['summary'])->toBe(trans_choice('firewatch::messages.compare_pair_summary', 1, ['groups' => 1, 'type' => 'request', 'by' => 'occurrences', 'before' => 'v2', 'after' => 'v3', 'changes' => '1 not_evaluated']))
        ->and($envelope['result'])->toEqual([
            'type' => 'request',
            'by' => 'occurrences',
            'change' => null,
            'reason' => null,
            'side' => null,
            'before' => ['deploy' => 'v2', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 22, 'observed_span_ms' => 210000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'after' => ['deploy' => 'v3', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 22, 'observed_span_ms' => 2100000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'rollup' => afterTheDeployRollup(1, ['not_evaluated' => 1]),
            'groups' => [afterTheDeployRow($groups['/orders'], '/orders', 22, 22, 'not_evaluated', reason: 'unequal_spans')],
            'deploys' => null,
        ])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_deploy_pair_note')]);
});

it('evaluates nothing for a deploy that served nothing, lists the deploys that did, and never says no regression', function () {
    $created = afterTheDeployServeAll();

    $envelope = Envelope::assert(Compare::class, ['type' => 'request', 'by' => 'occurrences', 'deploy_before' => 'v2', 'deploy_after' => 'v9']);

    expect($envelope)->toEqual([
        'tool' => 'compare',
        'now' => $created + 5000,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => $created, 'until' => $created + 5000, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => __('firewatch::messages.compare_empty_deploy_summary', ['deploy' => 'v9', 'side' => 'after', 'type' => 'request']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'occurrences',
            'change' => 'not_evaluated',
            'reason' => 'empty_side',
            'side' => 'after',
            'before' => ['deploy' => 'v2', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 60, 'observed_span_ms' => 590000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'after' => ['deploy' => 'v9', 'since_at' => $created, 'until_at' => $created + 5000, 'clipped' => false, 'records' => 0, 'observed_span_ms' => null, 'earlier_records' => 0, 'earlier_more' => false],
            'rollup' => null,
            'groups' => [],
            'deploys' => [
                ['deploy' => 'v1', 'records' => 52, 'first_at' => $created + 10],
                ['deploy' => 'v2', 'records' => 60, 'first_at' => $created + 1000],
                ['deploy' => 'v3', 'records' => 22, 'first_at' => $created + 2000],
            ],
        ],
        'coverage' => afterTheDeployCoverage($created),
        'blind_spots' => afterTheDeployBlindSpots(),
        'notes' => [__('firewatch::messages.compare_pair_not_evaluated_note'), __('firewatch::messages.compare_deploy_pair_note')],
        'truncated' => [],
        'next' => [],
    ]);
});

it('answers that no job ran under either deploy, with the blind spots that say why an absence is no proof', function () {
    afterTheDeployServeAll();

    $envelope = Envelope::assert(Compare::class, ['type' => 'job-attempt', 'deploy_before' => 'v1', 'deploy_after' => 'v2']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 134, 'filters' => 'type: job-attempt, deploy_before: v1, deploy_after: v2'])])
        ->and($envelope['result'])->toBe([])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_deploy_pair_note')])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded');
});
