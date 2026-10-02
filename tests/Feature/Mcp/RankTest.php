<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const RANK_AT = 1790776000.0;

function rankHash(string $letter): string
{
    return str_repeat($letter, 32);
}

/**
 * @param  array<string, mixed>  $fields
 */
function rankRecord(RecordType $type, string $letter, ?int $milliseconds, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->with([
        '_group' => strlen($letter) === 32 ? $letter : rankHash($letter),
        'duration' => $milliseconds === null ? null : $milliseconds * 1000,
        'timestamp' => RANK_AT,
        ...$fields,
    ]);
}

/**
 * Records of one group whose durations are the given milliseconds.
 *
 * @param  list<int>  $milliseconds
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function rankGroup(RecordType $type, string $letter, array $milliseconds, array $fields = []): array
{
    return array_map(fn (int $duration) => rankRecord($type, $letter, $duration, $fields), $milliseconds);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return list<array<string, mixed>>
 */
function rankRows(array $arguments = ['type' => 'request']): array
{
    return Envelope::assert(Rank::class, $arguments)['result']['groups'];
}

it('orders the groups of a type worst first by their 95th percentile, nearest rank', function () {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', range(1, 20)),
        ...rankGroup(RecordType::REQUEST, 'b', array_fill(0, 20, 100)),
        ...rankGroup(RecordType::REQUEST, 'c', [...array_fill(0, 19, 5), 900]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request']);
    $rows = $envelope['result']['groups'];

    expect(array_column($rows, 'group'))->toBe([rankHash('b'), rankHash('a'), rankHash('c')])
        ->and($rows[1])->toMatchArray(['occurrences' => 20, 'min_ms' => 1.0, 'p50_ms' => 10.0, 'avg_ms' => 10.5, 'p95_ms' => 19.0, 'max_ms' => 20.0, 'total_ms' => 210.0, 'withheld' => null])
        ->and($rows[2])->toMatchArray(['p95_ms' => 5.0, 'max_ms' => 900.0])
        ->and($envelope['result'])->toMatchArray(['type' => 'request', 'by' => 'p95_duration', 'records' => 60, 'groups_ranked' => 3, 'records_without_group' => 0])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.rank_summary', 3, ['count' => 3, 'type' => 'request', 'by' => 'p95_duration']))
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['coverage']['types_read'])->toBe(['request']);
});

it('withholds a percentile below its sample floor, and shows it at the floor', function (int $records, bool $p50, bool $p95) {
    ingest(rankGroup(RecordType::REQUEST, 'a', range(1, $records)));

    $row = rankRows()[0];

    expect($row['p50_ms'] !== null)->toBe($p50)
        ->and($row['p95_ms'] !== null)->toBe($p95)
        ->and(array_intersect_key($row['withheld'] ?? [], ['p50_ms' => 0, 'p95_ms' => 0]))->toBe(array_filter([
            'p50_ms' => $p50 ? null : ['reason' => 'sample_too_small', 'have' => $records, 'needed' => 3],
            'p95_ms' => $p95 ? null : ['reason' => 'sample_too_small', 'have' => $records, 'needed' => 20],
        ]));
})->with([
    '2 records' => [2, false, false],
    '3 records' => [3, true, false],
    '19 records' => [19, true, false],
    '20 records' => [20, true, true],
]);

it('shows the raw values, ascending, of a group with fewer than 3 records', function (array $milliseconds, ?array $values) {
    ingest(rankGroup(RecordType::REQUEST, 'a', $milliseconds));

    $rows = rankRows();

    expect($rows[0]['values_ms'])->toEqual($values);
})->with([
    'one record' => [[7], [7.0]],
    'two records' => [[9, 3], [3.0, 9.0]],
    'three records' => [[9, 3, 5], null],
]);

it('shows one record as occurrences 1 with min, average, max and total equal', function () {
    ingest([rankRecord(RecordType::REQUEST, 'a', 12)]);

    $rows = rankRows();

    expect($rows[0])->toMatchArray(['occurrences' => 1, 'min_ms' => 12.0, 'avg_ms' => 12.0, 'max_ms' => 12.0, 'total_ms' => 12.0, 'p50_ms' => null, 'p95_ms' => null]);
});

it('orders by the maximum and says so when no group reaches the floor of the percentile', function (string $by, string $statistic, int $needed) {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', [10, 20]),
        ...rankGroup(RecordType::REQUEST, 'b', [5, 90]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'by' => $by]);

    expect(array_column($envelope['result']['groups'], 'group'))->toBe([rankHash('b'), rankHash('a')])
        ->and($envelope['notes'])->toBe(["No group has enough records for {$statistic} (needed {$needed}); ordered by max."]);
})->with([
    'p95' => ['p95_duration', 'p95', 20],
    'p50' => ['p50_duration', 'p50', 3],
]);

