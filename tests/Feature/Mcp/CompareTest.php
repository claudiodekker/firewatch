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
use Laravel\Mcp\Server\Transport\FakeTransporter;

const COMPARE_SPLIT = 1790776000.0;

const COMPARE_CREATED = COMPARE_SPLIT - 7200;

const COMPARE_NOW = COMPARE_SPLIT + 3600;

function compareHash(string $letter): string
{
    return str_repeat($letter, 32);
}

/**
 * @param  array<string, mixed>  $fields
 */
function compareRecord(RecordType $type, string $letter, float $at, ?int $microseconds, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->with([
        '_group' => compareHash($letter),
        'duration' => $microseconds,
        'timestamp' => $at,
        ...$fields,
    ]);
}

/**
 * Make one record per given duration in milliseconds, a second apart, starting at the offset from the split.
 *
 * @param  list<int>  $milliseconds
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function compareRecords(RecordType $type, string $letter, float $offset, array $milliseconds, array $fields = []): array
{
    return array_map(fn (int $index, int $duration) => compareRecord($type, $letter, COMPARE_SPLIT + $offset + $index, $duration * 1000, $fields), array_keys($milliseconds), $milliseconds);
}

/**
 * Store the records in a store created two hours before the split, then set the clock an hour after it.
 *
 * @param  list<RecordBuilder>  $records
 */
