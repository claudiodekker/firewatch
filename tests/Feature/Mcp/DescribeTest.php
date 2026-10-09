<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\Mcp\Catalogue;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Arr;
use Laravel\Nightwatch\Core;

/**
 * Get the clock the tests of this file read, which is also the instant the store is created.
 */
function dscClock(): float
{
    test()->travelTo('2026-09-30 14:00:00.5');

    return 1790776800.5;
}

/**
 * Store two requests of two deploys, a query, a log line and a person.
 */
function dscSeed(): void
{
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.5, 'trace_id' => 'first', 'deploy' => 'v1', 'route_path' => '/cart', 'status_code' => 200]),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776100.5, 'trace_id' => 'second', 'deploy' => 'v2', 'route_path' => '/checkout', 'status_code' => 500]),
        syntheticRecord(RecordType::QUERY)->with(['timestamp' => 1790776050.5, 'execution_id' => 'first', 'trace_id' => 'first', 'deploy' => 'v1']),
        syntheticRecord(RecordType::LOG)->with(['timestamp' => 1790776060.5, 'execution_id' => 'first', 'trace_id' => 'first', 'deploy' => 'v1', 'level' => 'error']),
        syntheticRecord(RecordType::USER)->with(['timestamp' => 1790776070.5, 'id' => '7', 'name' => 'Taylor', 'username' => 'taylor@example.com']),
    ]);
}

/**
 * Get the text a tool call answers with, whether it is an answer or an error.
 *
 * @param  array<string, mixed>  $arguments
 */
function dscText(string $tool, array $arguments): string
{
    return (fn () => $this->content())->call(FirewatchServer::tool($tool, $arguments))[0];
}

/**
 * Run the call a `next` entry offers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function dscFollow(array $call): array
{
    $tool = ['describe' => Describe::class, 'query' => Query::class, 'rank' => Rank::class][$call['tool']];

    return Envelope::assert($tool, $call['arguments']);
}

/**
 * Get the row of a column in the answer for a type.
 *
 * @param  array<string, mixed>  $envelope
 * @return array<string, mixed>
 */
function dscColumn(array $envelope, string $column): array
{
    return Arr::first($envelope['result']['columns'], fn (array $row) => $row['column'] === $column);
}