it('sorts the groups below the floor after those above it, with no fallback', function () {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', [900, 800]),
        ...rankGroup(RecordType::REQUEST, 'b', array_fill(0, 20, 1)),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request']);

    expect(array_column($envelope['result']['groups'], 'group'))->toBe([rankHash('b'), rankHash('a')])
        ->and($envelope['notes'])->toBe([]);
});

it('breaks ties by occurrences, then by group hash', function () {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'c', [10]),
        ...rankGroup(RecordType::REQUEST, 'b', [10, 10]),
        ...rankGroup(RecordType::REQUEST, 'a', [10]),
    ]);

    expect(array_column(rankRows(['type' => 'request', 'by' => 'max_duration']), 'group'))->toBe([rankHash('b'), rankHash('a'), rankHash('c')]);
});

it('ranks by the measure asked for', function (string $by, array $order) {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', [10, 10, 10], ['timestamp' => RANK_AT + 10, 'peak_memory_usage' => 3000000, 'queries' => 1]),
        ...rankGroup(RecordType::REQUEST, 'b', [50], ['timestamp' => RANK_AT + 30, 'peak_memory_usage' => 1000000, 'queries' => 9]),
        ...rankGroup(RecordType::REQUEST, 'c', [20, 20], ['timestamp' => RANK_AT + 20, 'peak_memory_usage' => 2000000, 'queries' => 2]),
    ]);

    expect(array_column(rankRows(['type' => 'request', 'by' => $by]), 'group'))->toBe(array_map(rankHash(...), $order));
})->with([
    'max_duration' => ['max_duration', ['b', 'c', 'a']],
    'total_duration' => ['total_duration', ['b', 'c', 'a']],
    'occurrences' => ['occurrences', ['a', 'c', 'b']],
    'last_seen' => ['last_seen', ['b', 'c', 'a']],
    'max_memory' => ['max_memory', ['a', 'c', 'b']],
    'queries' => ['queries', ['b', 'c', 'a']],
]);

it('shows peak memory in megabytes and the summed query counter of executions', function () {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', [10, 10, 10], ['peak_memory_usage' => 2097152, 'queries' => 4]),
        rankRecord(RecordType::REQUEST, 'a', 10, ['peak_memory_usage' => 5242880, 'queries' => 6]),
    ]);

    $rows = rankRows();

    expect($rows[0])->toMatchArray(['max_memory_mb' => 5.0, 'p95_memory_mb' => null, 'queries' => 18])
        ->and(rankRows()[0]['withheld']['p95_memory_mb'])->toBe(['reason' => 'sample_too_small', 'have' => 4, 'needed' => 20]);
});

it('ranks exceptions by occurrences, and by when they were last seen', function () {
    ingest([
        rankRecord(RecordType::EXCEPTION, 'a', null, ['timestamp' => RANK_AT + 5]),
        rankRecord(RecordType::EXCEPTION, 'b', null, ['timestamp' => RANK_AT]),
        rankRecord(RecordType::EXCEPTION, 'b', null, ['timestamp' => RANK_AT + 1]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'exception']);

    expect(array_column($envelope['result']['groups'], 'group'))->toBe([rankHash('b'), rankHash('a')])
        ->and($envelope['result']['by'])->toBe('occurrences')
        ->and($envelope['result']['groups'][0])->toMatchArray(['occurrences' => 2, 'failure_pct' => null])
        ->and($envelope['result']['groups'][0])->not->toHaveKeys(['p95_ms', 'withheld'])
        ->and(array_column(rankRows(['type' => 'exception', 'by' => 'last_seen']), 'group'))->toBe([rankHash('a'), rankHash('b')]);
});

it('counts a record without a duration as an occurrence, and leaves it out of the durations', function () {
    ingest([
        rankRecord(RecordType::SCHEDULED_TASK, 'a', 30),
        rankRecord(RecordType::SCHEDULED_TASK, 'a', null),
        rankRecord(RecordType::SCHEDULED_TASK, 'b', null),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'scheduled-task']);
    $rows = array_column($envelope['result']['groups'], null, 'group');

    expect($rows[rankHash('a')])->toMatchArray(['occurrences' => 2, 'min_ms' => 30.0, 'max_ms' => 30.0, 'total_ms' => 30.0])
        ->and($rows[rankHash('b')])->toMatchArray(['occurrences' => 1, 'min_ms' => null, 'avg_ms' => null, 'max_ms' => null, 'total_ms' => null, 'p50_ms' => null, 'p95_ms' => null])
        ->and($envelope['notes'])->toContain('2 records without duration are counted in occurrences and left out of the duration statistics.');
});

it('computes failure_pct by the rule of each type', function (RecordType $type, array $failed, array $kept, ?float $percent, ?string $definition) {
    ingest([
        ...array_map(fn (array $fields) => rankRecord($type, 'a', 10, $fields), [...$failed, ...$kept]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => $type->value]);

    expect($envelope['result']['groups'][0]['failure_pct'])->toEqual($percent)
        ->and($envelope['result']['failure_definition'])->toBe($definition);
})->with([
    'a request, from status 400 up' => [RecordType::REQUEST, [['status_code' => 404], ['status_code' => 500]], [['status_code' => 200], ['status_code' => 399]], 50.0, 'status >= 400'],
    'an outgoing request, from status 400 up' => [RecordType::OUTGOING_REQUEST, [['status_code' => 400]], [['status_code' => 200], ['status_code' => 302], ['status_code' => 204]], 25.0, 'status >= 400'],
    'a command, a non-zero exit code' => [RecordType::COMMAND, [['exit_code' => 1]], [['exit_code' => 0], ['exit_code' => 0]], 33.3, 'exit_code <> 0'],
    'a job attempt, failed or released' => [RecordType::JOB_ATTEMPT, [['status' => 'failed'], ['status' => 'released']], [['status' => 'processed'], ['status' => 'processed']], 50.0, 'status is failed or released'],
    'a scheduled task, failed but not skipped' => [RecordType::SCHEDULED_TASK, [['status' => 'failed']], [['status' => 'skipped'], ['status' => 'processed']], 33.3, 'status is failed'],
    'a query, none' => [RecordType::QUERY, [], [[]], null, null],
    'a mail, none' => [RecordType::MAIL, [], [[]], null, null],
]);

it('leaves failure_pct null when no record of the group has the field it reads', function () {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10, ['status_code' => null])]);

    $rows = rankRows();

    expect($rows[0]['failure_pct'])->toBeNull();
});