function compareIngest(array $records): void
{
    test()->travelTo(Date::createFromTimestamp(COMPARE_CREATED));
    ingest($records);
    test()->travelTo(Date::createFromTimestamp(COMPARE_NOW));
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function compareAnswer(array $arguments = []): array
{
    return Envelope::assert(Compare::class, ['type' => 'request', 'split_at' => COMPARE_SPLIT, ...$arguments]);
}

/**
 * Get the rollup of a comparison: every change counted, with the groups on one side only and the groups cut.
 *
 * @param  array<string, int>  $counts
 * @return array<string, int>
 */
function compareRollup(int $groups, array $counts = [], int $cut = 0): array
{
    $tokens = ['slower', 'faster', 'heavier', 'lighter', 'more_calls', 'fewer_calls', 'steady', 'new', 'gone', 'zero_baseline', 'not_evaluated'];

    return [
        'groups' => $groups,
        ...array_merge(array_fill_keys($tokens, 0), $counts),
        'one_side_only' => ($counts['new'] ?? 0) + ($counts['gone'] ?? 0),
        'cut' => $cut,
    ];
}

it('compares one group before and after the split by its 95th percentile and says it got slower', function () {
    compareIngest([
        ...compareRecords(RecordType::REQUEST, 'a', -600, array_fill(0, 20, 100), ['route_path' => '/orders', 'method' => 'GET']),
        ...compareRecords(RecordType::REQUEST, 'a', 600, array_fill(0, 20, 150), ['route_path' => '/orders', 'method' => 'GET']),
    ]);

    $envelope = compareAnswer();

    expect($envelope)->toEqual([
        'tool' => 'compare',
        'now' => COMPARE_NOW,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => trans_choice('firewatch::messages.compare_summary', 1, ['groups' => 1, 'type' => 'request', 'by' => 'p95_duration', 'changes' => '1 slower']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'p95_duration',
            'change' => null,
            'reason' => null,
            'side' => null,
            'before' => ['since_at' => COMPARE_CREATED, 'until_at' => COMPARE_SPLIT, 'clipped' => false, 'records' => 20, 'observed_span_ms' => 19000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'after' => ['since_at' => COMPARE_SPLIT, 'until_at' => COMPARE_NOW, 'clipped' => false, 'records' => 20, 'observed_span_ms' => 19000.0],
            'rollup' => compareRollup(1, ['slower' => 1]),
            'groups' => [
                [
                    'group' => compareHash('a'),
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
            'straddling' => 0,
        ],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [],
        'truncated' => [],
        'next' => [
            ['tool' => 'occurrences', 'arguments' => ['group' => compareHash('a'), 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW], 'why' => __('firewatch::messages.compare_next_occurrences')],
            ['tool' => 'rank', 'arguments' => ['group' => compareHash('a'), 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW], 'why' => __('firewatch::messages.compare_next_rank')],
        ],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 40, 'types_read' => ['request']])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion', 'console-requests');
});

/**
 * Make the given number of records of one group, a second apart, starting at the offset from the split.
 *
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function compareCopies(RecordType $type, string $letter, float $offset, int $count, array $fields = []): array
{
    return array_map(fn (int $index) => compareRecord($type, $letter, COMPARE_SPLIT + $offset + $index, 10000, $fields), range(0, $count - 1));
}

it('moves a duration or a memory value only past both the 10% band and the noise floor, in either direction', function (string $by, string $field, int $before, int $after, string $change) {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3, [$field => $before]),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3, [$field => $after]),
    ]);

    $row = compareAnswer(['by' => $by])['result']['groups'][0];

    expect($row['change'])->toBe($change)
        ->and($row['reason'])->toBeNull();
})->with([
    'a duration up by exactly 10%' => ['p50_duration', 'duration', 100000, 110000, 'steady'],
    'a duration up by just over 10%' => ['p50_duration', 'duration', 100000, 110001, 'slower'],
    'a duration down by exactly 10%' => ['p50_duration', 'duration', 100000, 90000, 'steady'],
    'a duration down by just over 10%' => ['p50_duration', 'duration', 100000, 89999, 'faster'],
    'a duration up by exactly the 1 ms floor' => ['p50_duration', 'duration', 5000, 6000, 'steady'],
    'a duration up by just over the 1 ms floor' => ['p50_duration', 'duration', 5000, 6001, 'slower'],
    'a duration down by exactly the 1 ms floor' => ['p50_duration', 'duration', 6000, 5000, 'steady'],
    'a duration down by just over the 1 ms floor' => ['p50_duration', 'duration', 6000, 4999, 'faster'],
    'memory up by exactly 10%' => ['p50_memory', 'peak_memory_usage', 104857600, 115343360, 'steady'],
    'memory up by just over 10%' => ['p50_memory', 'peak_memory_usage', 104857600, 115343361, 'heavier'],
    'memory down by exactly 10%' => ['p50_memory', 'peak_memory_usage', 104857600, 94371840, 'steady'],
    'memory down by just over 10%' => ['p50_memory', 'peak_memory_usage', 104857600, 94371839, 'lighter'],
    'memory up by exactly the 2 MiB floor' => ['p50_memory', 'peak_memory_usage', 10485760, 12582912, 'steady'],
    'memory up by just over the 2 MiB floor' => ['p50_memory', 'peak_memory_usage', 10485760, 12582913, 'heavier'],
    'memory down by exactly the 2 MiB floor' => ['p50_memory', 'peak_memory_usage', 12582912, 10485760, 'steady'],
    'memory down by just over the 2 MiB floor' => ['p50_memory', 'peak_memory_usage', 12582912, 10485759, 'lighter'],
    'an unchanged value' => ['p50_duration', 'duration', 100000, 100000, 'steady'],
]);

it('moves a count only past both the 10% band and the noise floor of 1, in either direction', function (int $before, int $after, string $change) {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, $before),
        ...compareCopies(RecordType::REQUEST, 'a', 600, $after),
    ]);

    $row = compareAnswer(['by' => 'occurrences'])['result']['groups'][0];

    expect($row)->toMatchArray(['before_occurrences' => $before, 'after_occurrences' => $after, 'difference_occurrences' => $after - $before, 'change' => $change, 'reason' => null]);
})->with([
    'up by exactly 10%' => [10, 11, 'steady'],
    'up past 10% and the floor' => [10, 12, 'more_calls'],
    'down by exactly 10%' => [10, 9, 'steady'],
    'down past 10% and the floor' => [10, 8, 'fewer_calls'],
    'up by exactly the floor' => [5, 6, 'steady'],
    'up by just over the floor' => [5, 7, 'more_calls'],
    'down by exactly the floor' => [5, 4, 'steady'],
    'down by just over the floor' => [5, 3, 'fewer_calls'],
]);

it('states the exact change in percent, and none when the before value is 0', function () {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3, ['duration' => 30000]),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3, ['duration' => 40000]),
        ...compareCopies(RecordType::REQUEST, 'b', -600, 3, ['queries' => 0]),
        ...compareCopies(RecordType::REQUEST, 'b', 600, 3, ['queries' => 2]),
    ]);

    $timed = compareAnswer(['by' => 'p50_duration'])['result']['groups'];
    $counted = compareAnswer(['by' => 'queries'])['result']['groups'];

    expect(collect($timed)->firstWhere('group', compareHash('a')))->toMatchArray(['before_ms' => 30.0, 'after_ms' => 40.0, 'difference_ms' => 10.0, 'change_pct' => 33.3, 'change' => 'slower'])
        ->and(collect($counted)->firstWhere('group', compareHash('b')))->toMatchArray(['before_queries' => 0, 'after_queries' => 6, 'difference_queries' => 6, 'change_pct' => null, 'change' => 'zero_baseline']);
});

it('steps the 95th percentile down to the median when a side has fewer than 20 records, and evaluates nothing below 3', function (string $by, string $field, int $before, int $after, array $expected) {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, $before, [$field => 10485760]),
        ...compareCopies(RecordType::REQUEST, 'a', 600, $after, [$field => 31457280]),
    ]);

    $row = compareAnswer(['by' => $by])['result']['groups'][0];

    expect(array_intersect_key($row, $expected))->toEqual($expected);
})->with([
    'p95 at 20 records a side' => ['p95_duration', 'duration', 20, 20, ['change' => 'slower', 'measured_on' => null, 'reason' => null, 'have' => null, 'needed' => null]],
    'p95 with 19 records before' => ['p95_duration', 'duration', 19, 20, ['change' => 'slower', 'measured_on' => 'p50', 'reason' => null, 'have' => null, 'needed' => null]],
    'p95 with 19 records after' => ['p95_duration', 'duration', 20, 19, ['change' => 'slower', 'measured_on' => 'p50', 'reason' => null]],
    'p95 with 3 records a side' => ['p95_duration', 'duration', 3, 3, ['change' => 'slower', 'measured_on' => 'p50', 'reason' => null]],
    'p95 with 2 records before' => ['p95_duration', 'duration', 2, 40, ['before_ms' => null, 'after_ms' => null, 'change' => 'not_evaluated', 'measured_on' => null, 'reason' => 'sample_too_small', 'have' => 2, 'needed' => 3]],
    'p50 with 2 records after' => ['p50_duration', 'duration', 5, 2, ['change' => 'not_evaluated', 'reason' => 'sample_too_small', 'have' => 2, 'needed' => 3]],
    'p50 at 3 records a side' => ['p50_duration', 'duration', 3, 3, ['change' => 'slower', 'measured_on' => null, 'reason' => null]],
    'memory p95 with 19 records before' => ['p95_memory', 'peak_memory_usage', 19, 20, ['before_mb' => 10.0, 'after_mb' => 30.0, 'change' => 'heavier', 'measured_on' => 'p50']],
    'memory p95 at 20 records a side' => ['p95_memory', 'peak_memory_usage', 20, 20, ['change' => 'heavier', 'measured_on' => null]],
    'memory p95 with 2 records after' => ['p95_memory', 'peak_memory_usage', 20, 2, ['change' => 'not_evaluated', 'reason' => 'sample_too_small', 'have' => 2, 'needed' => 3]],
]);

it('judges a maximum on one record a side, and not on a side whose records have no value', function () {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600, 30000),
        compareRecord(RecordType::REQUEST, 'b', COMPARE_SPLIT - 600, null),
        compareRecord(RecordType::REQUEST, 'b', COMPARE_SPLIT + 600, 30000),
    ]);

    $rows = collect(compareAnswer(['by' => 'max_duration'])['result']['groups'])->keyBy('group');

    expect($rows[compareHash('a')])->toMatchArray(['before_ms' => 10.0, 'after_ms' => 30.0, 'change' => 'slower', 'reason' => null])
        ->and($rows[compareHash('b')])->toMatchArray(['before_ms' => null, 'after_ms' => null, 'change' => 'not_evaluated', 'reason' => 'sample_too_small', 'have' => 0, 'needed' => 1]);
});

it('judges a volume measure only when the longer observed span is at most twice the shorter', function (string $by, int $afterSeconds, ?string $reason) {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600, 10000, ['queries' => 1]),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 590, 10000, ['queries' => 1]),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600, 10000, ['queries' => 1]),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600 + $afterSeconds, 10000, ['queries' => 1]),
    ]);

    $result = compareAnswer(['by' => $by])['result'];

    expect($result['before']['observed_span_ms'])->toEqual(10000.0)
        ->and($result['after']['observed_span_ms'])->toEqual($afterSeconds * 1000.0)
        ->and($result['groups'][0])->toMatchArray(['change' => $reason === null ? 'steady' : 'not_evaluated', 'reason' => $reason]);
})->with([
    'occurrences at a ratio of exactly 2' => ['occurrences', 20, null],
    'occurrences past a ratio of 2' => ['occurrences', 21, 'unequal_spans'],
    'occurrences at a ratio of exactly a half' => ['occurrences', 5, null],
    'occurrences below a ratio of a half' => ['occurrences', 4, 'unequal_spans'],
    'total duration at a ratio of exactly 2' => ['total_duration', 20, null],
    'total duration past a ratio of 2' => ['total_duration', 21, 'unequal_spans'],
    'queries at a ratio of exactly 2' => ['queries', 20, null],
    'queries past a ratio of 2' => ['queries', 21, 'unequal_spans'],
    'a percentile past a ratio of 2' => ['max_duration', 21, null],
]);

it('evaluates no volume measure on a side whose observed span is unknown, and still shows the values', function (string $by, string $field) {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600, 10000, ['queries' => 1]),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600, 10000, ['queries' => 1]),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 610, 10000, ['queries' => 1]),
    ]);

    $result = compareAnswer(['by' => $by])['result'];

    expect($result['before']['observed_span_ms'])->toBeNull()
        ->and($result['groups'][0])->toMatchArray(["before_{$field}" => 1, "after_{$field}" => 2, 'change' => 'not_evaluated', 'reason' => 'unequal_spans', 'have' => null, 'needed' => null]);
})->with([
    'occurrences' => ['occurrences', 'occurrences'],
    'queries' => ['queries', 'queries'],
]);

it('evaluates nothing when the after side is empty, says it is no "no regression" and lists the deploys of the window', function () {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3, ['deploy' => 'v1']),
        ...compareCopies(RecordType::REQUEST, 'b', -300, 2, ['deploy' => 'v2']),
        ...compareCopies(RecordType::REQUEST, 'c', -200, 1),
    ]);

    $envelope = compareAnswer();

    expect($envelope)->toEqual([
        'tool' => 'compare',
        'now' => COMPARE_NOW,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => __('firewatch::messages.compare_empty_side_summary', ['side' => 'after', 'type' => 'request']),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'p95_duration',
            'change' => 'not_evaluated',
            'reason' => 'empty_side',
            'side' => 'after',
            'before' => ['since_at' => COMPARE_CREATED, 'until_at' => COMPARE_SPLIT, 'clipped' => false, 'records' => 6, 'observed_span_ms' => 400000.0, 'earlier_records' => 0, 'earlier_more' => false],
            'after' => ['since_at' => COMPARE_SPLIT, 'until_at' => COMPARE_NOW, 'clipped' => false, 'records' => 0, 'observed_span_ms' => null],
            'rollup' => null,
            'groups' => [],
            'deploys' => [
                ['deploy' => 'v1', 'records' => 3, 'first_at' => COMPARE_SPLIT - 600],
                ['deploy' => 'v2', 'records' => 2, 'first_at' => COMPARE_SPLIT - 300],
            ],
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.compare_not_evaluated_note')],
        'truncated' => [],
        'next' => [],
    ])
        ->and($envelope['coverage']['straddling'])->toBe(0)
        ->and($envelope['summary'])->toContain('"no regression"');
});

it('evaluates nothing when the before side is empty, and says which side it is', function () {
    compareIngest(compareCopies(RecordType::REQUEST, 'a', 600, 3));

    $envelope = compareAnswer();

    expect($envelope['result'])->toMatchArray(['change' => 'not_evaluated', 'reason' => 'empty_side', 'side' => 'before', 'rollup' => null, 'groups' => [], 'deploys' => []])
        ->and($envelope['result']['after']['records'])->toBe(3)
        ->and($envelope['summary'])->toBe(__('firewatch::messages.compare_empty_side_summary', ['side' => 'before', 'type' => 'request']));
});

it('puts a record that started exactly at the split on the after side', function () {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 0.000001, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT, 10000),
    ]);

    $result = compareAnswer(['by' => 'max_duration'])['result'];

    expect($result['before']['records'])->toBe(1)
        ->and($result['after']['records'])->toBe(1)
        ->and($result['groups'][0])->toMatchArray(['before_records' => 1, 'after_records' => 1, 'change' => 'steady']);
});

it('refuses a split that does not lie strictly inside the window', function (array $arguments) {
    compareIngest(compareCopies(RecordType::REQUEST, 'a', -600, 3));

    $response = FirewatchServer::tool(Compare::class, ['type' => 'request', ...$arguments]);

    $response->assertHasErrors([__('firewatch::messages.split_outside_window', ['example' => 'compare(type: "request", split_at: "<now of an earlier answer>")'])]);
})->with([
    'at since' => [['since' => COMPARE_SPLIT - 100, 'split_at' => COMPARE_SPLIT - 100]],
    'at until' => [['until' => COMPARE_SPLIT + 100, 'split_at' => COMPARE_SPLIT + 100]],
    'before since' => [['since' => COMPARE_SPLIT, 'split_at' => COMPARE_SPLIT - 1]],
    'after until' => [['until' => COMPARE_SPLIT, 'split_at' => COMPARE_SPLIT + 1]],
    'at the coverage start when since is omitted' => [['split_at' => COMPARE_CREATED]],
    'at now when until is omitted' => [['split_at' => 'now']],
    'after now when until is omitted' => [['split_at' => COMPARE_NOW + 1]],
]);

it('accepts a split just inside either end of the window', function (array $arguments) {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -50, 3),
        ...compareCopies(RecordType::REQUEST, 'a', 50, 3),
    ]);

    $envelope = Envelope::assert(Compare::class, ['type' => 'request', ...$arguments]);

    expect($envelope['result']['type'])->toBe('request');
})->with([
    'just after since' => [['since' => COMPARE_SPLIT - 100, 'split_at' => COMPARE_SPLIT - 99.999999]],
    'just before until' => [['until' => COMPARE_SPLIT + 100, 'split_at' => COMPARE_SPLIT + 99.999999]],
    'just after the coverage start' => [['split_at' => COMPARE_CREATED + 0.000001]],
    'just before now' => [['split_at' => COMPARE_NOW - 0.000001]],
]);

it('reads an omitted since as the coverage start of the type and an omitted until as the store clock, leaving out what started after it', function () {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_NOW, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_NOW + 60, 10000),
    ]);

    $envelope = compareAnswer();

    expect($envelope['window'])->toMatchArray(['since' => COMPARE_CREATED, 'until' => COMPARE_NOW])
        ->and($envelope['result']['before'])->toMatchArray(['since_at' => COMPARE_CREATED, 'clipped' => false])
        ->and($envelope['result']['after'])->toMatchArray(['until_at' => COMPARE_NOW, 'records' => 0])
        ->and($envelope['result']['reason'])->toBe('empty_side');
});

it('reads an omitted since as the instant a rebuild recreated the store', function () {
    compareIngest(compareCopies(RecordType::REQUEST, 'a', -6000, 3));
    $this->travelTo(Date::createFromTimestamp(COMPARE_SPLIT - 1000));
    app(Writer::class)->rebuild();
    ingest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3),
    ]);
    $this->travelTo(Date::createFromTimestamp(COMPARE_NOW));

    $envelope = compareAnswer(['by' => 'p50_duration']);

    expect($envelope['window']['since'])->toEqual(COMPARE_SPLIT - 1000)
        ->and($envelope['result']['before'])->toMatchArray(['since_at' => COMPARE_SPLIT - 1000, 'clipped' => false, 'records' => 3]);
});

it('clips a side that begins before the coverage start of the type, and says so', function () {
    compareIngest(compareCopies(RecordType::REQUEST, 'a', -6000, 3));
    $this->travelTo(Date::createFromTimestamp(COMPARE_SPLIT - 1000));
    $this->artisan('firewatch:clear', ['--force' => true])->run();
    ingest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3),
    ]);
    $this->travelTo(Date::createFromTimestamp(COMPARE_NOW));

    $envelope = compareAnswer(['by' => 'p50_duration', 'since' => COMPARE_CREATED]);

    expect($envelope['window']['since'])->toEqual(COMPARE_CREATED)
        ->and($envelope['result']['before'])->toEqual(['since_at' => COMPARE_SPLIT - 1000, 'until_at' => COMPARE_SPLIT, 'clipped' => true, 'records' => 3, 'observed_span_ms' => 2000.0, 'earlier_records' => 0, 'earlier_more' => false])
        ->and($envelope['result']['after']['clipped'])->toBeFalse()
        ->and($envelope['result']['groups'][0]['change'])->toBe('steady');
});

it('evaluates nothing when the before side lies wholly before the coverage start of the type', function () {
    compareIngest(compareCopies(RecordType::REQUEST, 'a', -6000, 3));
    $this->travelTo(Date::createFromTimestamp(COMPARE_SPLIT - 1000));
    $this->artisan('firewatch:clear', ['--force' => true])->run();
    ingest(compareCopies(RecordType::REQUEST, 'a', 600, 3));
    $this->travelTo(Date::createFromTimestamp(COMPARE_NOW));

    $envelope = compareAnswer(['since' => COMPARE_CREATED, 'split_at' => COMPARE_SPLIT - 2000]);

    expect($envelope['result'])->toMatchArray(['change' => 'not_evaluated', 'reason' => 'outside_coverage', 'side' => 'before', 'rollup' => null, 'groups' => [], 'deploys' => null])
        ->and($envelope['result']['before'])->toMatchArray(['since_at' => COMPARE_SPLIT - 1000, 'until_at' => COMPARE_SPLIT - 2000, 'clipped' => true, 'records' => 0])
        ->and($envelope['result']['after'])->toMatchArray(['since_at' => COMPARE_SPLIT - 1000, 'clipped' => true, 'records' => 3])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.compare_outside_coverage_summary', ['side' => 'before', 'type' => 'request']))
        ->and($envelope['notes'])->toBe([__('firewatch::messages.compare_not_evaluated_note')]);
});

/**
 * Make three records of a group before the split and three after it, of the given durations in milliseconds; a side without one has no records.
 *
 * @return list<RecordBuilder>
 */
function compareGroup(string $letter, ?int $before, ?int $after, int $beforeCount = 3): array
{
    return [
        ...($before === null ? [] : compareCopies(RecordType::REQUEST, $letter, -600, $beforeCount, ['duration' => $before * 1000])),
        ...($after === null ? [] : compareCopies(RecordType::REQUEST, $letter, 600, 3, ['duration' => $after * 1000])),
    ];
}

it('lists the moved groups by the size of their change, then the new, then the gone, then the rest by their after value, ties by group hash', function () {
    compareIngest([
        ...compareGroup('j', 100, 100),
        ...compareGroup('i', 100, 100, beforeCount: 2),
        ...compareGroup('h', 50, 50),
        ...compareGroup('g', 100, 100),
        ...compareGroup('f', 70, null),
        ...compareGroup('e', null, 80),
        ...compareGroup('d', null, 30),
        ...compareGroup('c', 100, 150),
        ...compareGroup('b', 100, 40),
        ...compareGroup('a', 100, 150),
    ]);

    $envelope = compareAnswer(['by' => 'p50_duration']);
    $rows = $envelope['result']['groups'];

    expect(array_column($rows, 'group'))->toBe(array_map(compareHash(...), ['b', 'a', 'c', 'e', 'd', 'f', 'g', 'j', 'h', 'i']))
        ->and(array_column($rows, 'change'))->toBe(['faster', 'slower', 'slower', 'new', 'new', 'gone', 'steady', 'steady', 'steady', 'not_evaluated'])
        ->and(array_map(array_keys(...), $rows))->each->toBe(array_keys($rows[0]))
        ->and($envelope['result']['rollup'])->toBe(compareRollup(10, ['slower' => 2, 'faster' => 1, 'steady' => 3, 'new' => 2, 'gone' => 1, 'not_evaluated' => 1]))
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.compare_summary', 10, ['groups' => 10, 'type' => 'request', 'by' => 'p50_duration', 'changes' => '2 slower, 1 faster, 3 steady, 2 new, 1 gone, 1 not_evaluated']));
});

it('cuts the rows at the limit, says how many groups were cut and how to narrow, and counts every group in the rollup', function () {
    compareIngest(array_merge(...array_map(fn (int $index) => compareGroup(chr(ord('a') + $index), 100, 200 + $index * 10), range(0, 24))));

    $envelope = compareAnswer(['by' => 'p50_duration', 'limit' => 10]);

    expect($envelope['result']['groups'])->toHaveCount(10)
        ->and($envelope['result']['groups'][0]['group'])->toBe(compareHash('y'))
        ->and($envelope['truncated'])->toBe([['section' => 'groups', 'shown' => 10, 'matched' => 25, 'reason' => 'limit', 'how' => __('firewatch::messages.compare_truncated_how')]])
        ->and($envelope['result']['rollup'])->toBe(compareRollup(25, ['slower' => 25], cut: 15))
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.compare_summary', 25, ['groups' => 25, 'type' => 'request', 'by' => 'p50_duration', 'changes' => '25 slower']));
});

it('lists every group when they are no more than the limit, from a limit of 1 to one of 100, and 20 when none is given', function (?int $limit, int $groups, int $shown, int $cut) {
    compareIngest(array_merge(...array_map(fn (int $index) => compareGroup(chr(ord('a') + $index), 100, 100), range(0, $groups - 1))));

    $envelope = compareAnswer(['by' => 'p50_duration', ...($limit === null ? [] : ['limit' => $limit])]);

    expect($envelope['result']['groups'])->toHaveCount($shown)
        ->and($envelope['result']['rollup']['cut'])->toBe($cut)
        ->and($envelope['truncated'])->toHaveCount($cut > 0 ? 1 : 0);
})->with([
    'a limit of 1 over one group' => [1, 1, 1, 0],
    'a limit of 1 over two groups' => [1, 2, 1, 1],
    'a limit of 100 over three groups' => [100, 3, 3, 0],
    'a limit of 20 over 21 groups' => [20, 21, 20, 1],
    'the default limit over 20 groups' => [null, 20, 20, 0],
    'the default limit over 21 groups' => [null, 21, 20, 1],
]);

it('compares one group alone, by its type, when it is given without one', function () {
    compareIngest([
        ...compareGroup('a', 100, 150),
        ...compareGroup('b', 100, 300),
    ]);

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT, 'by' => 'p50_duration']);

    expect(array_column($envelope['result']['groups'], 'group'))->toBe([compareHash('a')])
        ->and($envelope['result'])->toMatchArray(['type' => 'request', 'rollup' => compareRollup(1, ['slower' => 1])])
        ->and($envelope['result']['before']['records'])->toBe(3)
        ->and($envelope['coverage']['types_read'])->toBe(['request']);
});

it('compares the job attempts of a job group when no type is given, and says so', function () {
    compareIngest([
        ...compareCopies(RecordType::JOB_ATTEMPT, 'a', -600, 3, ['duration' => 10000]),
        ...compareCopies(RecordType::JOB_ATTEMPT, 'a', 600, 3, ['duration' => 30000]),
        ...compareCopies(RecordType::QUEUED_JOB, 'a', -600, 3, ['duration' => 1000]),
    ]);

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT, 'by' => 'p50_duration']);
    $dispatches = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'type' => 'queued-job', 'split_at' => COMPARE_SPLIT, 'by' => 'p50_duration']);

    expect($envelope['result']['type'])->toBe('job-attempt')
        ->and($envelope['result']['groups'][0]['change'])->toBe('slower')
        ->and($envelope['notes'])->toBe([__('firewatch::messages.rank_job_group', ['group' => compareHash('a')])])
        ->and($dispatches['result'])->toMatchArray(['type' => 'queued-job', 'reason' => 'empty_side', 'side' => 'after'])
        ->and($dispatches['notes'])->toBe([__('firewatch::messages.compare_not_evaluated_note')]);
});

