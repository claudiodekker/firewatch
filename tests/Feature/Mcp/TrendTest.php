<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const TREND_START = 1790776000.0;

const TREND_CREATED = TREND_START - 3600;

const TREND_NOW = TREND_START + 4500;

function trendHash(string $letter): string
{
    return str_repeat($letter, 32);
}

/**
 * @param  array<string, mixed>  $fields
 */
function trendRecord(RecordType $type, string $letter, float $at, ?int $microseconds = 1000, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->with([
        '_group' => trendHash($letter),
        'duration' => $microseconds,
        'timestamp' => $at,
        ...$fields,
    ]);
}

/**
 * Make records of one group a second apart, the first at the offset from the start.
 *
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function trendRecords(int $count, float $offset, RecordType $type = RecordType::REQUEST, string $letter = 'a', ?int $microseconds = 1000, array $fields = []): array
{
    return array_map(fn (int $index) => trendRecord($type, $letter, TREND_START + $offset + $index, $microseconds, $fields), range(0, $count - 1));
}

/**
 * Store the records in a store created an hour before the start, then set the clock.
 *
 * @param  list<RecordBuilder>  $records
 */
function trendIngest(array $records, float $now = TREND_NOW): void
{
    test()->travelTo(Date::createFromTimestamp(TREND_CREATED));
    ingest($records);
    test()->travelTo(Date::createFromTimestamp($now));
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function trendAnswer(array $arguments = []): array
{
    return Envelope::assert(Trend::class, ['type' => 'request', ...$arguments]);
}

/**
 * Run a call an answer offers and get what it answers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function trendFollow(array $call): array
{
    $tool = ['occurrences' => Occurrences::class][$call['tool']];

    return Envelope::assert($tool, $call['arguments']);
}

/**
 * Make the records of six buckets of ten minutes from the start, counted 3, 4, 0, 6, 9 and 11, the last of them an hour after the first.
 *
 * @return list<RecordBuilder>
 */
function trendRising(): array
{
    return [
        ...trendRecords(3, 0),
        ...trendRecords(4, 600),
        ...trendRecords(6, 1800),
        ...trendRecords(9, 2400),
        ...trendRecords(10, 3000),
        ...trendRecords(1, 3600),
    ];
}

/**
 * Get a bucket of the answer.
 *
 * @return array<string, mixed>
 */
function trendBucket(int $index, float $since, float $until, int|float|null $value, int $samples, bool $partial = false, string $field = 'occurrences'): array
{
    return ['index' => $index, 'since_at' => $since, 'until_at' => $until, $field => $value, 'samples' => $samples, 'partial' => $partial];
}

it('cuts the window derived from the records into equal buckets, says the count rose, names the peak and the idle time, and every call it offers runs', function () {
    trendIngest(trendRising(), now: TREND_START + 4500);

    $envelope = trendAnswer(['buckets' => 6]);

    expect($envelope)->toEqual([
        'tool' => 'trend',
        'now' => TREND_START + 4500,
        'window' => [
            'windowed' => true,
            'basis' => 'started_at',
            'since' => TREND_START,
            'until' => TREND_START + 3600,
            'timezone' => 'UTC',
            'description' => __('firewatch::messages.window_description_derived'),
            'derived' => ['since', 'until'],
        ],
        'summary' => __('firewatch::messages.trend_summary', ['type' => 'request', 'by' => 'occurrences', 'buckets' => 6, 'direction' => 'rose', 'peak' => 5]),
        'empty' => null,
        'result' => [
            'type' => 'request',
            'by' => 'occurrences',
            'width_ms' => 600000.0,
            'direction' => 'rose',
            'reason' => null,
            'have' => null,
            'needed' => null,
            'peak' => ['index' => 5, 'occurrences' => 11],
            'buckets' => [
                trendBucket(0, TREND_START, TREND_START + 600, 3, 3),
                trendBucket(1, TREND_START + 600, TREND_START + 1200, 4, 4),
                trendBucket(2, TREND_START + 1200, TREND_START + 1800, 0, 0),
                trendBucket(3, TREND_START + 1800, TREND_START + 2400, 6, 6),
                trendBucket(4, TREND_START + 2400, TREND_START + 3000, 9, 9),
                trendBucket(5, TREND_START + 3000, TREND_START + 3600, 11, 11),
            ],
        ],
        'coverage' => [
            ...$envelope['coverage'],
            'state' => 'ok',
            'reason' => null,
            'oldest_at' => TREND_START,
            'newest_at' => TREND_START + 3600,
            'records' => 33,
            'types_read' => ['request'],
            'straddling' => null,
        ],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.trend_idle_note', ['duration' => '15m'])],
        'truncated' => [],
        'next' => [
            ['tool' => 'occurrences', 'arguments' => ['type' => 'request', 'since' => TREND_START + 3000, 'until' => TREND_START + 4500], 'why' => __('firewatch::messages.trend_next_occurrences')],
        ],
    ])
        ->and($envelope['coverage']['history']['from'])->toEqual(TREND_CREATED)
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion')
        ->and(trendFollow($envelope['next'][0])['result']['rows'])->toHaveCount(11);
});

/**
 * Make the given count of records in each bucket of ten minutes from the start, a second apart.
 *
 * @param  list<int>  $counts
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function trendCounts(array $counts, array $fields = []): array
{
    return array_merge(...array_map(fn (int $count, int $index) => $count === 0 ? [] : trendRecords($count, $index * 600, fields: $fields), $counts, array_keys($counts)));
}

/**
 * Make one record of the given duration in milliseconds in each bucket of ten minutes from the start.
 *
 * @param  list<int|float>  $milliseconds
 * @return list<RecordBuilder>
 */
function trendDurations(array $milliseconds): array
{
    return array_map(fn (int|float $duration, int $index) => trendRecord(RecordType::REQUEST, 'a', TREND_START + $index * 600, (int) round($duration * 1000)), $milliseconds, array_keys($milliseconds));
}

/**
 * Get the arguments of a window given whole, of buckets of ten minutes from the start.
 *
 * @return array<string, mixed>
 */
function trendGrid(int $buckets): array
{
    return ['since' => TREND_START, 'until' => TREND_START + $buckets * 600, 'buckets' => $buckets];
}

it('keeps a window given whole as given: half-open, nothing derived, and no idle time however long ago the last record started', function () {
    trendIngest([
        trendRecord(RecordType::REQUEST, 'a', TREND_START),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 599),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 600),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 1200),
    ], now: TREND_START + 86400);

    $envelope = trendAnswer(['since' => TREND_START, 'until' => TREND_START + 1200, 'buckets' => 2]);

    expect($envelope['window'])->toEqual(['windowed' => true, 'basis' => 'started_at', 'since' => TREND_START, 'until' => TREND_START + 1200, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description'), 'derived' => []])
        ->and($envelope['result']['buckets'])->toEqual([
            trendBucket(0, TREND_START, TREND_START + 600, 2, 2),
            trendBucket(1, TREND_START + 600, TREND_START + 1200, 1, 1),
        ])
        ->and($envelope['notes'])->toBe([]);
});