it('labels a group from the display field of its latest record in the window', function (RecordType $type, string $field, string $label, array $extra) {
    ingest([
        rankRecord($type, 'a', 10, [$field => 'old label', 'timestamp' => RANK_AT]),
        rankRecord($type, 'a', 10, [$field => $label, 'timestamp' => RANK_AT + 10, ...$extra]),
        rankRecord($type, 'a', 10, [$field => 'newer, outside the window', 'timestamp' => RANK_AT + 20]),
    ]);

    $row = rankRows(['type' => $type->value, 'until' => RANK_AT + 15])[0];

    expect($row['label'])->toBe($label)
        ->and($row['occurrences'])->toBe(2);
})->with([
    'a request' => [RecordType::REQUEST, 'route_path', '/orders/{order}', ['method' => 'POST']],
    'a command' => [RecordType::COMMAND, 'name', 'orders:sync', []],
    'a job attempt' => [RecordType::JOB_ATTEMPT, 'name', 'App\\Jobs\\Ship', []],
    'a query' => [RecordType::QUERY, 'sql', 'select * from orders', []],
    'an outgoing request' => [RecordType::OUTGOING_REQUEST, 'host', 'api.example.test', ['method' => 'PUT']],
    'a cache event' => [RecordType::CACHE_EVENT, 'key', 'orders:1', []],
    'an exception' => [RecordType::EXCEPTION, 'class', 'RuntimeException', []],
]);

it('labels requests that matched no route as one group, and shows the method', function () {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => '', 'method' => 'DELETE'])]);

    $rows = rankRows();

    expect($rows[0])->toMatchArray(['label' => '(no route matched)', 'method' => 'DELETE']);
});

it('keeps groups of the same label and different hashes as separate rows', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => '/same']),
        rankRecord(RecordType::REQUEST, 'b', 20, ['route_path' => '/same']),
    ]);

    expect(array_column(rankRows(), 'label'))->toBe(['/same', '/same']);
});

it('reads the window by when the records started, and the deploy by its exact string', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['timestamp' => RANK_AT, 'deploy' => 'v1']),
        rankRecord(RecordType::REQUEST, 'a', 30, ['timestamp' => RANK_AT + 100, 'deploy' => 'v2']),
        rankRecord(RecordType::REQUEST, 'a', 50, ['timestamp' => RANK_AT + 200, 'deploy' => 'v2']),
    ]);

    $rows = rankRows(['type' => 'request', 'since' => RANK_AT + 100]);

    expect($rows[0])->toMatchArray(['occurrences' => 2, 'max_ms' => 50.0, 'deploys' => 1])
        ->and(rankRows(['type' => 'request', 'until' => RANK_AT + 200])[0])->toMatchArray(['occurrences' => 2, 'max_ms' => 30.0, 'deploys' => 2])
        ->and(rankRows(['type' => 'request', 'deploy' => 'v2'])[0])->toMatchArray(['occurrences' => 2, 'min_ms' => 30.0]);
});