it('answers that the store holds no such group, with the filters', function () {
    compareIngest(compareGroup('a', 100, 150));

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash('f'), 'split_at' => COMPARE_SPLIT]);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 6, 'filters' => 'group: '.compareHash('f')])])
        ->and($envelope['result'])->toBe([]);
});

it('refuses what it can not compare, naming what it accepts', function (array $arguments, string $key, string $argument, string $accepted, string $example, ?string $expected = null, ?string $value = null) {
    compareIngest(compareGroup('a', 100, 150));

    $response = FirewatchServer::tool(Compare::class, $arguments);

    $response->assertHasErrors([__("firewatch::messages.{$key}", ['argument' => $argument, 'with' => 'group', 'expected' => $expected, 'value' => $value, 'accepted' => $accepted, 'example' => $example])]);
})->with(function () {
    $types = 'request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request, queued-job';
    $example = 'compare(type: "request", split_at: "<now of an earlier answer>")';
    $limits = 'a whole number from 1 to 100';
    $limitExample = 'compare(type: "request", split_at: "<time>", limit: 20)';

    return [
        'neither type nor group' => [['split_at' => COMPARE_SPLIT], 'missing_argument', 'type', $types, $example],
        'no split' => [['type' => 'request'], 'missing_argument', 'split_at', 'exactly one boundary: `split_at` (a time, such as the now of an earlier answer), or `deploy_before` with `deploy_after` (exact deploy strings)', $example],
        'a type with no groups' => [['type' => 'log', 'split_at' => COMPARE_SPLIT], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '"log"'],
        'a type in capitals' => [['type' => 'Request', 'split_at' => COMPARE_SPLIT], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '"Request"'],
        'a measure that is none' => [['type' => 'request', 'split_at' => COMPARE_SPLIT, 'by' => 'p99_duration'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, p95_memory, p50_memory, max_memory, queries', 'compare(type: "request", split_at: "<time>", by: "p95_duration")', 'a measure of request', '"p99_duration"'],
        'when a group was last seen' => [['type' => 'request', 'split_at' => COMPARE_SPLIT, 'by' => 'last_seen'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, p95_memory, p50_memory, max_memory, queries', 'compare(type: "request", split_at: "<time>", by: "p95_duration")', 'a measure of request', '"last_seen"'],
        'memory of a query' => [['type' => 'query', 'split_at' => COMPARE_SPLIT, 'by' => 'p50_memory'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences', 'compare(type: "query", split_at: "<time>", by: "p95_duration")', 'a measure of query', '"p50_memory"'],
        'a duration of exceptions' => [['type' => 'exception', 'split_at' => COMPARE_SPLIT, 'by' => 'p95_duration'], 'invalid_argument', 'by', 'occurrences', 'compare(type: "exception", split_at: "<time>", by: "occurrences")', 'a measure of exception', '"p95_duration"'],
        'a measure that does not fit the group' => [['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT, 'by' => 'p99_duration'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, p95_memory, p50_memory, max_memory, queries', 'compare(type: "request", split_at: "<time>", by: "p95_duration")', 'a measure of request', '"p99_duration"'],
        'a limit of 0' => [['type' => 'request', 'split_at' => COMPARE_SPLIT, 'limit' => 0], 'invalid_argument', 'limit', $limits, $limitExample, '1 to 100', '0'],
        'a limit of 101' => [['type' => 'request', 'split_at' => COMPARE_SPLIT, 'limit' => 101], 'invalid_argument', 'limit', $limits, $limitExample, '1 to 100', '101'],
        'a limit that is no whole number' => [['type' => 'request', 'split_at' => COMPARE_SPLIT, 'limit' => 'ten'], 'invalid_argument', 'limit', $limits, $limitExample, '1 to 100', '"ten"'],
        'a limit with a group' => [['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT, 'limit' => 5], 'conflicting_arguments', 'limit', 'a call without `limit`: one group is one row', 'compare(group: "'.compareHash('a').'", split_at: "<time>")', null, null],
        'a group that is no group id' => [['group' => 'abc', 'split_at' => COMPARE_SPLIT], 'invalid_argument', 'group', 'a 32-character lowercase hex group id', 'compare(group: "<group id>", split_at: "<time>")', 'a 32-character lowercase hex group id', '"abc"'],
        'a type that does not hold the group' => [['group' => compareHash('a'), 'type' => 'query', 'split_at' => COMPARE_SPLIT], 'conflicting_arguments', 'type', 'a type that holds the group: request', 'compare(group: "'.compareHash('a').'", split_at: "<time>")', null, null],
    ];
});

it('refuses a split or a bound that is no time, and a window that does not start before it ends', function (array $arguments, string $error) {
    $response = FirewatchServer::tool(Compare::class, ['type' => 'request', ...$arguments]);

    $response->assertHasErrors(["error: {$error}"]);
})->with([
    'a split in words' => [['split_at' => 'yesterday'], 'unreadable_time'],
    'a split in milliseconds' => [['split_at' => 1790776000000], 'unreadable_time'],
    'a since in words' => [['split_at' => COMPARE_SPLIT, 'since' => 'last week'], 'unreadable_time'],
    'an empty window' => [['split_at' => COMPARE_SPLIT, 'since' => 'now', 'until' => '-1d'], 'empty_window'],
]);

it('refuses the deploy pair beside a split, an argument of another tool and one that is none', function (string $argument, string $code) {
    $response = FirewatchServer::tool(Compare::class, ['type' => 'request', 'split_at' => COMPARE_SPLIT, $argument => 'x']);

    $response->assertHasErrors(["error: {$code}"]);
})->with([
    'deploy_before' => ['deploy_before', 'conflicting_arguments'],
    'deploy_after' => ['deploy_after', 'conflicting_arguments'],
    'deploy' => ['deploy', 'conflicting_arguments'],
    'cursor' => ['cursor', 'conflicting_arguments'],
    'a misspelling' => ['split', 'invalid_argument'],
]);

it('counts the work of the type that started in the hour before the split and ended after it, and only that', function () {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 3600, 3601000000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 3600.000001, 3601000000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 10, 11000000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 10, 10000000),
        compareRecord(RecordType::REQUEST, 'b', COMPARE_SPLIT - 10, 11000000),
        compareRecord(RecordType::COMMAND, 'c', COMPARE_SPLIT - 10, 11000000),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3),
    ]);

    $all = compareAnswer();
    $one = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT]);
    $later = compareAnswer(['since' => COMPARE_SPLIT - 5]);

    expect($all['coverage']['straddling'])->toBe(3)
        ->and($one['coverage']['straddling'])->toBe(2)
        ->and($later['coverage']['straddling'])->toBe(0)
        ->and(array_column($all['blind_spots'], 'id'))->toContain('visible-at-completion');
});

it('states no straddling count on the answers of other tools', function () {
    compareIngest(compareGroup('a', 100, 150));

    $ranked = Envelope::assert(Rank::class, ['type' => 'request']);

    expect($ranked['coverage'])->toHaveKey('straddling', null)
        ->and(array_column($ranked['blind_spots'], 'id'))->not->toContain('visible-at-completion');
});

it('says to move since when it is left out and the store holds records from more than an hour before the split', function (float $oldest, array $arguments, array $notes) {
    compareIngest([
        compareRecord(RecordType::COMMAND, 'z', COMPARE_SPLIT + $oldest, 10000),
        ...compareGroup('a', 100, 150),
    ]);

    $envelope = compareAnswer($arguments);

    expect($envelope['notes'])->toBe(array_map(fn (string $key) => __("firewatch::messages.{$key}"), $notes));
})->with([
    'a record from just over an hour before' => [-3600.000001, [], ['compare_move_since_note']],
    'a record from exactly an hour before' => [-3600, [], []],
    'a record from long before, with since' => [-7000, ['since' => COMPARE_SPLIT - 7100], []],
]);

it('offers the records and the deploy breakdown of the group that changed most, over the same window when the clock moves on, and every call runs', function () {
    compareIngest([
        ...compareGroup('a', 100, 110),
        ...compareGroup('b', 100, 400),
    ]);

    $envelope = compareAnswer(['by' => 'p50_duration']);
    $this->travelTo(Date::createFromTimestamp(COMPARE_NOW + 7200));
    ingest(compareCopies(RecordType::REQUEST, 'b', COMPARE_NOW - COMPARE_SPLIT + 600, 3, ['duration' => 900000]));

    $window = ['since' => COMPARE_CREATED, 'until' => COMPARE_NOW];
    $records = Envelope::assert(Occurrences::class, $envelope['next'][0]['arguments']);
    $breakdown = Envelope::assert(Rank::class, $envelope['next'][1]['arguments']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['occurrences', 'rank'])
        ->and(array_column($envelope['next'], 'arguments'))->toEqual([['group' => compareHash('b'), ...$window], ['group' => compareHash('b'), ...$window]])
        ->and($records['empty'])->toBeNull()
        ->and($records['window'])->toMatchArray($window)
        ->and($records['result']['rows'])->toHaveCount(6)
        ->and($breakdown['empty'])->toBeNull()
        ->and($breakdown['result']['records'])->toBe(6);
});

it('offers the dispatch breakdown of a job group compared as dispatches', function () {
    compareIngest([
        ...compareCopies(RecordType::QUEUED_JOB, 'a', -600, 3, ['duration' => 1000]),
        ...compareCopies(RecordType::QUEUED_JOB, 'a', 600, 3, ['duration' => 9000]),
    ]);

    $envelope = Envelope::assert(Compare::class, ['type' => 'queued-job', 'split_at' => COMPARE_SPLIT, 'by' => 'p50_duration']);

    expect(array_column($envelope['next'], 'arguments'))->toEqual([
        ['group' => compareHash('a'), 'type' => 'queued-job', 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW],
        ['group' => compareHash('a'), 'type' => 'queued-job', 'since' => COMPARE_CREATED, 'until' => COMPARE_NOW],
    ]);

    foreach ($envelope['next'] as $call) {
        expect(Envelope::assert(['occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']], $call['arguments'])['empty'])->toBeNull();
    }
});

it('answers nothing for a window with no record, a type with none on either side, an empty store and no store, and states what it can not see', function () {
    $this->travelTo(Date::createFromTimestamp(COMPARE_CREATED));
    $missing = compareAnswer();

    app(Writer::class)->transaction(fn () => null);
    $emptyStore = compareAnswer(['since' => COMPARE_CREATED, 'until' => COMPARE_NOW]);

    compareIngest(compareGroup('a', 100, 150));
    $emptyWindow = compareAnswer(['since' => COMPARE_SPLIT - 100, 'until' => COMPARE_SPLIT + 100]);
    $noMatch = compareAnswer(['type' => 'query']);

    expect($missing['empty']['kind'])->toBe('no_store')
        ->and($missing['coverage'])->toMatchArray(['state' => 'absent', 'straddling' => null])
        ->and($missing['next'])->toBe([])
        ->and($emptyStore['empty']['kind'])->toBe('store_empty')
        ->and($emptyWindow['empty'])->toMatchArray(['kind' => 'window_empty', 'population' => 6])
        ->and($noMatch['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 6, 'filters' => 'type: query'])])
        ->and($noMatch['result'])->toBe([]);

    foreach ([$missing, $emptyStore, $emptyWindow, $noMatch] as $envelope) {
        expect(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion');
    }
});

it('is listed between actor and trend, read only, idempotent and closed, with no argument required', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tools = array_column($listing['tools'], null, 'name');
    $compare = $tools['compare'];

    expect(array_slice(array_keys($tools), -4))->toBe(['actor', 'compare', 'trend', 'query'])
        ->and($compare['description'])->toBe(__('firewatch::messages.tools.compare'))
        ->and(str_word_count($compare['description']))->toBeLessThanOrEqual(150)
        ->and(array_keys($compare['inputSchema']['properties']))->toBe(['type', 'group', 'split_at', 'deploy_before', 'deploy_after', 'by', 'since', 'until', 'limit', 'format'])
        ->and($compare['inputSchema'])->not->toHaveKey('required')
        ->and($compare['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]);
});

it('says a group is new or gone with the value of the side it is on, and no change in percent', function () {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -600, 3, ['duration' => 20000]),
        ...compareCopies(RecordType::REQUEST, 'b', 600, 3, ['duration' => 50000]),
    ]);

    $rows = compareAnswer(['by' => 'p50_duration'])['result']['groups'];

    expect($rows[0])->toMatchArray(['group' => compareHash('b'), 'before_records' => 0, 'after_records' => 3, 'before_ms' => null, 'after_ms' => 50.0, 'difference_ms' => null, 'change_pct' => null, 'change' => 'new'])
        ->and($rows[1])->toMatchArray(['group' => compareHash('a'), 'before_records' => 3, 'after_records' => 0, 'before_ms' => 20.0, 'after_ms' => null, 'difference_ms' => null, 'change_pct' => null, 'change' => 'gone']);
});

it('gives one group that is on one side only its one row, new or gone, while other groups of the type are on both sides', function (string $letter, array $row, int $before, int $after) {
    compareIngest([
        ...compareGroup('a', null, 150),
        ...compareGroup('b', 100, 100),
        ...compareGroup('c', 70, null),
    ]);

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash($letter), 'split_at' => COMPARE_SPLIT, 'by' => 'p50_duration']);
    $result = $envelope['result'];

    expect($result)->toMatchArray(['type' => 'request', 'change' => null, 'reason' => null, 'side' => null, 'rollup' => compareRollup(1, [$row['change'] => 1]), 'deploys' => null])
        ->and($result['groups'])->toHaveCount(1)
        ->and($result['groups'][0])->toMatchArray(['group' => compareHash($letter), 'change_pct' => null, ...$row])
        ->and($result['before'])->toMatchArray(['records' => $before, 'observed_span_ms' => $before === 0 ? null : 2000.0])
        ->and($result['after'])->toMatchArray(['records' => $after, 'observed_span_ms' => $after === 0 ? null : 2000.0])
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.compare_summary', 1, ['groups' => 1, 'type' => 'request', 'by' => 'p50_duration', 'changes' => "1 {$row['change']}"]));
})->with([
    'a group after the split only' => ['a', ['before_records' => 0, 'after_records' => 3, 'before_ms' => null, 'after_ms' => 150.0, 'change' => 'new'], 0, 3],
    'a group before the split only' => ['c', ['before_records' => 3, 'after_records' => 0, 'before_ms' => 70.0, 'after_ms' => null, 'change' => 'gone'], 3, 0],
]);

it('evaluates nothing for one group when a side holds no record of its type at all', function () {
    compareIngest([
        ...compareGroup('a', null, 150),
        ...compareGroup('b', null, 100),
    ]);

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT]);

    expect($envelope['result'])->toMatchArray(['change' => 'not_evaluated', 'reason' => 'empty_side', 'side' => 'before', 'rollup' => null, 'groups' => []])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.compare_empty_side_summary', ['side' => 'before', 'type' => 'request']));
});