it('derives each missing bound on its own, and only a derived until includes the record at it and has an idle time', function (array $arguments, array $derived, float $since, float $until, array $counts) {
    trendIngest([
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 100),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 1100),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 1200),
    ], now: TREND_START + 86400);

    $envelope = trendAnswer([...$arguments, 'buckets' => 2]);

    expect($envelope['window'])->toMatchArray(['since' => $since, 'until' => $until, 'derived' => $derived])
        ->and(array_column($envelope['result']['buckets'], 'occurrences'))->toBe($counts)
        ->and($envelope['result']['buckets'][1]['until_at'])->toEqual($until)
        ->and($envelope['notes'])->toHaveCount($derived === ['until'] ? 1 : 0);
})->with([
    'since given, until derived' => [['since' => TREND_START], ['until'], TREND_START, TREND_START + 1200, [1, 2]],
    'since derived, until given' => [['until' => TREND_START + 1200], ['since'], TREND_START + 100, TREND_START + 1200, [1, 1]],
]);

it('states the idle time once the store clock is a bucket width past the last record, and not a moment before', function (float $now, bool $idle) {
    trendIngest([
        trendRecord(RecordType::REQUEST, 'a', TREND_START),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 1200),
    ], now: $now);

    expect(trendAnswer(['buckets' => 2])['notes'])->toBe($idle ? [__('firewatch::messages.trend_idle_note', ['duration' => '10m'])] : []);
})->with([
    'exactly one width' => [TREND_START + 1800, true],
    'a second under one width' => [TREND_START + 1799, false],
]);