it('shows when the group was first seen in the store, when it was last seen in the window, and its slowest execution', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['timestamp' => RANK_AT, 'trace_id' => 'first']),
        rankRecord(RecordType::REQUEST, 'a', 90, ['timestamp' => RANK_AT + 100, 'trace_id' => 'slowest']),
        rankRecord(RecordType::REQUEST, 'a', 30, ['timestamp' => RANK_AT + 200, 'trace_id' => 'last']),
    ]);

    $rows = rankRows(['type' => 'request', 'since' => RANK_AT + 50, 'until' => RANK_AT + 150]);

    expect($rows[0])->toMatchArray([
        'first_seen_at' => RANK_AT,
        'last_seen_at' => RANK_AT + 100,
        'slowest_execution_id' => 'slowest',
    ]);
});

it('counts the records of the window, the groups ranked and the records without a group', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10),
        rankRecord(RecordType::REQUEST, 'b', 10),
        rankRecord(RecordType::REQUEST, 'b', 10, ['timestamp' => RANK_AT + 500]),
        rankRecord(RecordType::REQUEST, 'c', 10)->without('_group'),
        rankRecord(RecordType::QUERY, 'a', 10),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'until' => RANK_AT + 100]);

    expect($envelope['result'])->toMatchArray(['records' => 3, 'groups_ranked' => 2, 'records_without_group' => 1]);
});

it('cuts the list at the limit, and says how to see more', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10),
        rankRecord(RecordType::REQUEST, 'b', 20),
        rankRecord(RecordType::REQUEST, 'c', 30),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'limit' => 2]);

    expect(array_column($envelope['result']['groups'], 'group'))->toBe([rankHash('c'), rankHash('b')])
        ->and($envelope['result']['groups_ranked'])->toBe(3)
        ->and($envelope['truncated'])->toHaveCount(1)
        ->and($envelope['truncated'][0])->toMatchArray(['section' => 'groups', 'shown' => 2, 'matched' => null, 'reason' => 'limit'])
        ->and($envelope['truncated'][0]['how'])->toStartWith('Call rank again with this cursor to see the rest: rank(type: "request", by: "max_duration", limit: 2, cursor: "');
});

it('answers that the store holds nothing of the type in the window, naming the filters', function () {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v1'])]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'deploy' => 'v2']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'type: request, deploy: v2']))
        ->and($envelope['result'])->toBe([])
        ->and(Envelope::assert(Rank::class, ['type' => 'request', 'since' => RANK_AT + 1])['empty']['kind'])->toBe('window_empty')
        ->and(Envelope::assert(Rank::class, ['type' => 'query'])['empty']['kind'])->toBe('no_match');
});

it('answers that the store is empty or missing', function () {
    $envelope = Envelope::assert(Rank::class, ['type' => 'request']);

    expect($envelope['empty']['kind'])->toBe('no_store');

    app(Writer::class)->transaction(fn () => null);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request']);

    expect($envelope['empty']['kind'])->toBe('store_empty');
});