it('answers that nothing matches one group with no record in the window, while its type has records on both sides', function () {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', -6000, 3),
        ...compareGroup('b', 100, 150),
    ]);

    $envelope = Envelope::assert(Compare::class, ['group' => compareHash('a'), 'split_at' => COMPARE_SPLIT, 'since' => COMPARE_SPLIT - 1000]);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 6, 'filters' => 'group: '.compareHash('a')])])
        ->and($envelope['result'])->toBe([]);
});

it('judges a volume measure at a span ratio of exactly 2 whatever the fractions of a second, and not a microsecond past it', function (float $beforeSpan, float $afterSpan, ?string $reason) {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600 + $beforeSpan, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600, 10000),
        compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT + 600 + $afterSpan, 10000),
    ]);

    $result = compareAnswer(['by' => 'occurrences'])['result'];

    expect($result['before']['observed_span_ms'])->toEqual(round($beforeSpan * 1000, 2))
        ->and($result['after']['observed_span_ms'])->toEqual(round($afterSpan * 1000, 2))
        ->and($result['groups'][0])->toMatchArray(['change' => $reason === null ? 'steady' : 'not_evaluated', 'reason' => $reason]);
})->with([
    '0.1 s against 0.2 s' => [0.1, 0.2, null],
    '0.1 s against a microsecond past 0.2 s' => [0.1, 0.200001, 'unequal_spans'],
    '1.1 s against 2.2 s' => [1.1, 2.2, null],
    '2.2 s against 1.1 s' => [2.2, 1.1, null],
    '1.1 s against a microsecond past 2.2 s' => [1.1, 2.200001, 'unequal_spans'],
    '0.3 s against 0.6 s' => [0.3, 0.6, null],
]);