it('cuts the window into 12 buckets by default, and into 2 to 60 when asked', function (?int $buckets, int $rows) {
    trendIngest(trendRecords(2, 0));

    $envelope = trendAnswer(['since' => TREND_START, 'until' => TREND_START + 3600, ...($buckets === null ? [] : ['buckets' => $buckets])]);

    expect($envelope['result']['buckets'])->toHaveCount($rows)
        ->and($envelope['result']['width_ms'])->toEqual(3600000.0 / $rows)
        ->and($envelope['truncated'])->toBe([]);
})->with([
    'default' => [null, 12],
    'fewest' => [2, 2],
    'most' => [60, 60],
]);

it('gives one bucket of no width when every selected record started at one instant, and evaluates no direction', function (int $records) {
    trendIngest(array_fill(0, $records, trendRecord(RecordType::REQUEST, 'a', TREND_START)), now: TREND_START + 86400);

    $envelope = trendAnswer();

    expect($envelope['window'])->toMatchArray(['since' => TREND_START, 'until' => TREND_START, 'derived' => ['since', 'until']])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.trend_sample_too_small_summary', ['have' => 1, 'buckets' => 1, 'by' => 'occurrences', 'needed' => 4]))
        ->and($envelope['result'])->toEqual([
            'type' => 'request',
            'by' => 'occurrences',
            'width_ms' => 0.0,
            'direction' => null,
            'reason' => 'sample_too_small',
            'have' => 1,
            'needed' => 4,
            'peak' => null,
            'buckets' => [trendBucket(0, TREND_START, TREND_START, $records, $records)],
        ])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.trend_width_zero_note')])
        ->and($envelope['next'])->toBe([]);
})->with([
    'one record' => [1],
    'three at one instant' => [3],
]);

it('decides a direction from four valued buckets or more, the median of the first half against the last, the middle one dropped', function (array $counts, ?string $direction, ?int $have, ?int $peak) {
    trendIngest(trendCounts($counts));

    $result = trendAnswer(trendGrid(count($counts)))['result'];

    expect($result['direction'])->toBe($direction)
        ->and($result['reason'])->toBe($direction === null ? 'sample_too_small' : null)
        ->and($result['have'])->toBe($have)
        ->and($result['needed'])->toBe($have === null ? null : 4)
        ->and($result['peak']['index'] ?? null)->toBe($peak);
})->with([
    'three valued buckets' => [[1, 2, 3], null, 3, 2],
    'four valued buckets' => [[1, 1, 5, 5], 'rose', null, 2],
    'five, the middle one dropped' => [[1, 9, 100, 9, 1], 'held', null, 2],
    'an empty bucket skipped, not counted as 0' => [[5, 0, 5, 1, 1], 'fell', null, 0],
    'four empty buckets among three valued' => [[0, 3, 0, 4, 0, 5, 0], null, 3, 5],
    'a tie goes to the earliest' => [[2, 5, 5, 1], 'held', null, 1],
    'all valued buckets equal' => [[3, 3, 3, 3], 'held', null, null],
]);