it('refuses what it can not rank, naming what it accepts', function (array $arguments, string $key, string $argument, string $accepted, string $example, ?string $expected = null, ?string $value = null) {
    $response = FirewatchServer::tool(Rank::class, $arguments);

    $response->assertHasErrors([__("firewatch::messages.{$key}", ['argument' => $argument, 'expected' => $expected, 'value' => $value, 'accepted' => $accepted, 'example' => $example])]);
})->with(function () {
    $types = 'request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request, queued-job';
    $example = 'rank(type: "request", by: "p95_duration")';

    return [
        'no type' => [[], 'missing_argument', 'type', $types, $example],
        'a type with no groups' => [['type' => 'log'], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '"log"'],
        'a type spelled with an underscore' => [['type' => 'job_attempt'], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '"job_attempt"'],
        'a type in capitals' => [['type' => 'Request'], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '"Request"'],
        'a measure that is none' => [['type' => 'request', 'by' => 'p99_duration'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, p95_memory, max_memory, last_seen, queries', 'rank(type: "request", by: "p95_duration")', 'a measure of request', '"p99_duration"'],
        'a duration of exceptions' => [['type' => 'exception', 'by' => 'p95_duration'], 'invalid_argument', 'by', 'occurrences, last_seen', 'rank(type: "exception", by: "occurrences")', 'a measure of exception', '"p95_duration"'],
        'memory of a query' => [['type' => 'query', 'by' => 'p95_memory'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, last_seen', 'rank(type: "query", by: "p95_duration")', 'a measure of query', '"p95_memory"'],
        'queries of a mail' => [['type' => 'mail', 'by' => 'queries'], 'invalid_argument', 'by', 'p95_duration, p50_duration, max_duration, total_duration, occurrences, last_seen', 'rank(type: "mail", by: "p95_duration")', 'a measure of mail', '"queries"'],
        'a limit of 0' => [['type' => 'request', 'limit' => 0], 'invalid_argument', 'limit', 'a whole number from 1 to 100', 'rank(type: "request", limit: 20)', '1 to 100', '0'],
        'a limit of 101' => [['type' => 'request', 'limit' => 101], 'invalid_argument', 'limit', 'a whole number from 1 to 100', 'rank(type: "request", limit: 20)', '1 to 100', '101'],
        'a limit that is no whole number' => [['type' => 'request', 'limit' => 'ten'], 'invalid_argument', 'limit', 'a whole number from 1 to 100', 'rank(type: "request", limit: 20)', '1 to 100', '"ten"'],
        'a type that is no string' => [['type' => 5], 'invalid_argument', 'type', $types, $example, 'one of the types with groups', '5'],
    ];
});

it('accepts a limit of 1 and of 100', function (int $limit) {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10)]);

    $rows = rankRows(['type' => 'request', 'limit' => $limit]);

    expect($rows)->toHaveCount(1);
})->with(['the lowest' => 1, 'the highest' => 100]);

it('refuses an argument that is not its own, and one that is none', function (string $argument, string $code) {
    $response = FirewatchServer::tool(Rank::class, ['type' => 'request', $argument => 'x']);

    $response->assertHasErrors(["error: {$code}"]);
})->with([
    'an argument of another tool' => ['shape', 'conflicting_arguments'],
    'a misspelling' => ['sinse', 'invalid_argument'],
]);

it('refuses a window that does not start before it ends', function () {
    $response = FirewatchServer::tool(Rank::class, ['type' => 'request', 'since' => 'now', 'until' => '-1d']);

    $response->assertHasErrors(['error: empty_window']);
});

it('is read only, idempotent and closed', function () {
    expect((new Rank(app(Configuration::class), app(Reader::class), app(Conditions::class)))->description())
        ->toBe(__('firewatch::messages.tools.rank'))
        ->and(str_word_count(__('firewatch::messages.tools.rank')))->toBeLessThanOrEqual(150);
});

it('ranks the requests the real sensor recorded', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');
    $this->get('/');

    $rows = rankRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['label' => '/', 'method' => 'GET', 'occurrences' => 2, 'failure_pct' => 0, 'deploys' => 1])
        ->and($rows[0]['values_ms'])->toHaveCount(2);
});

it('leaves the duration of a skipped scheduled task out of the durations', function () {
    ingest([
        rankRecord(RecordType::SCHEDULED_TASK, 'a', 30, ['status' => 'processed']),
        rankRecord(RecordType::SCHEDULED_TASK, 'a', 900, ['status' => 'skipped']),
    ]);

    $rows = rankRows(['type' => 'scheduled-task']);

    expect($rows[0])->toMatchArray(['occurrences' => 2, 'max_ms' => 30.0, 'total_ms' => 30.0]);
});

it('shows the 95th percentile of memory at 20 records and falls back to the maximum below it', function () {
    ingest([
        ...array_map(fn (int $mb) => rankRecord(RecordType::REQUEST, 'a', 10, ['peak_memory_usage' => $mb * 1048576]), range(1, 20)),
        ...array_map(fn (int $mb) => rankRecord(RecordType::REQUEST, 'b', 10, ['peak_memory_usage' => $mb * 1048576]), [50, 2]),
    ]);

    $rows = rankRows(['type' => 'request', 'by' => 'p95_memory']);

    expect(array_column($rows, 'group'))->toBe([rankHash('a'), rankHash('b')])
        ->and($rows[0])->toMatchArray(['p95_memory_mb' => 19.0, 'max_memory_mb' => 20.0])
        ->and($rows[1]['p95_memory_mb'])->toBeNull();
});

function rankCreatedAt(): ?float
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->createdAt);
}

/**
 * @param  array<string, mixed>  $envelope
 */
function rankCursor(array $envelope): string
{
    preg_match('/cursor: "([^"]+)"/', $envelope['truncated'][0]['how'], $matches);

    return $matches[1];
}

/**
 * Twenty-five groups of one record each, the one named `0` the slowest.
 */
function rankTwentyFiveGroups(): void
{
    ingest(array_map(fn (int $number) => rankRecord(RecordType::REQUEST, str_pad(dechex($number), 32, '0', STR_PAD_LEFT), 100 - $number, ['route_path' => "/route-{$number}"]), range(0, 24)));
}

/**
 * The labels of the groups an answer lists.
 *
 * @param  array<string, mixed>  $envelope
 * @return list<string>
 */
function rankLabels(array $envelope): array
{
    return array_column($envelope['result']['groups'], 'label');
}

/**
 * The labels of the routes of twenty-five groups, from the first to the last given.
 *
 * @return list<string>
 */
function rankRoutes(int $first, int $last): array
{
    return array_map(fn (int $number) => "/route-{$number}", range($first, $last));
}