it('answers what the store holds, then the schema and the SQL tool, in full', function () {
    $clock = dscClock();
    dscSeed();
    $path = app(Configuration::class)->database;

    $envelope = Envelope::assert(Describe::class);
    $result = $envelope['result'];
    $created = ['complete_from_at' => $clock, 'complete_reason' => 'created'];

    expect(array_keys($result))->toBe(['sqlite_version', 'file_bytes', 'live_bytes', 'wal_mitigation', 'source_capture', 'last_prune_at', 'dropped_records', 'types', 'deploys', 'drift', 'dropped_batches', 'units', 'json_access', 'join_keys', 'objects', 'tables', 'sql', 'examples'])
        ->and($envelope['tool'])->toBe('describe')
        ->and($envelope['window'])->toBe(['windowed' => false, 'reason' => __('firewatch::messages.describe_window_reason')])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.describe_summary', 4, ['records' => 4, 'types' => 3, 'sql' => __('firewatch::messages.describe_sql_available')]))
        ->and($envelope['empty'])->toBeNull()
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'oldest_at' => 1790776000.5, 'newest_at' => 1790776100.5, 'records' => 4])
        ->and($result['sqlite_version'])->toBe(SQLite3::version()['versionString'])
        ->and($result['file_bytes'])->toBe(filesize($path))
        ->and($result['live_bytes'])->toBeGreaterThan(0)
        ->and($result['wal_mitigation'])->toBe(Writer::hasWalResetBug(SQLite3::version()['versionString']))
        ->and($result['source_capture'])->toBeTrue()
        ->and($result['last_prune_at'])->toBe($clock)
        ->and($result['dropped_records'])->toBe(0)
        ->and($result['types'])->toBe([
            ['type' => 'request', 'object' => 'requests', 'records' => 2, 'oldest_at' => 1790776000.5, 'newest_at' => 1790776100.5, ...$created],
            ['type' => 'command', 'object' => 'commands', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'job-attempt', 'object' => 'job_attempts', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'scheduled-task', 'object' => 'scheduled_tasks', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'query', 'object' => 'queries', 'records' => 1, 'oldest_at' => 1790776050.5, 'newest_at' => 1790776050.5, ...$created],
            ['type' => 'exception', 'object' => 'exceptions', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'log', 'object' => 'logs', 'records' => 1, 'oldest_at' => 1790776060.5, 'newest_at' => 1790776060.5, ...$created],
            ['type' => 'cache-event', 'object' => 'cache_events', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'mail', 'object' => 'mail', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'notification', 'object' => 'notifications', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'outgoing-request', 'object' => 'outgoing_requests', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
            ['type' => 'queued-job', 'object' => 'queued_jobs', 'records' => 0, 'oldest_at' => null, 'newest_at' => null, ...$created],
        ])
        ->and($result['deploys'])->toBe([
            ['deploy' => 'v2', 'first_seen_at' => 1790776100.5, 'last_seen_at' => 1790776100.5, 'records' => 1],
            ['deploy' => 'v1', 'first_seen_at' => 1790776000.5, 'last_seen_at' => 1790776060.5, 'records' => 3],
        ])
        ->and($result['drift'])->toBe([])
        ->and($result['dropped_batches'])->toBe([])
        ->and($result['units'])->toBe([
            ['unit' => 'epoch_seconds', 'meaning' => __('firewatch::messages.describe_units.epoch_seconds')],
            ['unit' => 'microseconds', 'meaning' => __('firewatch::messages.describe_units.microseconds')],
            ['unit' => 'bytes', 'meaning' => __('firewatch::messages.describe_units.bytes')],
            ['unit' => 'seconds', 'meaning' => __('firewatch::messages.describe_units.seconds')],
        ])
        ->and($result['json_access'])->toBe(__('firewatch::messages.describe_json_access'))
        ->and($result['join_keys'])->toBe(__('firewatch::messages.describe_join_keys'))
        ->and(array_column($result['objects'], 'object'))->toBe(['requests', 'commands', 'job_attempts', 'scheduled_tasks', 'queries', 'exceptions', 'logs', 'cache_events', 'mail', 'notifications', 'outgoing_requests', 'queued_jobs', 'records', 'users', 'drift', 'meta'])
        ->and($result['objects'][0])->toBe(['object' => 'requests', 'type' => 'request', 'holds' => __('firewatch::messages.objects.requests')])
        ->and($result['objects'][12])->toBe(['object' => 'records', 'type' => null, 'holds' => __('firewatch::messages.objects.records')])
        ->and(array_column($result['tables'], 'column'))->toBe(['id', 'type', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'job_id', 'user_id', 'deploy', 'server', 'data', 'kind', 'type', 'v', 'detail', 'count', 'first_seen', 'last_seen', 'key', 'value'])
        ->and($result['sql'])->toBe([
            'available' => true,
            'reason' => null,
            'ceilings' => ['statement_bytes' => 16384, 'deadline_ms' => 10000, 'row_limit' => 500, 'row_budget_bytes' => 16000, 'output_bytes' => 1048576, 'sqlite_heap_mb' => 32, 'php_memory_mb' => 64, 'cell_characters' => 2000],
            'readable' => Policy::READABLE,
            'functions' => Policy::FUNCTIONS,
        ])
        ->and(array_column($result['examples'], 'example'))->toBe([
            __('firewatch::messages.describe_examples.slowest_routes'),
            __('firewatch::messages.describe_examples.exceptions_by_class'),
            __('firewatch::messages.describe_examples.queries_per_execution'),
            __('firewatch::messages.describe_examples.execution_timeline'),
            __('firewatch::messages.describe_examples.header_names'),
        ])
        ->and(array_keys($result['tables'][0]))->toBe(['column', 'sql_type', 'wire', 'unit', 'values', 'nullable', 'indexed', 'examples', 'meaning'])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.describe_bytes_note')])
        ->and($envelope['truncated'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'kind'))->toContain('structural');
});