it('counts the straddling work of an execution type only, and states none for a type that is no execution', function (string $type, ?int $straddling) {
    compareIngest([
        compareRecord(RecordType::from($type), 'a', COMPARE_SPLIT - 10, 11000000),
        compareRecord(RecordType::from($type), 'a', COMPARE_SPLIT - 5, 1000),
        ...compareCopies(RecordType::from($type), 'a', 600, 3),
    ]);

    $arguments = ['type' => $type, 'split_at' => COMPARE_SPLIT, 'by' => 'occurrences'];
    $envelope = Envelope::assert(Compare::class, $arguments);
    $markdown = (fn () => $this->content())->call(FirewatchServer::tool(Compare::class, $arguments))[0];
    $storeLine = collect(explode("\n", $markdown))->first(fn (string $line) => str_starts_with($line, 'Store: '));
    $stated = $straddling === null ? [] : [__('firewatch::messages.store_straddling', ['count' => $straddling])];

    expect($envelope['result']['before']['records'])->toBe(2)
        ->and($envelope['coverage']['straddling'])->toBe($straddling)
        ->and(array_values(preg_grep('/straddling/', explode(', ', explode('; ', $storeLine)[0]))))->toBe($stated);
})->with([
    'request' => ['request', 1],
    'command' => ['command', 1],
    'job-attempt' => ['job-attempt', 1],
    'scheduled-task' => ['scheduled-task', 1],
    'query' => ['query', null],
    'outgoing-request' => ['outgoing-request', null],
    'queued-job' => ['queued-job', null],
    'exception' => ['exception', null],
]);