it('moves only past both the 10% band and the noise floor of the measure', function (string $by, array $records, string $direction) {
    trendIngest($records);

    expect(trendAnswer([...trendGrid(4), 'by' => $by])['result']['direction'])->toBe($direction);
})->with([
    'a count up by 10%' => ['occurrences', fn () => trendCounts([10, 10, 11, 11]), 'held'],
    'a count up by 20%' => ['occurrences', fn () => trendCounts([10, 10, 12, 12]), 'rose'],
    'a duration up by less than 1 ms' => ['max_duration', fn () => trendDurations([100, 100, 100.5, 100.5]), 'held'],
    'a duration down by half' => ['max_duration', fn () => trendDurations([200, 200, 100, 100]), 'fell'],
    'a duration from 0 past the floor' => ['max_duration', fn () => trendDurations([0, 0, 2, 2]), 'rose'],
    'a duration from 0 within the floor' => ['max_duration', fn () => trendDurations([0, 0, 0.5, 0.5]), 'held'],
]);

it('states each measure per bucket under its field: an empty bucket 0 by occurrences and null by any other, an untimed record only in samples', function (string $by, string $field, int|float|null $value, int|float|null $untimed) {
    trendIngest([
        trendRecord(RecordType::REQUEST, 'a', TREND_START, 4000),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 1, 2000),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 2, null),
        trendRecord(RecordType::REQUEST, 'a', TREND_START + 600, null),
    ]);

    $result = trendAnswer([...trendGrid(3), 'by' => $by])['result'];

    expect($result['buckets'])->toEqual([
        trendBucket(0, TREND_START, TREND_START + 600, $value, 3, field: $field),
        trendBucket(1, TREND_START + 600, TREND_START + 1200, $untimed, 1, field: $field),
        trendBucket(2, TREND_START + 1200, TREND_START + 1800, $by === 'occurrences' ? 0 : null, 0, field: $field),
    ])
        ->and($result['buckets'][1][$field])->toBe($untimed)
        ->and($result['buckets'][2][$field])->toBe($by === 'occurrences' ? 0 : null);
})->with([
    'occurrences' => ['occurrences', 'occurrences', 3, 1],
    'max_duration' => ['max_duration', 'max_duration_ms', 4.0, null],
    'avg_duration' => ['avg_duration', 'avg_duration_ms', 3.0, null],
    'total_duration' => ['total_duration', 'total_duration_ms', 6.0, null],
]);

it('leaves a skipped scheduled task out of every duration and counts it in samples', function () {
    trendIngest([
        trendRecord(RecordType::SCHEDULED_TASK, 'a', TREND_START, 5000, ['status' => 'processed']),
        trendRecord(RecordType::SCHEDULED_TASK, 'a', TREND_START + 1, 9000, ['status' => 'skipped']),
    ]);

    $bucket = trendAnswer([...trendGrid(2), 'type' => 'scheduled-task', 'by' => 'avg_duration'])['result']['buckets'][0];

    expect($bucket)->toMatchArray(['avg_duration_ms' => 5.0, 'samples' => 2]);
});

it('states the peak memory of an execution per bucket in megabytes', function () {
    trendIngest([
        trendRecord(RecordType::JOB_ATTEMPT, 'a', TREND_START, fields: ['peak_memory_usage' => 10485760]),
        trendRecord(RecordType::JOB_ATTEMPT, 'a', TREND_START + 1, fields: ['peak_memory_usage' => 20971520]),
        trendRecord(RecordType::JOB_ATTEMPT, 'a', TREND_START + 600, fields: ['peak_memory_usage' => 5242880]),
    ]);

    $result = trendAnswer([...trendGrid(2), 'type' => 'job-attempt', 'by' => 'max_memory'])['result'];

    expect(array_column($result['buckets'], 'max_memory_mb'))->toEqual([20.0, 5.0])
        ->and($result['peak'])->toEqual(['index' => 0, 'max_memory_mb' => 20.0]);
});