it('describes the users table with the people the directory holds, in full', function () {
    dscClock();
    dscSeed();

    $envelope = Envelope::assert(Describe::class, ['type' => 'user']);

    expect($envelope['summary'])->toBe(trans_choice('firewatch::messages.describe_type_summary', 1, ['object' => 'users', 'columns' => 5, 'records' => 1]))
        ->and($envelope['empty'])->toBeNull()
        ->and($envelope['coverage']['types_read'])->toBe(['user'])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial')
        ->and($envelope['result'])->toBe([
            'type' => 'user',
            'object' => 'users',
            'records' => 1,
            'group_recipe' => null,
            'columns' => [
                ['column' => 'id', 'sql_type' => 'TEXT', 'wire' => 'id', 'unit' => null, 'values' => null, 'nullable' => false, 'indexed' => true, 'examples' => ['7'], 'meaning' => __('firewatch::messages.column_meanings_in.users.id')],
                ['column' => 'name', 'sql_type' => 'TEXT', 'wire' => 'name', 'unit' => null, 'values' => null, 'nullable' => true, 'indexed' => false, 'examples' => ['Taylor'], 'meaning' => __('firewatch::messages.column_meanings_in.users.name')],
                ['column' => 'username', 'sql_type' => 'TEXT', 'wire' => 'username', 'unit' => null, 'values' => null, 'nullable' => true, 'indexed' => false, 'examples' => ['taylor@example.com'], 'meaning' => __('firewatch::messages.column_meanings.username')],
                ['column' => 'first_seen', 'sql_type' => 'REAL', 'wire' => null, 'unit' => 'epoch_seconds', 'values' => null, 'nullable' => true, 'indexed' => false, 'examples' => [1790776070.5], 'meaning' => __('firewatch::messages.column_meanings_in.users.first_seen')],
                ['column' => 'last_seen', 'sql_type' => 'REAL', 'wire' => null, 'unit' => 'epoch_seconds', 'values' => null, 'nullable' => true, 'indexed' => false, 'examples' => [1790776070.5], 'meaning' => __('firewatch::messages.column_meanings_in.users.last_seen')],
            ],
            'examples' => (new Catalogue)->examples(RecordType::USER),
        ])
        ->and($envelope['next'])->toBe([
            ['tool' => 'query', 'arguments' => ['sql' => Catalogue::TYPE_EXAMPLES['user']['user_directory']], 'why' => __('firewatch::messages.describe_next_query')],
        ]);
});

it('describes every type by its object, its recipe and three statements', function (string $type, string $object, bool $grouped) {
    dscClock();
    dscSeed();

    $envelope = Envelope::assert(Describe::class, ['type' => $type]);
    $result = $envelope['result'];

    expect($envelope['coverage']['types_read'])->toBe([$type])
        ->and($result['type'])->toBe($type)
        ->and($result['object'])->toBe($object)
        ->and(array_column($result['columns'], 'column'))->toBe(array_column((new Catalogue)->columns($object), 'column'))
        ->and($result['examples'])->toHaveCount(3)
        ->and($result['group_recipe'] !== null)->toBe($grouped);
})->with([
    'request' => ['request', 'requests', true],
    'command' => ['command', 'commands', true],
    'job-attempt' => ['job-attempt', 'job_attempts', true],
    'scheduled-task' => ['scheduled-task', 'scheduled_tasks', true],
    'query' => ['query', 'queries', true],
    'exception' => ['exception', 'exceptions', true],
    'log' => ['log', 'logs', false],
    'cache-event' => ['cache-event', 'cache_events', true],
    'mail' => ['mail', 'mail', true],
    'notification' => ['notification', 'notifications', true],
    'outgoing-request' => ['outgoing-request', 'outgoing_requests', true],
    'queued-job' => ['queued-job', 'queued_jobs', true],
    'user' => ['user', 'users', false],
]);

it('shows the recent values of a column, five at most, newest first, and never of the id or a JSON column', function () {
    dscClock();
    ingest(array_map(fn (int $index) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.5 + $index, 'route_path' => "/page-{$index}", 'status_code' => $index % 2 === 0 ? 200 : 500]), range(1, 8)));

    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);

    expect(dscColumn($envelope, 'route_path')['examples'])->toBe(['/page-8', '/page-7', '/page-6', '/page-5', '/page-4'])
        ->and(dscColumn($envelope, 'status_code')['examples'])->toBe([200, 500])
        ->and(dscColumn($envelope, 'started_at')['examples'])->toBe([1790776008.5, 1790776007.5, 1790776006.5, 1790776005.5, 1790776004.5])
        ->and(dscColumn($envelope, 'id')['examples'])->toBe([])
        ->and(dscColumn($envelope, 'data')['examples'])->toBe([])
        ->and(dscColumn($envelope, 'headers')['examples'])->toBe([])
        ->and(dscColumn($envelope, 'context')['examples'])->toBe([])
        ->and(dscColumn($envelope, 'route_methods')['examples'])->toBe([]);
});