it('matches the label of a group as a case-insensitive substring', function (string $matching, array $labels) {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => '/Orders/{order}']),
        rankRecord(RecordType::REQUEST, 'b', 20, ['route_path' => '/users']),
        rankRecord(RecordType::REQUEST, 'c', 30, ['route_path' => '/100%_done']),
        rankRecord(RecordType::REQUEST, 'd', 40, ['route_path' => '']),
        rankRecord(RecordType::REQUEST, 'e', 50, ['route_path' => '/Éclair']),
    ]);

    $rows = rankRows(['type' => 'request', 'by' => 'max_duration', 'matching' => $matching]);

    expect(array_column($rows, 'label'))->toBe($labels);
})->with([
    'a substring in another case' => ['orDERS', ['/Orders/{order}']],
    'a substring in the middle' => ['ser', ['/users']],
    'a non-ASCII substring in another case' => ['éCLAIR', ['/Éclair']],
    'a percent sign, literally' => ['%', ['/100%_done']],
    'an underscore, literally' => ['_d', ['/100%_done']],
    'a substring of every label' => ['/', ['/Éclair', '/100%_done', '/users', '/Orders/{order}']],
]);

it('matches the label requests that matched no route have', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => '']),
        rankRecord(RecordType::REQUEST, 'b', 20, ['route_path' => '/users']),
    ]);

    $rows = rankRows(['type' => 'request', 'matching' => __('firewatch::messages.rank_no_route')]);

    expect(array_column($rows, 'label'))->toBe([__('firewatch::messages.rank_no_route')]);
});

it('answers that nothing matched, naming the filters', function () {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => '/orders', 'deploy' => 'v1'])]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'matching' => 'users', 'deploy' => 'v1']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'type: request, matching: users, deploy: v1']));
});

it('refuses a matching of no characters or of more than 200', function (string $matching) {
    $response = FirewatchServer::tool(Rank::class, ['type' => 'request', 'matching' => $matching]);

    $response->assertHasErrors(['error: invalid_argument']);
})->with(['empty' => '', 'too long' => str_repeat('a', 201)]);

it('accepts a matching of 1 and of 200 characters', function (int $length) {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10, ['route_path' => str_repeat('a', 200)])]);

    $rows = rankRows(['type' => 'request', 'matching' => str_repeat('a', $length)]);

    expect($rows)->toHaveCount(1);
})->with(['the shortest' => 1, 'the longest' => 200]);

it('continues a cut list with the cursor, until the list is complete', function () {
    rankTwentyFiveGroups();
    $arguments = ['type' => 'request', 'by' => 'max_duration', 'limit' => 10];

    $first = Envelope::assert(Rank::class, $arguments);
    $second = Envelope::assert(Rank::class, [...$arguments, 'cursor' => rankCursor($first)]);
    $third = Envelope::assert(Rank::class, [...$arguments, 'cursor' => rankCursor($second)]);

    expect(rankLabels($first))->toBe(rankRoutes(0, 9))
        ->and(rankLabels($second))->toBe(rankRoutes(10, 19))
        ->and(rankLabels($third))->toBe(rankRoutes(20, 24))
        ->and($third['truncated'])->toBe([])
        ->and($second['result']['groups_ranked'])->toBe(25);
});

it('pages through ties and groups without the measure in the order of one unpaged list', function (array $groups, string $by) {
    ingest(array_merge(...array_map(fn (string $letter, array $milliseconds) => rankGroup(RecordType::REQUEST, $letter, $milliseconds), array_keys($groups), $groups)));
    $arguments = ['type' => 'request', 'by' => $by];
    $expected = array_column(rankRows($arguments), 'group');
    $seen = [];

    $page = Envelope::assert(Rank::class, [...$arguments, 'limit' => 1]);

    while (true) {
        array_push($seen, ...array_column($page['result']['groups'], 'group'));

        if ($page['truncated'] === []) {
            break;
        }

        $page = Envelope::assert(Rank::class, [...$arguments, 'limit' => 1, 'cursor' => rankCursor($page)]);
    }

    expect($seen)->toBe($expected)->and($expected)->toHaveCount(count($groups));
})->with([
    'ties, then groups below the floor' => [['a' => array_fill(0, 20, 5), 'b' => array_fill(0, 20, 5), 'c' => [9, 9], 'd' => [9, 9], 'e' => [7]], 'p95_duration'],
    'the fallback to the maximum' => [['a' => [5, 5], 'b' => [5, 5], 'c' => [8], 'd' => [1, 9], 'e' => [9, 1]], 'p95_duration'],
    'the median with a null before ties' => [['a' => [4, 4, 4], 'b' => [4, 4, 4], 'c' => [4, 4], 'd' => [4]], 'p50_duration'],
]);