it('says how many records of the type started before what the store covers, which are on neither side', function (float $offset, array $arguments, int $records, int $earlier) {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_CREATED + $offset, 10000),
        compareRecord(RecordType::COMMAND, 'c', COMPARE_CREATED - 5, 10000),
        ...compareCopies(RecordType::REQUEST, 'a', 600, 3),
    ]);

    $envelope = compareAnswer($arguments);
    $said = $earlier === 0 ? [] : [trans_choice('firewatch::messages.compare_earlier_note', $earlier, ['count' => $earlier, 'type' => 'request'])];

    expect($envelope['result']['before'])->toMatchArray(['since_at' => COMPARE_CREATED, 'records' => $records, 'earlier_records' => $earlier, 'earlier_more' => false])
        ->and($envelope['result']['after'])->not->toHaveKeys(['earlier_records', 'earlier_more'])
        ->and($envelope['result']['reason'])->toBe($records === 0 ? 'empty_side' : null)
        ->and($envelope['notes'])->toBe([
            ...($records === 0 ? [__('firewatch::messages.compare_not_evaluated_note')] : []),
            ...$said,
            ...($arguments === [] ? [__('firewatch::messages.compare_move_since_note')] : []),
        ]);
})->with([
    'a record a microsecond before the coverage start' => [-0.000001, [], 0, 1],
    'a record exactly at the coverage start' => [0, [], 1, 0],
    'a record before the coverage start, and a since before it' => [-5, ['since' => COMPARE_CREATED - 60], 0, 1],
]);