it('draws example values from the latest 200 records only', function (int $records, bool $shown) {
    dscClock();
    $oldest = syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790700000.0, 'server' => 'oldest']);
    $later = array_map(fn (int $index) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790770000.0 + $index, 'server' => 'recent']), range(1, $records - 1));
    ingest([$oldest, ...$later]);

    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);

    expect(in_array('oldest', dscColumn($envelope, 'server')['examples'], true))->toBe($shown)
        ->and(dscColumn($envelope, 'server')['examples'][0])->toBe('recent');
})->with([
    'the 200th newest' => [200, true],
    'the 201st newest' => [201, false],
]);

it('cuts a long value to 40 characters', function () {
    dscClock();
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0, 'url' => 'http://localhost/'.str_repeat('a', 300)])]);

    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);

    expect(dscColumn($envelope, 'url')['examples'])->toBe([substr('http://localhost/'.str_repeat('a', 300), 0, 40)]);
});

it('keeps 0 and "0" apart among the values of a column', function () {
    dscClock();
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776001.5, 'status_code' => '0']),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.5, 'status_code' => 0]),
    ]);

    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);

    expect(dscColumn($envelope, 'status_code')['examples'])->toBe(['0', 0]);
});

it('lists the deploys newest first, twenty at most, and says when it cut them', function (int $deploys, bool $cut) {
    dscClock();
    ingest([
        ...array_map(fn (int $index) => syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0 + $index, 'deploy' => "deploy-{$index}"]), range(1, $deploys)),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776500.0, 'deploy' => '']),
    ]);

    $envelope = Envelope::assert(Describe::class);

    expect(array_column($envelope['result']['deploys'], 'deploy'))->toBe(array_map(fn (int $index) => "deploy-{$index}", range($deploys, $deploys - 19)))
        ->and($envelope['truncated'])->toBe($cut ? [['section' => 'deploys', 'shown' => 20, 'matched' => null, 'reason' => 'limit', 'how' => __('firewatch::messages.describe_deploys_how')]] : []);
})->with([
    'twenty deploys' => [20, false],
    'twenty-one deploys' => [21, true],
]);

it('lists what Nightwatch\'s output drifted from and the batches the store dropped', function () {
    $clock = dscClock();
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0, 'made_up' => 1])]);
    $lines = array_map(fn (int $index) => json_encode(['at' => 1790776000.5 + $index, 'kind' => 'busy', 'code' => 5, 'message' => 'database is locked', 'dropped' => $index]), range(1, 6));
    file_put_contents(dirname(app(Configuration::class)->database).'/failures.jsonl', implode("\n", $lines)."\n");

    $result = Envelope::assert(Describe::class)['result'];

    expect($result['drift'])->toBe([['kind' => 'unknown_field', 'type' => 'request', 'count' => 1, 'last_seen_at' => $clock]])
        ->and($result['dropped_records'])->toBe(21)
        ->and($result['dropped_batches'])->toBe([
            ['dropped_at' => 1790776006.5, 'kind' => 'busy', 'dropped' => 6],
            ['dropped_at' => 1790776005.5, 'kind' => 'busy', 'dropped' => 5],
            ['dropped_at' => 1790776004.5, 'kind' => 'busy', 'dropped' => 4],
            ['dropped_at' => 1790776003.5, 'kind' => 'busy', 'dropped' => 3],
            ['dropped_at' => 1790776002.5, 'kind' => 'busy', 'dropped' => 2],
        ]);
});

it('lists the drift the store notes for itself, which no record type carries', function () {
    $clock = dscClock();
    app()->instance(NightwatchInstall::class, new NightwatchInstall(version: 'v1.30.2', registeredFirst: true));
    app(Core::class)->ingest = app(Ingest::class);
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0])]);

    $envelope = Envelope::assert(Describe::class);

    expect($envelope['result']['drift'])->toBe([['kind' => 'structure', 'type' => '', 'count' => 1, 'last_seen_at' => $clock]])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.describe_summary', 1, ['records' => '1', 'types' => 1, 'sql' => __('firewatch::messages.describe_sql_available')]));
});

it('names the pass that last claimed the prune and the types stored outside the contract', function () {
    $claimed = dscClock();
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0]), ['t' => 'sandwich', 'v' => 1, 'timestamp' => 1790776001.0]]);
    $this->travel(30)->seconds();

    $envelope = Envelope::assert(Describe::class);

    expect($envelope['result']['last_prune_at'])->toBe($claimed)
        ->and($envelope['notes'])->toBe([__('firewatch::messages.describe_bytes_note'), __('firewatch::messages.describe_unknown_types', ['types' => 'sandwich'])]);
});