it('lets the limit change between pages', function () {
    rankTwentyFiveGroups();
    $first = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'limit' => 10]);

    $second = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'limit' => 3, 'cursor' => rankCursor($first)]);

    expect(rankLabels($second))->toBe(rankRoutes(10, 12));
});

it('keeps the window of the first page, so records that came after it do not show up', function () {
    rankTwentyFiveGroups();
    $this->travelTo(Date::createFromTimestamp(RANK_AT + 1000));
    $first = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'limit' => 10]);

    $this->travelTo(Date::createFromTimestamp(RANK_AT + 2000));
    ingest([rankRecord(RecordType::REQUEST, 'f', 1000, ['route_path' => '/late', 'timestamp' => RANK_AT + 1500])]);

    $second = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'limit' => 10, 'cursor' => rankCursor($first)]);

    expect(rankLabels($second))->toBe(rankRoutes(10, 19))
        ->and($second['window']['until'])->toEqual(RANK_AT + 1000);
});

it('refuses a cursor that is no cursor, or of another tool or call, or from before a rebuild', function (Closure $arrange) {
    rankTwentyFiveGroups();
    $arguments = ['type' => 'request', 'by' => 'max_duration', 'limit' => 10];
    $cursor = rankCursor(Envelope::assert(Rank::class, $arguments));
    $changed = $arrange($cursor, $arguments);

    $response = FirewatchServer::tool(Rank::class, $changed);

    $response->assertHasErrors([__('firewatch::messages.bad_cursor', ['tool' => 'rank'])]);
})->with([
    'a string that is no cursor' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => 'not a cursor']],
    'base64 of something else' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => rtrim(strtr(base64_encode('[1]'), '+/', '-_'), '=')]],
    'a cursor of another tool' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make(tool: 'occurrences', arguments: $arguments, createdAt: rankCreatedAt(), last: ['value' => 1, 'occurrences' => 1, 'hash' => 'a'], since: null, until: null)]],
    'another measure' => [fn (string $cursor, array $arguments) => [...$arguments, 'by' => 'total_duration', 'cursor' => $cursor]],
    'another type' => [fn (string $cursor, array $arguments) => [...$arguments, 'type' => 'command', 'cursor' => $cursor]],
    'another matching' => [fn (string $cursor, array $arguments) => [...$arguments, 'matching' => 'route', 'cursor' => $cursor]],
    'a rebuilt store' => [function (string $cursor, array $arguments) {
        test()->travel(1)->seconds();
        app(Writer::class)->rebuild();
        rankTwentyFiveGroups();

        return [...$arguments, 'cursor' => $cursor];
    }],
]);

it('keeps a cursor valid across a clear', function () {
    rankTwentyFiveGroups();
    $arguments = ['type' => 'request', 'by' => 'max_duration', 'limit' => 10];
    $cursor = rankCursor(Envelope::assert(Rank::class, $arguments));
    $this->artisan('firewatch:clear', ['--force' => true])->assertExitCode(0);

    $envelope = Envelope::assert(Rank::class, [...$arguments, 'cursor' => $cursor]);

    expect($envelope['empty']['kind'])->toBe('store_empty');
});

it('breaks one group down by deploy, in the order the deploys were first seen', function () {
    ingest([
        ...rankGroup(RecordType::REQUEST, 'a', [10, 30, 20], ['deploy' => 'v2', 'timestamp' => RANK_AT + 100]),
        ...rankGroup(RecordType::REQUEST, 'a', range(1, 20), ['deploy' => 'v1', 'timestamp' => RANK_AT]),
        rankRecord(RecordType::REQUEST, 'a', 5, ['deploy' => '', 'timestamp' => RANK_AT + 50])->without('deploy'),
        rankRecord(RecordType::REQUEST, 'b', 99, ['deploy' => 'other']),
    ]);

    $envelope = Envelope::assert(Rank::class, ['group' => rankHash('a')]);
    $rows = $envelope['result']['deploys'];

    expect(array_column($rows, 'deploy'))->toBe(['v1', __('firewatch::messages.rank_no_deploy'), 'v2'])
        ->and($rows[0])->toMatchArray(['occurrences' => 20, 'p50_ms' => 10.0, 'p95_ms' => 19.0, 'max_ms' => 20.0, 'first_at' => RANK_AT, 'last_at' => RANK_AT, 'withheld' => null])
        ->and($rows[1])->toMatchArray(['occurrences' => 1, 'p50_ms' => null, 'p95_ms' => null, 'max_ms' => 5.0, 'values_ms' => [5.0]])
        ->and($rows[2])->toMatchArray(['occurrences' => 3, 'p50_ms' => 20.0, 'p95_ms' => null, 'max_ms' => 30.0, 'first_at' => RANK_AT + 100])
        ->and($rows[2]['withheld'])->toBe(['p95_ms' => ['reason' => 'sample_too_small', 'have' => 3, 'needed' => 20]])
        ->and($envelope['result'])->toMatchArray(['type' => 'request', 'group' => rankHash('a'), 'label' => '/', 'records' => 24])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.rank_breakdown_summary', 3, ['group' => rankHash('a'), 'count' => 3]));
});

