<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const PAIR_CREATED = 1790768800.0;

const PAIR_START = PAIR_CREATED + 3600;

const PAIR_NOW = PAIR_CREATED + 10800;

function pairHash(string $letter): string
{
    return str_repeat($letter, 32);
}

/**
 * Make one request of the group per given duration in milliseconds, under the deploy or none, the step in seconds apart from the offset after the first record's start.
 *
 * @param  list<int>  $milliseconds
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function pairRecords(string $letter, ?string $deploy, float $offset, array $milliseconds, float $step = 1, array $fields = []): array
{
    return array_map(function (int $index, int $duration) use ($letter, $deploy, $offset, $step, $fields) {
        $record = syntheticRecord(RecordType::REQUEST)->with([
            '_group' => pairHash($letter),
            'duration' => $duration * 1000,
            'timestamp' => PAIR_START + $offset + $index * $step,
            ...$fields,
        ]);

        return $deploy === null ? $record->without('deploy') : $record->deploy($deploy);
    }, array_keys($milliseconds), $milliseconds);
}

/**
 * Store the records in a store created an hour before the first of them, then set the clock two hours after it.
 *
 * @param  list<RecordBuilder>  $records
 */
function pairIngest(array $records): void
{
    test()->travelTo(Date::createFromTimestamp(PAIR_CREATED));
    ingest($records);
    test()->travelTo(Date::createFromTimestamp(PAIR_NOW));
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function pairAnswer(array $arguments = []): array
{
    return Envelope::assert(Compare::class, ['type' => 'request', 'deploy_before' => 'v1', 'deploy_after' => 'v2', ...$arguments]);
}

/**
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function pairRollup(int $groups, array $counts = [], int $cut = 0): array
{
    $tokens = ['slower', 'faster', 'heavier', 'lighter', 'more_calls', 'fewer_calls', 'steady', 'new', 'gone', 'zero_baseline', 'not_evaluated'];

    return [
        'groups' => $groups,
        ...array_merge(array_fill_keys($tokens, 0), $counts),
        'one_side_only' => ($counts['new'] ?? 0) + ($counts['gone'] ?? 0),
        'cut' => $cut,
    ];
}

it('compares every record of each deploy in the window, interleaved in time, and leaves out the records with no deploy', function () {
    pairIngest([
        ...pairRecords('a', 'v1', 0, array_fill(0, 20, 100), step: 2, fields: ['route_path' => '/orders', 'method' => 'GET']),
        ...pairRecords('a', 'v2', 1, array_fill(0, 20, 150), step: 2, fields: ['route_path' => '/orders', 'method' => 'GET']),
        ...pairRecords('a', null, 5, array_fill(0, 3, 900), fields: ['route_path' => '/orders', 'method' => 'GET']),
        ...pairRecords('a', '', 15, array_fill(0, 3, 900), fields: ['route_path' => '/orders', 'method' => 'GET']),
        ...pairRecords('a', 'v2', PAIR_NOW - PAIR_START, [900, 900]),
    ]);

    $envelope = pairAnswer();

    expect($envelope)->toEqual([
        'tool' => 'compare',
        'now' => PAIR_NOW,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => PAIR_CREATED, 'until' => PAIR_NOW, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => trans_choice('firewatch::messages.compare_pair_summary', 1, ['groups' => 1, 'type' => 'request', 'by' => 'p95_duration', 'before' => 'v1', 'after' => 'v2', 'changes' => '1 slower']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'p95_duration',
            'change' => null,
            'reason' => null,
            'side' => null,
            'before' => ['deploy' => 'v1', 'since_at' => PAIR_CREATED, 'until_at' => PAIR_NOW, 'clipped' => false, 'records' => 20, 'observed_span_ms' => 38000.0],
            'after' => ['deploy' => 'v2', 'since_at' => PAIR_CREATED, 'until_at' => PAIR_NOW, 'clipped' => false, 'records' => 20, 'observed_span_ms' => 38000.0],
            'rollup' => pairRollup(1, ['slower' => 1]),
            'groups' => [
                [
                    'group' => pairHash('a'),
                    'label' => '/orders',
                    'method' => 'GET',
                    'before_records' => 20,
                    'after_records' => 20,
                    'before_ms' => 100.0,
                    'after_ms' => 150.0,
                    'difference_ms' => 50.0,
                    'change_pct' => 50.0,
                    'change' => 'slower',
                    'measured_on' => null,
                    'reason' => null,
                    'have' => null,
                    'needed' => null,
                ],
            ],
            'deploys' => null,
        ],
        'coverage' => [
            ...$envelope['coverage'],
            'straddling' => null,
        ],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.compare_deploy_pair_note')],
        'truncated' => [],
        'next' => [
            ['tool' => 'occurrences', 'arguments' => ['group' => pairHash('a'), 'deploy' => 'v1', 'since' => PAIR_CREATED, 'until' => PAIR_NOW], 'why' => __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => 'v1'])],
            ['tool' => 'occurrences', 'arguments' => ['group' => pairHash('a'), 'deploy' => 'v2', 'since' => PAIR_CREATED, 'until' => PAIR_NOW], 'why' => __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => 'v2'])],
            ['tool' => 'rank', 'arguments' => ['group' => pairHash('a'), 'since' => PAIR_CREATED, 'until' => PAIR_NOW], 'why' => __('firewatch::messages.compare_next_rank')],
        ],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 48, 'types_read' => ['request']])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('console-requests')
        ->and(array_column($envelope['blind_spots'], 'id'))->not->toContain('visible-at-completion');
});

it('refuses a boundary that is not exactly one of the two forms, naming what it accepts', function (array $arguments, string $key, string $argument, string $accepted, string $example, ?string $with = null, ?string $expected = null, ?string $value = null) {
    pairIngest(pairRecords('a', 'v1', 0, [100, 100, 100]));

    $response = FirewatchServer::tool(Compare::class, ['type' => 'request', ...$arguments]);

    $response->assertHasErrors([__("firewatch::messages.{$key}", ['argument' => $argument, 'with' => $with, 'expected' => $expected, 'value' => $value, 'accepted' => $accepted, 'example' => $example])]);
})->with(function () {
    $boundary = 'exactly one boundary: `split_at` (a time, such as the now of an earlier answer), or `deploy_before` with `deploy_after` (exact deploy strings)';
    $splitExample = 'compare(type: "request", split_at: "<now of an earlier answer>")';
    $pairExample = 'compare(type: "request", deploy_before: "<deploy>", deploy_after: "<another deploy>")';
    $deploy = 'an exact deploy string, which is not empty';

    return [
        'neither form' => [[], 'missing_argument', 'split_at', $boundary, $splitExample],
        'deploy_before alone' => [['deploy_before' => 'v1'], 'missing_argument', 'deploy_after', 'an exact deploy string, with deploy_before', $pairExample],
        'deploy_after alone' => [['deploy_after' => 'v2'], 'missing_argument', 'deploy_before', 'an exact deploy string, with deploy_after', $pairExample],
        'split_at with deploy_before' => [['split_at' => PAIR_START, 'deploy_before' => 'v1'], 'conflicting_arguments', 'deploy_before', $boundary, $pairExample, 'split_at'],
        'split_at with deploy_after' => [['split_at' => PAIR_START, 'deploy_after' => 'v2'], 'conflicting_arguments', 'deploy_after', $boundary, $pairExample, 'split_at'],
        'split_at with the pair' => [['split_at' => PAIR_START, 'deploy_before' => 'v1', 'deploy_after' => 'v2'], 'conflicting_arguments', 'deploy_before', $boundary, $pairExample, 'split_at'],
        'the same deploy on both sides' => [['deploy_before' => 'v1', 'deploy_after' => 'v1'], 'invalid_argument', 'deploy_after', 'a deploy other than deploy_before', $pairExample, null, 'a deploy other than deploy_before', '"v1"'],
        'an empty deploy_before' => [['deploy_before' => '', 'deploy_after' => 'v2'], 'invalid_argument', 'deploy_before', $deploy, $pairExample, null, 'an exact deploy string', '""'],
        'an empty deploy_after' => [['deploy_before' => 'v1', 'deploy_after' => ''], 'invalid_argument', 'deploy_after', $deploy, $pairExample, null, 'an exact deploy string', '""'],
        'a deploy that is no string' => [['deploy_before' => 1, 'deploy_after' => 'v2'], 'invalid_argument', 'deploy_before', $deploy, $pairExample, null, 'an exact deploy string', '1'],
    ];
});

it('matches a deploy exactly, so a deploy that differs only in case is on neither side', function () {
    pairIngest([
        ...pairRecords('a', 'v1', 0, [100, 100, 100]),
        ...pairRecords('a', 'V1', 10, [500, 500, 500]),
        ...pairRecords('a', 'v2', 20, [100, 100, 100]),
    ]);

    $result = pairAnswer(['by' => 'p50_duration'])['result'];

    expect($result['before']['records'])->toBe(3)
        ->and($result['after']['records'])->toBe(3)
        ->and($result['groups'][0])->toMatchArray(['before_ms' => 100.0, 'after_ms' => 100.0, 'change' => 'steady']);
});

it('moves a value between the deploys only past both the 10% band and the noise floor', function (string $by, int $before, int $after, int $beforeCount, int $afterCount, string $change) {
    pairIngest([
        ...pairRecords('a', 'v1', 0, array_fill(0, $beforeCount, 10), fields: ['duration' => $before]),
        ...pairRecords('a', 'v2', 600, array_fill(0, $afterCount, 10), fields: ['duration' => $after]),
    ]);

    $row = pairAnswer(['by' => $by])['result']['groups'][0];

    expect($row['change'])->toBe($change)
        ->and($row['reason'])->toBeNull();
})->with([
    'a duration up by exactly 10%' => ['p50_duration', 100000, 110000, 3, 3, 'steady'],
    'a duration up by just over 10%' => ['p50_duration', 100000, 110001, 3, 3, 'slower'],
    'a duration down by just over 10%' => ['p50_duration', 100000, 89999, 3, 3, 'faster'],
    'a duration up by exactly the 1 ms floor' => ['p50_duration', 5000, 6000, 3, 3, 'steady'],
    'a duration up by just over the 1 ms floor' => ['p50_duration', 5000, 6001, 3, 3, 'slower'],
    'a count up by exactly 10%' => ['occurrences', 10000, 10000, 10, 11, 'steady'],
    'a count up past 10% and the floor' => ['occurrences', 10000, 10000, 10, 12, 'more_calls'],
    'a count up by exactly the floor' => ['occurrences', 10000, 10000, 5, 6, 'steady'],
    'a count up by just over the floor' => ['occurrences', 10000, 10000, 5, 7, 'more_calls'],
]);

it('steps the 95th percentile down to the median when a deploy has fewer than 20 records, and evaluates nothing below 3', function (int $before, int $after, array $expected) {
    pairIngest([
        ...pairRecords('a', 'v1', 0, array_fill(0, $before, 10)),
        ...pairRecords('a', 'v2', 600, array_fill(0, $after, 30)),
    ]);

    $row = pairAnswer()['result']['groups'][0];

    expect(array_intersect_key($row, $expected))->toEqual($expected);
})->with([
    '20 records a deploy' => [20, 20, ['change' => 'slower', 'measured_on' => null, 'reason' => null]],
    '19 records before' => [19, 20, ['change' => 'slower', 'measured_on' => 'p50', 'reason' => null]],
    '2 records after' => [20, 2, ['change' => 'not_evaluated', 'measured_on' => null, 'reason' => 'sample_too_small', 'have' => 2, 'needed' => 3]],
]);

it('judges a volume measure only when the longer observed span of the deploys is at most twice the shorter', function (int $afterSeconds, ?string $reason) {
    pairIngest([
        ...pairRecords('a', 'v1', 0, [10, 10], step: 10),
        ...pairRecords('a', 'v2', 5, [10, 10], step: $afterSeconds),
    ]);

    $result = pairAnswer(['by' => 'occurrences'])['result'];

    expect($result['before']['observed_span_ms'])->toEqual(10000.0)
        ->and($result['after']['observed_span_ms'])->toEqual($afterSeconds * 1000.0)
        ->and($result['groups'][0])->toMatchArray(['change' => $reason === null ? 'steady' : 'not_evaluated', 'reason' => $reason]);
})->with([
    'a ratio of exactly 2' => [20, null],
    'past a ratio of 2' => [21, 'unequal_spans'],
]);

it('evaluates nothing when a deploy has no records in the window, says which, and lists the deploys of the window by their first record', function (string $before, string $after, string $side, string $missing) {
    pairIngest([
        ...pairRecords('a', 'v0', -60, [100]),
        ...pairRecords('a', 'v1', 0, [100, 100, 100]),
        ...pairRecords('b', 'v3', 30, [100, 100]),
        ...pairRecords('b', null, 40, [100]),
    ]);

    $envelope = pairAnswer(['deploy_before' => $before, 'deploy_after' => $after]);
    $present = $side === 'after' ? $before : $after;

    expect($envelope)->toEqual([
        'tool' => 'compare',
        'now' => PAIR_NOW,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => PAIR_CREATED, 'until' => PAIR_NOW, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => __('firewatch::messages.compare_empty_deploy_summary', ['deploy' => $missing, 'side' => $side, 'type' => 'request']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'p95_duration',
            'change' => 'not_evaluated',
            'reason' => 'empty_side',
            'side' => $side,
            'before' => ['deploy' => $before, 'since_at' => PAIR_CREATED, 'until_at' => PAIR_NOW, 'clipped' => false, 'records' => $side === 'before' ? 0 : 3, 'observed_span_ms' => $side === 'before' ? null : 2000.0],
            'after' => ['deploy' => $after, 'since_at' => PAIR_CREATED, 'until_at' => PAIR_NOW, 'clipped' => false, 'records' => $side === 'after' ? 0 : 3, 'observed_span_ms' => $side === 'after' ? null : 2000.0],
            'rollup' => null,
            'groups' => [],
            'deploys' => [
                ['deploy' => 'v0', 'records' => 1, 'first_at' => PAIR_START - 60],
                ['deploy' => 'v1', 'records' => 3, 'first_at' => PAIR_START],
                ['deploy' => 'v3', 'records' => 2, 'first_at' => PAIR_START + 30],
            ],
        ],
        'coverage' => [
            ...$envelope['coverage'],
            'straddling' => null,
        ],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.compare_pair_not_evaluated_note'), __('firewatch::messages.compare_deploy_pair_note')],
        'truncated' => [],
        'next' => [],
    ])
        ->and($present)->toBe('v1')
        ->and($envelope['summary'])->toContain('"no regression"');
})->with([
    'the after deploy' => ['v1', 'v9', 'after', 'v9'],
    'the before deploy' => ['v9', 'v1', 'before', 'v9'],
]);

it('lists at most ten deploys beside an empty deploy, and says so when the window holds more', function (int $deploys, array $truncated) {
    pairIngest(array_merge(...array_map(fn (int $index) => pairRecords('a', sprintf('v%02d', $index), $index, [100]), range(1, $deploys))));

    $envelope = pairAnswer(['deploy_before' => 'v01', 'deploy_after' => 'v99']);

    expect($envelope['result'])->toMatchArray(['reason' => 'empty_side', 'side' => 'after'])
        ->and(array_column($envelope['result']['deploys'], 'deploy'))->toBe(['v01', 'v02', 'v03', 'v04', 'v05', 'v06', 'v07', 'v08', 'v09', 'v10'])
        ->and($envelope['truncated'])->toBe($truncated);
})->with([
    'ten deploys' => [10, []],
    'eleven deploys' => fn () => [11, [['section' => 'deploys', 'shown' => 10, 'matched' => null, 'reason' => 'limit', 'how' => __('firewatch::messages.compare_deploys_truncated_how')]]],
]);

it('says a deploy pair cannot separate an uncommitted edit on every pair answer, empty or not, and on no time-split answer', function () {
    $this->travelTo(Date::createFromTimestamp(PAIR_CREATED));
    $noStore = pairAnswer();

    app(Writer::class)->transaction(fn () => null);
    $storeEmpty = pairAnswer(['since' => PAIR_CREATED, 'until' => PAIR_NOW]);

    pairIngest([
        ...pairRecords('a', 'v1', 0, [100, 100, 100]),
        ...pairRecords('a', 'v2', 600, [100, 100, 100]),
    ]);

    $evaluated = pairAnswer();
    $emptySide = pairAnswer(['deploy_after' => 'v9']);
    $windowEmpty = pairAnswer(['since' => PAIR_START + 100, 'until' => PAIR_START + 200]);
    $noMatch = pairAnswer(['deploy_before' => 'v8', 'deploy_after' => 'v9']);
    $noGroup = Envelope::assert(Compare::class, ['group' => pairHash('f'), 'deploy_before' => 'v1', 'deploy_after' => 'v2']);
    $split = Envelope::assert(Compare::class, ['type' => 'request', 'split_at' => PAIR_START + 300]);
    $splitEmpty = Envelope::assert(Compare::class, ['type' => 'query', 'split_at' => PAIR_START + 300]);

    $sentence = __('firewatch::messages.compare_deploy_pair_note');

    expect($noStore['empty']['kind'])->toBe('no_store')
        ->and($storeEmpty['empty']['kind'])->toBe('store_empty')
        ->and($evaluated['empty'])->toBeNull()
        ->and($emptySide['result']['reason'])->toBe('empty_side')
        ->and($windowEmpty['empty']['kind'])->toBe('window_empty')
        ->and($noMatch['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 6, 'filters' => 'type: request, deploy_before: v8, deploy_after: v9'])])
        ->and($noGroup['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 6, 'filters' => 'group: '.pairHash('f').', deploy_before: v1, deploy_after: v2'])])
        ->and($split['empty'])->toBeNull()
        ->and($splitEmpty['empty']['kind'])->toBe('no_match');

    foreach ([$noStore, $storeEmpty, $evaluated, $emptySide, $windowEmpty, $noMatch, $noGroup] as $envelope) {
        expect($envelope['notes'])->toContain($sentence)
            ->and($envelope['coverage']['straddling'])->toBeNull()
            ->and(array_column($envelope['blind_spots'], 'id'))->not->toContain('visible-at-completion');
    }

    foreach ([$split, $splitEmpty] as $envelope) {
        expect($envelope['notes'])->not->toContain($sentence)
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion');
    }
});

it('offers the records of each deploy and the deploy breakdown of the first group, over the same window when the clock moves on, and every call runs', function () {
    pairIngest([
        ...pairRecords('a', 'v1', 0, [100, 100, 100]),
        ...pairRecords('a', 'v2', 600, [110, 110, 110]),
        ...pairRecords('b', 'v1', 0, [100, 100, 100]),
        ...pairRecords('b', 'v2', 600, [400, 400, 400, 400]),
        ...pairRecords('b', 'v0', 300, [100]),
    ]);

    $envelope = pairAnswer(['by' => 'p50_duration']);
    $this->travelTo(Date::createFromTimestamp(PAIR_NOW + 7200));
    ingest(pairRecords('b', 'v2', PAIR_NOW - PAIR_START + 600, [900, 900, 900]));

    $window = ['since' => PAIR_CREATED, 'until' => PAIR_NOW];
    $before = Envelope::assert(Occurrences::class, $envelope['next'][0]['arguments']);
    $after = Envelope::assert(Occurrences::class, $envelope['next'][1]['arguments']);
    $breakdown = Envelope::assert(Rank::class, $envelope['next'][2]['arguments']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['occurrences', 'occurrences', 'rank'])
        ->and(array_column($envelope['next'], 'arguments'))->toEqual([
            ['group' => pairHash('b'), 'deploy' => 'v1', ...$window],
            ['group' => pairHash('b'), 'deploy' => 'v2', ...$window],
            ['group' => pairHash('b'), ...$window],
        ])
        ->and($envelope['result']['groups'][0])->toMatchArray(['group' => pairHash('b'), 'before_records' => 3, 'after_records' => 4])
        ->and($before['empty'])->toBeNull()
        ->and($before['window'])->toMatchArray($window)
        ->and($before['result']['rows'])->toHaveCount(3)
        ->and($after['result']['rows'])->toHaveCount(4)
        ->and($breakdown['empty'])->toBeNull()
        ->and($breakdown['result']['records'])->toBe(8)
        ->and(array_column($breakdown['result']['deploys'], 'deploy'))->toBe(['v1', 'v0', 'v2']);
});

it('reads an omitted since as the coverage start of the type and an omitted until as the store clock, and clips an earlier since', function () {
    pairIngest([
        ...pairRecords('a', 'v1', 0, [100, 100, 100]),
        ...pairRecords('a', 'v2', 600, [100, 100, 100]),
    ]);

    $omitted = pairAnswer(['by' => 'p50_duration']);
    $earlier = pairAnswer(['by' => 'p50_duration', 'since' => PAIR_CREATED - 600]);

    expect($omitted['window'])->toMatchArray(['since' => PAIR_CREATED, 'until' => PAIR_NOW])
        ->and($omitted['result']['before'])->toMatchArray(['since_at' => PAIR_CREATED, 'until_at' => PAIR_NOW, 'clipped' => false])
        ->and($earlier['window'])->toMatchArray(['since' => PAIR_CREATED - 600, 'until' => PAIR_NOW])
        ->and($earlier['result']['before'])->toMatchArray(['since_at' => PAIR_CREATED, 'clipped' => true, 'records' => 3])
        ->and($earlier['result']['after'])->toMatchArray(['since_at' => PAIR_CREATED, 'clipped' => true, 'records' => 3])
        ->and($earlier['notes'])->toBe([__('firewatch::messages.compare_deploy_pair_note')]);
});