it('answers before anything was recorded with the shipped catalogue, saying the store is absent', function () {
    dscClock();
    $path = app(Configuration::class)->database;

    $store = Envelope::assert(Describe::class);
    $type = Envelope::assert(Describe::class, ['type' => 'request']);

    expect($store['empty'])->toBe(['kind' => 'no_store', 'population' => null, 'message' => __('firewatch::messages.no_store', ['path' => $path])])
        ->and($store['summary'])->toBe(__('firewatch::messages.describe_summary_absent'))
        ->and($store['coverage'])->toMatchArray(['state' => 'absent', 'records' => null])
        ->and(array_keys($store['result']))->toBe(['units', 'json_access', 'join_keys', 'objects', 'tables', 'sql', 'examples'])
        ->and($store['next'])->toBe([])
        ->and($type['empty']['kind'])->toBe('no_store')
        ->and($type['coverage'])->toMatchArray(['state' => 'absent', 'types_read' => ['request']])
        ->and($type['result']['records'])->toBeNull()
        ->and(array_column($type['result']['columns'], 'column'))->toBe(array_column((new Catalogue)->columns('requests'), 'column'))
        ->and(array_unique(array_map('json_encode', array_column($type['result']['columns'], 'examples'))))->toBe(['[]'])
        ->and($type['next'])->toBe([])
        ->and(dirname($path))->not->toBeDirectory();
});