it('says nothing of the records before what the store covers when since leaves them out anyway', function () {
    compareIngest([
        compareRecord(RecordType::REQUEST, 'a', COMPARE_CREATED - 5, 10000),
        ...compareGroup('a', 100, 100),
    ]);

    $envelope = compareAnswer(['by' => 'p50_duration', 'since' => COMPARE_SPLIT - 1000]);

    expect($envelope['result']['before'])->toMatchArray(['since_at' => COMPARE_SPLIT - 1000, 'clipped' => false, 'records' => 3, 'earlier_records' => 0, 'earlier_more' => false])
        ->and($envelope['notes'])->toBe([]);
});

it('counts at most 100 records before what the store covers, and says when there are more', function (int $stored, int $earlier, bool $more, string $key) {
    compareIngest([
        ...compareCopies(RecordType::REQUEST, 'a', COMPARE_CREATED - COMPARE_SPLIT - 600, $stored),
        ...compareGroup('a', 100, 100),
    ]);

    $envelope = compareAnswer(['by' => 'p50_duration']);

    expect($envelope['result']['before'])->toMatchArray(['records' => 3, 'earlier_records' => $earlier, 'earlier_more' => $more])
        ->and($envelope['notes'])->toBe([
            trans_choice("firewatch::messages.{$key}", $earlier, ['count' => $earlier, 'type' => 'request']),
            __('firewatch::messages.compare_move_since_note'),
        ]);
})->with([
    '100 records' => [100, 100, false, 'compare_earlier_note'],
    '101 records' => [101, 100, true, 'compare_earlier_more_note'],
]);