it('flags the buckets that start before the coverage start partial, counts nothing before it, and leaves them out of direction and peak', function () {
    trendIngest([
        trendRecord(RecordType::REQUEST, 'a', TREND_CREATED - 100),
        ...array_map(fn (int $second) => trendRecord(RecordType::REQUEST, 'a', TREND_CREATED + $second), range(0, 9)),
        trendRecord(RecordType::REQUEST, 'a', TREND_CREATED + 150),
        ...array_fill(0, 2, trendRecord(RecordType::REQUEST, 'a', TREND_CREATED + 450)),
        ...array_fill(0, 3, trendRecord(RecordType::REQUEST, 'a', TREND_CREATED + 750)),
    ]);

    $envelope = trendAnswer(['since' => TREND_CREATED - 150, 'until' => TREND_CREATED + 1050, 'buckets' => 4]);

    expect(array_column($envelope['result']['buckets'], 'partial'))->toBe([true, false, false, false])
        ->and(array_column($envelope['result']['buckets'], 'occurrences'))->toBe([10, 1, 2, 3])
        ->and($envelope['result'])->toMatchArray(['direction' => null, 'reason' => 'sample_too_small', 'have' => 3, 'peak' => ['index' => 3, 'occurrences' => 3]])
        ->and($envelope['notes'])->toBe([trans_choice('firewatch::messages.trend_partial_note', 1, ['count' => 1, 'type' => 'request'])]);
});

it('evaluates nothing when the window ends at or before the coverage start of the type', function () {
    trendIngest([
        ...trendRecords(3, 0),
        ...trendRecords(3, 0, RecordType::QUERY, 'd'),
    ]);
    $this->travelTo(Date::createFromTimestamp(TREND_START + 1000));
    $this->artisan('firewatch:clear', ['--type' => 'request', '--force' => true])->run();
    ingest(trendRecords(3, 1200));
    $this->travelTo(Date::createFromTimestamp(TREND_NOW));

    $envelope = trendAnswer(['since' => TREND_START, 'until' => TREND_START + 600]);

    expect($envelope['window'])->toMatchArray(['since' => TREND_START, 'until' => TREND_START + 600, 'derived' => []])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.trend_outside_coverage_summary', ['type' => 'request']))
        ->and($envelope['empty'])->toBeNull()
        ->and($envelope['result'])->toEqual([
            'type' => 'request',
            'by' => 'occurrences',
            'width_ms' => null,
            'direction' => null,
            'reason' => 'outside_coverage',
            'have' => null,
            'needed' => null,
            'peak' => null,
            'buckets' => [],
        ])
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['next'])->toBe([]);
});

it('trends one group, a job group as its attempts with a note, and the dispatches when asked, and the call it offers lists that group', function () {
    trendIngest([
        ...trendCounts([1, 2, 3, 4]),
        ...trendRecords(9, 0, letter: 'b'),
        ...trendRecords(2, 0, RecordType::JOB_ATTEMPT, 'c'),
        ...trendRecords(3, 600, RecordType::JOB_ATTEMPT, 'c'),
        ...trendRecords(1, 10, RecordType::QUEUED_JOB, 'c'),
        ...trendRecords(2, 610, RecordType::QUEUED_JOB, 'c'),
    ]);

    $group = trendAnswer(['group' => trendHash('a'), ...trendGrid(4)]);
    $attempts = Envelope::assert(Trend::class, ['group' => trendHash('c'), ...trendGrid(2)]);
    $dispatches = Envelope::assert(Trend::class, ['group' => trendHash('c'), 'type' => 'queued-job', ...trendGrid(2)]);

    expect(array_column($group['result']['buckets'], 'occurrences'))->toBe([1, 2, 3, 4])
        ->and($group['next'][0]['arguments'])->toEqual(['group' => trendHash('a'), 'since' => TREND_START + 1800, 'until' => TREND_START + 2400])
        ->and($attempts['result']['type'])->toBe('job-attempt')
        ->and(array_column($attempts['result']['buckets'], 'occurrences'))->toBe([2, 3])
        ->and($attempts['notes'])->toBe([__('firewatch::messages.rank_job_group', ['group' => trendHash('c')])])
        ->and($attempts['next'][0]['arguments'])->toEqual(['group' => trendHash('c'), 'since' => TREND_START + 600, 'until' => TREND_START + 1200])
        ->and(array_column($dispatches['result']['buckets'], 'occurrences'))->toBe([1, 2])
        ->and($dispatches['notes'])->toBe([])
        ->and($dispatches['next'][0]['arguments'])->toEqual(['group' => trendHash('c'), 'type' => 'queued-job', 'since' => TREND_START + 600, 'until' => TREND_START + 1200]);

    foreach ([$group, $attempts, $dispatches] as $envelope) {
        expect(trendFollow($envelope['next'][0])['empty'])->toBeNull();
    }
});