it('shows the most recent deploys the limit allows, still in the order they were first seen', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v1', 'timestamp' => RANK_AT]),
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v2', 'timestamp' => RANK_AT + 10]),
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v3', 'timestamp' => RANK_AT + 20]),
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v1', 'timestamp' => RANK_AT + 30]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['group' => rankHash('a'), 'limit' => 2]);

    expect(array_column($envelope['result']['deploys'], 'deploy'))->toBe(['v1', 'v3'])
        ->and($envelope['truncated'])->toBe([['section' => 'deploys', 'shown' => 2, 'matched' => 3, 'reason' => 'limit', 'how' => __('firewatch::messages.rank_truncated_how')]]);
});

it('breaks a group down within the window', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v1', 'timestamp' => RANK_AT]),
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v2', 'timestamp' => RANK_AT + 100]),
    ]);

    $envelope = Envelope::assert(Rank::class, ['group' => rankHash('a'), 'since' => RANK_AT + 50]);

    expect(array_column($envelope['result']['deploys'], 'deploy'))->toBe(['v2']);
});

it('answers that the store holds no such group, with the filters', function (array $arguments, string $filters) {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10)]);

    $envelope = Envelope::assert(Rank::class, $arguments);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => $filters]));
})->with([
    'a group of no type' => [['group' => str_repeat('f', 32)], 'group: ffffffffffffffffffffffffffffffff'],
    'a group of a type' => [['group' => str_repeat('f', 32), 'type' => 'query'], 'group: ffffffffffffffffffffffffffffffff, type: query'],
]);

it('uses the job attempts of a job group when no type is given, and says so', function () {
    ingest([
        rankRecord(RecordType::JOB_ATTEMPT, 'a', 10, ['deploy' => 'v1']),
        rankRecord(RecordType::QUEUED_JOB, 'a', 5, ['deploy' => 'v1']),
    ]);

    $envelope = Envelope::assert(Rank::class, ['group' => rankHash('a')]);
    $explicit = Envelope::assert(Rank::class, ['group' => rankHash('a'), 'type' => 'queued-job']);

    expect($envelope['result']['type'])->toBe('job-attempt')
        ->and($envelope['result']['deploys'][0]['max_ms'])->toEqual(10.0)
        ->and($envelope['notes'])->toBe([__('firewatch::messages.rank_job_group', ['group' => rankHash('a')])])
        ->and($explicit['result']['type'])->toBe('queued-job')
        ->and($explicit['result']['deploys'][0]['max_ms'])->toEqual(5.0)
        ->and($explicit['notes'])->toBe([]);
});

it('refuses what does not fit a group breakdown', function (array $arguments, string $code) {
    ingest([rankRecord(RecordType::REQUEST, 'a', 10)]);

    $response = FirewatchServer::tool(Rank::class, ['group' => rankHash('a'), ...$arguments]);

    $response->assertHasErrors(["error: {$code}"]);
})->with([
    'matching' => [['matching' => 'x'], 'conflicting_arguments'],
    'a deploy' => [['deploy' => 'v1'], 'conflicting_arguments'],
    'a cursor' => [['cursor' => 'x'], 'conflicting_arguments'],
    'queries' => [['by' => 'queries'], 'invalid_argument'],
    'a type that does not hold the group' => [['type' => 'query'], 'conflicting_arguments'],
]);

it('refuses a group that is not 32 lowercase hex characters', function (string $group) {
    $response = FirewatchServer::tool(Rank::class, ['group' => $group]);

    $response->assertHasErrors(['error: invalid_argument']);
})->with(['short' => 'abc', 'long' => str_repeat('a', 33), 'not hex' => str_repeat('g', 32), 'capitals' => str_repeat('A', 32)]);

it('points from a ranking to the breakdown of its worst group, in a call that runs', function () {
    ingest([
        rankRecord(RecordType::REQUEST, 'a', 10, ['deploy' => 'v1']),
        rankRecord(RecordType::REQUEST, 'b', 90, ['deploy' => 'v1']),
    ]);

    $envelope = Envelope::assert(Rank::class, ['type' => 'request', 'by' => 'max_duration', 'since' => RANK_AT - 1]);
    $breakdown = Envelope::assert(Rank::class, $envelope['next'][0]['arguments']);

    expect($envelope['next'])->toEqual([['tool' => 'rank', 'arguments' => ['group' => rankHash('b'), 'since' => RANK_AT - 1], 'why' => __('firewatch::messages.rank_next_group')]])
        ->and($breakdown['result']['deploys'])->toHaveCount(1);
});