it('answers an empty store with the catalogue and the store facts, saying it holds nothing', function () {
    dscClock();
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn () => null);

    $store = Envelope::assert(Describe::class);
    $type = Envelope::assert(Describe::class, ['type' => 'request']);

    expect($store['empty'])->toBe(['kind' => 'store_empty', 'population' => 0, 'message' => __('firewatch::messages.store_empty', ['path' => $path])])
        ->and($store['summary'])->toBe(__('firewatch::messages.describe_summary_empty'))
        ->and($store['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0])
        ->and(array_sum(array_column($store['result']['types'], 'records')))->toBe(0)
        ->and($store['result']['deploys'])->toBe([])
        ->and($store['result']['objects'])->toHaveCount(16)
        ->and($store['next'])->toBe([])
        ->and($type['empty']['kind'])->toBe('store_empty')
        ->and($type['summary'])->toBe(__('firewatch::messages.describe_type_summary_empty', ['object' => 'requests', 'columns' => 48]))
        ->and($type['result']['records'])->toBe(0)
        ->and(array_unique(array_map('json_encode', array_column($type['result']['columns'], 'examples'))))->toBe(['[]']);
});

it('answers a store it cannot read with the catalogue and the reason', function () {
    dscClock();
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));

    $store = Envelope::assert(Describe::class);
    $type = Envelope::assert(Describe::class, ['type' => 'exception']);

    expect($store['empty']['kind'])->toBe('store_unusable')
        ->and($store['empty']['message'])->toBe(__('firewatch::messages.store_unusable.foreign_file', ['path' => $path]))
        ->and($store['summary'])->toBe(__('firewatch::messages.describe_summary_unusable'))
        ->and($store['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'foreign_file'])
        ->and($store['result']['objects'])->toHaveCount(16)
        ->and(array_key_exists('file_bytes', $store['result']))->toBeFalse()
        ->and($type['coverage'])->toMatchArray(['state' => 'unusable', 'types_read' => ['exception']])
        ->and(array_column($type['result']['columns'], 'column'))->toBe(array_column((new Catalogue)->columns('exceptions'), 'column'));
});

it('does not write anything where the store is', function () {
    dscClock();
    dscSeed();
    $path = app(Configuration::class)->database;
    $before = [md5_file($path), scandir(dirname($path))];

    Envelope::assert(Describe::class);
    Envelope::assert(Describe::class, ['type' => 'request']);

    expect([md5_file($path), scandir(dirname($path))])->toBe($before);
});

it('says why the SQL tool cannot run, in the words the SQL tool refuses with', function () {
    dscClock();
    dscSeed();
    app()->instance(Availability::class, new Availability(sqliteVersion: fn () => null));

    $store = Envelope::assert(Describe::class);

    expect($store['result']['sql']['available'])->toBeFalse()
        ->and($store['result']['sql']['reason'])->toBe(__('firewatch::messages.sql_unavailable.sqlite3_missing'))
        ->and(array_column($store['next'], 'tool'))->not->toContain('query')
        ->and(dscText(Query::class, ['sql' => 'SELECT 1']))->toBe(__('firewatch::messages.unavailable', ['reason' => $store['result']['sql']['reason']]))
        ->and(array_column(Envelope::assert(Describe::class, ['type' => 'request'])['next'], 'tool'))->toBe(['rank']);
});

it('offers the busiest types and the first example, and every call it offers answers', function () {
    dscClock();
    dscSeed();

    $store = Envelope::assert(Describe::class);

    expect($store['next'])->toBe([
        ['tool' => 'describe', 'arguments' => ['type' => 'request'], 'why' => __('firewatch::messages.describe_next_type', ['type' => 'request'])],
        ['tool' => 'describe', 'arguments' => ['type' => 'query'], 'why' => __('firewatch::messages.describe_next_type', ['type' => 'query'])],
        ['tool' => 'describe', 'arguments' => ['type' => 'log'], 'why' => __('firewatch::messages.describe_next_type', ['type' => 'log'])],
        ['tool' => 'query', 'arguments' => ['sql' => Catalogue::EXAMPLES['slowest_routes']], 'why' => __('firewatch::messages.describe_next_query')],
    ]);

    foreach ($store['next'] as $call) {
        $answer = dscFollow($call);

        expect($answer['tool'])->toBe($call['tool']);
    }

    $request = Envelope::assert(Describe::class, ['type' => 'request']);

    expect($request['next'])->toBe([
        ['tool' => 'rank', 'arguments' => ['type' => 'request'], 'why' => __('firewatch::messages.describe_next_rank')],
        ['tool' => 'query', 'arguments' => ['sql' => Catalogue::TYPE_EXAMPLES['request']['request_status']], 'why' => __('firewatch::messages.describe_next_query')],
    ]);

    foreach ($request['next'] as $call) {
        expect(dscFollow($call)['tool'])->toBe($call['tool']);
    }
})->group('process');

it('fits the answer budget with the longest values a request can have and twenty deploys of 200 characters', function () {
    dscClock();
    $long = str_repeat('x', 2000);
    ingest(array_map(fn (int $index) => syntheticRecord(RecordType::REQUEST)->with([
        'timestamp' => 1790776000.5 + $index,
        'url' => "{$index}{$long}",
        'route_action' => "{$index}{$long}",
        'route_path' => "{$index}{$long}",
        'exception_preview' => "{$index}{$long}",
        'ip' => "{$index}{$long}",
        'server' => "{$index}{$long}",
        'route_name' => "{$index}{$long}",
        'route_domain' => "{$index}{$long}",
        'user' => "{$index}{$long}",
        'trace_id' => "{$index}{$long}",
        'deploy' => $index.str_repeat('d', 199),
    ]), range(1, 20)));

    $request = Envelope::assert(Describe::class, ['type' => 'request']);
    $store = Envelope::assert(Describe::class);

    expect($request['truncated'])->toBe([])
        ->and($store['truncated'])->toBe([])
        ->and($store['result']['deploys'])->toHaveCount(20);
});

it('refuses a type that is none of the thirteen, matching it exactly', function (mixed $type, string $shown) {
    $text = dscText(Describe::class, ['type' => $type]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'type', 'expected' => 'one of the twelve record types or user', 'value' => $shown, 'accepted' => 'request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request, queued-job, user', 'example' => 'describe(type: "request")']));
})->with([
    'a view name' => ['requests', '"requests"'],
    'a capital' => ['Request', '"Request"'],
    'an underscore' => ['job_attempt', '"job_attempt"'],
    'padding' => ['user ', '"user "'],
    'not a string' => [7, '7'],
]);

it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
    $text = dscText(Describe::class, [$argument => '1h']);

    expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'describe', 'accepted' => 'type, format', 'example' => 'describe(format: "json")']));
})->with([
    'a misspelling' => ['object', 'unknown_argument'],
    'since' => ['since', 'inapplicable_argument'],
    'until' => ['until', 'inapplicable_argument'],
    'limit' => ['limit', 'inapplicable_argument'],
]);

it('is read-only and idempotent: asking twice gives the same answer', function () {
    dscClock();
    dscSeed();

    expect(Envelope::assert(Describe::class))->toBe(Envelope::assert(Describe::class));
});