it('counts only the records of one deploy, alone or with a group, and the call it offers keeps the deploy', function (array $arguments, array $selector) {
    trendIngest([
        ...trendCounts([1, 2], ['deploy' => 'v1']),
        ...trendCounts([5, 5], ['deploy' => 'v2']),
    ]);

    $envelope = trendAnswer([...$arguments, ...trendGrid(2), 'deploy' => 'v1']);

    expect(array_column($envelope['result']['buckets'], 'occurrences'))->toBe([1, 2])
        ->and($envelope['next'][0]['arguments'])->toEqual([...$selector, 'deploy' => 'v1', 'since' => TREND_START + 600, 'until' => TREND_START + 1200])
        ->and(trendFollow($envelope['next'][0])['result']['rows'])->toHaveCount(2);
})->with([
    'alone' => [[], ['type' => 'request']],
    'with a group' => [['group' => trendHash('a')], ['group' => trendHash('a')]],
]);

it('answers nothing for no store, a window before every record, a type with no records and a group held by none', function () {
    $this->travelTo(Date::createFromTimestamp(TREND_CREATED));
    $missing = trendAnswer();

    trendIngest(trendRecords(3, 0));
    $emptyWindow = trendAnswer(['until' => TREND_START - 1]);
    $noMatch = trendAnswer(['type' => 'query']);
    $noGroup = Envelope::assert(Trend::class, ['group' => trendHash('e')]);

    expect($missing['empty']['kind'])->toBe('no_store')
        ->and($missing['window']['derived'])->toBe([])
        ->and($emptyWindow['empty'])->toMatchArray(['kind' => 'window_empty', 'population' => 3])
        ->and($noMatch['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 3, 'filters' => 'type: query'])])
        ->and($noGroup['empty'])->toMatchArray(['kind' => 'no_match', 'message' => __('firewatch::messages.no_match', ['population' => 3, 'filters' => 'group: '.trendHash('e')])])
        ->and($noGroup['result'])->toBe([])
        ->and($noGroup['next'])->toBe([]);

    foreach ([$missing, $emptyWindow, $noMatch] as $envelope) {
        expect($envelope['result'])->toBe([])
            ->and($envelope['next'])->toBe([])
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('visible-at-completion');
    }
});

it('refuses what it can not trend, naming what it accepts', function (array $arguments, string $key, string $argument, string $accepted, string $example, ?string $expected = null, ?string $value = null) {
    trendIngest(trendRecords(3, 0));

    $response = FirewatchServer::tool(Trend::class, $arguments);

    $response->assertHasErrors([__("firewatch::messages.{$key}", ['argument' => $argument, 'with' => 'group', 'expected' => $expected, 'value' => $value, 'accepted' => $accepted, 'example' => $example])]);
})->with(function () {
    $types = 'request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request, queued-job';
    $executions = 'occurrences, max_duration, avg_duration, total_duration, max_memory';
    $buckets = 'a whole number from 2 to 60';
    $bucketsExample = 'trend(type: "request", buckets: 12)';

    return [
        'neither type nor group' => [[], 'missing_argument', 'type', $types, 'trend(type: "request")'],
        'a type with no groups' => [['type' => 'log'], 'invalid_argument', 'type', $types, 'trend(type: "request")', 'one of the types with groups', '"log"'],
        'a group that is no group id' => [['group' => 'abc'], 'invalid_argument', 'group', 'a 32-character lowercase hex group id', 'trend(group: "<group id>")', 'a 32-character lowercase hex group id', '"abc"'],
        'a type that does not hold the group' => [['group' => trendHash('a'), 'type' => 'query'], 'conflicting_arguments', 'type', 'a type that holds the group: request', 'trend(group: "'.trendHash('a').'")'],
        'a percentile' => [['type' => 'request', 'by' => 'p95_duration'], 'invalid_argument', 'by', $executions, 'rank(type: "request", by: "p95_duration")', 'a measure per bucket; a percentile per bucket is never shown', '"p95_duration"'],
        'memory of a query' => [['type' => 'query', 'by' => 'max_memory'], 'invalid_argument', 'by', 'occurrences, max_duration, avg_duration, total_duration', 'trend(type: "query", by: "occurrences")', 'a measure of query per bucket', '"max_memory"'],
        'a duration of exceptions' => [['type' => 'exception', 'by' => 'max_duration'], 'invalid_argument', 'by', 'occurrences', 'trend(type: "exception", by: "occurrences")', 'a measure of exception per bucket', '"max_duration"'],
        'when a group was last seen' => [['type' => 'request', 'by' => 'last_seen'], 'invalid_argument', 'by', $executions, 'trend(type: "request", by: "occurrences")', 'a measure of request per bucket', '"last_seen"'],
        'one bucket' => [['type' => 'request', 'buckets' => 1], 'invalid_argument', 'buckets', $buckets, $bucketsExample, '2 to 60', '1'],
        '61 buckets' => [['type' => 'request', 'buckets' => 61], 'invalid_argument', 'buckets', $buckets, $bucketsExample, '2 to 60', '61'],
        'buckets as text' => [['type' => 'request', 'buckets' => '12'], 'invalid_argument', 'buckets', $buckets, $bucketsExample, '2 to 60', '"12"'],
        'buckets that are no whole number' => [['type' => 'request', 'buckets' => 12.5], 'invalid_argument', 'buckets', $buckets, $bucketsExample, '2 to 60', '12.5'],
        'a deploy that is no string' => [['type' => 'request', 'deploy' => 1], 'invalid_argument', 'deploy', 'an exact deploy string', 'trend(type: "request", deploy: "v1")', 'an exact deploy string', '1'],
    ];
});

it('refuses a bound that is no time, a window that does not start before it ends, an argument of another tool and one that is none', function (array $arguments, string $error) {
    $response = FirewatchServer::tool(Trend::class, ['type' => 'request', ...$arguments]);

    $response->assertHasErrors(["error: {$error}"]);
})->with([
    'a since in words' => [['since' => 'last week'], 'unreadable_time'],
    'an empty window' => [['since' => 'now', 'until' => '-1d'], 'empty_window'],
    'limit' => [['limit' => 5], 'conflicting_arguments'],
    'cursor' => [['cursor' => 'x'], 'conflicting_arguments'],
    'user_id' => [['user_id' => '1'], 'conflicting_arguments'],
    'matching' => [['matching' => 'orders'], 'conflicting_arguments'],
    'a misspelling' => [['bucket' => 4], 'invalid_argument'],
]);

it('takes avg_duration only on a trend, never on a ranking or a comparison', function (string $tool, array $arguments) {
    FirewatchServer::tool($tool, ['type' => 'request', 'by' => 'avg_duration', ...$arguments])->assertHasErrors(['error: invalid_argument']);
})->with([
    'rank' => [Rank::class, []],
    'compare' => [Compare::class, ['split_at' => TREND_START]],
]);