it('counts the groups the size budget dropped as cut in the rollup, which agrees with the truncated entry', function () {
    $records = array_map(fn (int $index) => [
        ...compareCopies(RecordType::QUERY, 'a', -600, 3, ['_group' => md5((string) $index), 'sql' => 'select '.str_repeat("column_{$index}, ", 100).'1']),
        ...compareCopies(RecordType::QUERY, 'a', 600, 3, ['_group' => md5((string) $index), 'sql' => 'select '.str_repeat("column_{$index}, ", 100).'1']),
    ], range(0, 99));

    compareIngest(array_merge(...$records));

    $envelope = compareAnswer(['type' => 'query', 'by' => 'p50_duration', 'limit' => 100]);
    $shown = count($envelope['result']['groups']);

    expect($shown)->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($envelope['truncated'])->toBe([['section' => 'groups', 'shown' => $shown, 'matched' => 100, 'reason' => 'size', 'how' => __('firewatch::messages.size_how', ['characters' => '24,000'])]])
        ->and($envelope['result']['rollup'])->toBe(compareRollup(100, ['steady' => 100], cut: 100 - $shown))
        ->and(mb_strlen(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)))->toBeLessThanOrEqual(24000);
});

it('lists at most ten deploys beside an empty side, and says so when the window holds more', function (int $deploys, array $truncated) {
    $records = array_map(fn (int $index) => compareRecord(RecordType::REQUEST, 'a', COMPARE_SPLIT - 600 + $index, 10000, ['deploy' => sprintf('v%02d', $index)]), range(1, $deploys));

    compareIngest($records);

    $envelope = compareAnswer();

    expect($envelope['result']['reason'])->toBe('empty_side')
        ->and(array_column($envelope['result']['deploys'], 'deploy'))->toBe(['v01', 'v02', 'v03', 'v04', 'v05', 'v06', 'v07', 'v08', 'v09', 'v10'])
        ->and($envelope['truncated'])->toBe($truncated);
})->with([
    'ten deploys' => [10, []],
    'eleven deploys' => fn () => [11, [['section' => 'deploys', 'shown' => 10, 'matched' => null, 'reason' => 'limit', 'how' => __('firewatch::messages.compare_deploys_truncated_how')]]],
]);
