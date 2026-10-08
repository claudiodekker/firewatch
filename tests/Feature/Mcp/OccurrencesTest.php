<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const OCC_AT = 1790776000.0;

function occHash(string $letter): string
{
    return str_repeat($letter, 32);
}

/**
 * @param  array<string, mixed>  $fields
 */
function occRecord(RecordType $type, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->with(['timestamp' => OCC_AT, ...$fields]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return list<array<string, mixed>>
 */
function occRows(array $arguments): array
{
    return Envelope::assert(Occurrences::class, $arguments)['result']['rows'] ?? [];
}

/**
 * @param  array<string, mixed>  $arguments
 */
function occRefused(array $arguments, string $code): void
{
    FirewatchServer::tool(Occurrences::class, $arguments)->assertHasErrors(["error: {$code}"]);
}

/**
 * @param  array<string, mixed>  $envelope
 */
function occCursor(array $envelope): string
{
    preg_match('/cursor: "([^"]+)"/', $envelope['truncated'][0]['how'], $matches);

    return $matches[1];
}

function occCreatedAt(): ?float
{
    return app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->createdAt);
}

/**
 * Five requests, three of them tied on every measure.
 */
function occFiveRequests(): void
{
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/a', 'duration' => 30000, 'peak_memory_usage' => 3000000, 'queries' => 3, 'timestamp' => OCC_AT + 1]),
        occRecord(RecordType::REQUEST, ['route_path' => '/b', 'duration' => 20000, 'peak_memory_usage' => 2000000, 'queries' => 2, 'timestamp' => OCC_AT + 2]),
        occRecord(RecordType::REQUEST, ['route_path' => '/c', 'duration' => 20000, 'peak_memory_usage' => 2000000, 'queries' => 2, 'timestamp' => OCC_AT + 2]),
        occRecord(RecordType::REQUEST, ['route_path' => '/d', 'duration' => 20000, 'peak_memory_usage' => 2000000, 'queries' => 2, 'timestamp' => OCC_AT + 2]),
        occRecord(RecordType::REQUEST, ['route_path' => '/e', 'duration' => null, 'timestamp' => OCC_AT + 3]),
    ]);
}

it('refuses a call with no selector, naming the six', function () {
    $response = FirewatchServer::tool(Occurrences::class);

    $response->assertHasErrors([__('firewatch::messages.missing_argument', [
        'argument' => 'selector',
        'accepted' => 'group, type, execution_id, trace_id, job_id or user_id',
        'example' => 'occurrences(type: "request")',
    ])]);
});

it('lists the records of a type, newest first, and the other selectors narrow them', function (array $arguments, array $paths) {
    ingest([
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'route_path' => '/one', 'timestamp' => OCC_AT + 1, 'trace_id' => 't1', 'user' => 'u1']),
        occRecord(RecordType::REQUEST, ['_group' => occHash('b'), 'route_path' => '/two', 'timestamp' => OCC_AT + 2, 'trace_id' => 't2', 'user' => 'u2']),
        occRecord(RecordType::QUERY, ['_group' => occHash('c'), 'execution_id' => 't2', 'trace_id' => 't2', 'timestamp' => OCC_AT + 3, 'sql' => 'select 2']),
        occRecord(RecordType::JOB_ATTEMPT, ['_group' => occHash('d'), 'name' => 'Ship', 'job_id' => 'j1', 'attempt_id' => 'a1', 'timestamp' => OCC_AT + 4, 'user' => 'u1']),
    ]);

    $rows = occRows($arguments);

    expect(array_column($rows, 'name'))->toBe($paths);
})->with([
    'a type' => [['type' => 'request'], ['/two', '/one']],
    'a group' => [['group' => str_repeat('a', 32)], ['/one']],
    'an execution id, its own record and its children' => [['execution_id' => 't2'], ['select 2', '/two']],
    'a trace id' => [['trace_id' => 't1'], ['/one']],
    'a job id' => [['job_id' => 'j1'], ['Ship']],
    'a user id, of the record itself' => [['user_id' => 'u1'], ['Ship', '/one']],
    'selectors that all hold' => [['type' => 'request', 'user_id' => 'u1', 'trace_id' => 't1'], ['/one']],
    'selectors that do not all hold' => [['type' => 'request', 'user_id' => 'u2', 'trace_id' => 't1'], []],
]);

it('orders by the measure asked for, the records without it last', function (string $order, array $names) {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/a', 'duration' => 10000, 'peak_memory_usage' => 3000000, 'queries' => 1, 'timestamp' => OCC_AT + 3]),
        occRecord(RecordType::REQUEST, ['route_path' => '/b', 'duration' => 50000, 'peak_memory_usage' => 1000000, 'queries' => 9, 'timestamp' => OCC_AT + 1]),
        occRecord(RecordType::REQUEST, ['route_path' => '/c', 'duration' => null, 'peak_memory_usage' => 2000000, 'queries' => 2, 'timestamp' => OCC_AT + 2]),
    ]);

    $rows = occRows(['type' => 'request', 'order' => $order]);

    expect(array_column($rows, 'name'))->toBe($names);
})->with([
    'recent' => ['recent', ['/a', '/c', '/b']],
    'slowest' => ['slowest', ['/b', '/a', '/c']],
    'memory' => ['memory', ['/a', '/c', '/b']],
    'queries' => ['queries', ['/b', '/c', '/a']],
]);

it('orders the records of a trace by duration without a type, and the logs last', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/slow', 'trace_id' => 't1', 'duration' => 50000]),
        occRecord(RecordType::LOG, ['message' => 'Hello', 'trace_id' => 't1', 'timestamp' => OCC_AT + 5]),
        occRecord(RecordType::QUERY, ['sql' => 'select 1', 'trace_id' => 't1', 'duration' => 1000]),
    ]);

    $rows = occRows(['trace_id' => 't1', 'order' => 'slowest']);

    expect(array_column($rows, 'type'))->toBe(['request', 'query', 'log']);
});

it('keeps the records of a trace slower than a number of milliseconds without a type', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/slow', 'trace_id' => 't1', 'duration' => 50000]),
        occRecord(RecordType::QUERY, ['sql' => 'select 1', 'trace_id' => 't1', 'duration' => 1000]),
        occRecord(RecordType::LOG, ['message' => 'Hello', 'trace_id' => 't1']),
    ]);

    $rows = occRows(['trace_id' => 't1', 'slower_than_ms' => 10]);

    expect(array_column($rows, 'name'))->toBe(['/slow']);
});

it('breaks ties by the highest store id', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/first']),
        occRecord(RecordType::REQUEST, ['route_path' => '/second']),
    ]);

    $rows = occRows(['type' => 'request']);

    expect(array_column($rows, 'name'))->toBe(['/second', '/first']);
});

test('every row has its fixed fields, in order', function () {
    ingest([occRecord(RecordType::QUERY, ['_group' => occHash('c'), 'execution_id' => 'e1', 'trace_id' => 'tr1', 'user' => 'u1', 'deploy' => 'v1', 'duration' => 2500, 'sql' => 'select 1', 'file' => 'app/Order.php', 'line' => 12, 'execution_source' => 'request', 'execution_stage' => 'action'])]);

    $row = occRows(['type' => 'query'])[0];

    expect(array_keys($row))->toBe(['started_at', 'type', 'source', 'stage', 'duration_ms', 'execution_id', 'trace_id', 'group', 'name', 'location', 'user_id', 'deploy', 'detail'])
        ->and($row)->toMatchArray(['started_at' => OCC_AT, 'type' => 'query', 'source' => 'request', 'stage' => 'action', 'duration_ms' => 2.5, 'execution_id' => 'e1', 'trace_id' => 'tr1', 'group' => occHash('c'), 'name' => 'select 1', 'location' => 'app/Order.php:12', 'user_id' => 'u1', 'deploy' => 'v1']);
});

it('shows a location for queries and exceptions only, and no group or name for a log', function () {
    ingest([
        occRecord(RecordType::EXCEPTION, ['file' => 'app/Pay.php', 'line' => 7, 'class' => 'RuntimeException', 'timestamp' => OCC_AT + 1]),
        occRecord(RecordType::REQUEST, ['route_path' => '/', 'timestamp' => OCC_AT + 2]),
        occRecord(RecordType::LOG, ['level' => 'error', 'message' => 'Boom', 'trace_id' => 'x', 'timestamp' => OCC_AT + 3]),
    ]);

    $exception = occRows(['type' => 'exception'])[0];
    $request = occRows(['type' => 'request'])[0];
    $log = occRows(['type' => 'log'])[0];

    expect($exception)->toMatchArray(['location' => 'app/Pay.php:7', 'name' => 'RuntimeException'])
        ->and($request['location'])->toBeNull()
        ->and($log)->toMatchArray(['group' => null, 'name' => null, 'location' => null]);
});

it('shows the file alone as the location when no line was recorded', function () {
    ingest([occRecord(RecordType::EXCEPTION, ['file' => 'app/Pay.php', 'line' => null])]);

    $row = occRows(['type' => 'exception'])[0];

    expect($row['location'])->toBe('app/Pay.php');
});

test('each type has its own detail', function (RecordType $type, array $fields, array $detail) {
    ingest([occRecord($type, $fields)]);

    $row = occRows(['type' => $type->value])[0];

    expect($row['detail'])->toEqual($detail);
})->with([
    'a request' => [RecordType::REQUEST, ['method' => 'POST', 'url' => 'http://localhost/x', 'status_code' => 201, 'queries' => 3, 'peak_memory_usage' => 2097152], ['method' => 'POST', 'url' => 'http://localhost/x', 'status_code' => 201, 'queries' => 3, 'memory_mb' => 2.0]],
    'a command' => [RecordType::COMMAND, ['command' => 'php artisan orders:sync', 'exit_code' => 1, 'queries' => 2, 'peak_memory_usage' => 1048576], ['command' => 'php artisan orders:sync', 'exit_code' => 1, 'queries' => 2, 'memory_mb' => 1.0]],
    'a job attempt' => [RecordType::JOB_ATTEMPT, ['job_id' => 'j', 'attempt' => 2, 'status' => 'failed', 'queue' => 'q', 'connection' => 'redis', 'queries' => 1, 'peak_memory_usage' => 1048576], ['job_id' => 'j', 'attempt' => 2, 'status' => 'failed', 'queue' => 'q', 'connection' => 'redis', 'queries' => 1, 'memory_mb' => 1.0]],
    'a scheduled task' => [RecordType::SCHEDULED_TASK, ['cron' => '0 * * * *', 'status' => 'skipped', 'queries' => 0, 'peak_memory_usage' => 1048576], ['cron' => '0 * * * *', 'status' => 'skipped', 'queries' => 0, 'memory_mb' => 1.0]],
    'a query' => [RecordType::QUERY, ['sql' => 'select 1', 'connection' => 'sqlite'], ['sql' => 'select 1', 'connection' => 'sqlite', 'bindings' => null]],
    'an exception' => [RecordType::EXCEPTION, ['message' => 'Nope', 'handled' => true, 'code' => '42'], ['message' => 'Nope', 'handled' => true, 'code' => '42']],
    'a log' => [RecordType::LOG, ['level' => 'info', 'message' => 'Hello'], ['level' => 'info', 'message' => 'Hello']],
    'a cache event' => [RecordType::CACHE_EVENT, ['store' => 'redis', 'key' => 'k', 'type' => 'hit'], ['store' => 'redis', 'key' => 'k', 'event' => 'hit']],
    'a mail' => [RecordType::MAIL, ['mailer' => 'smtp', 'subject' => 'Hi'], ['mailer' => 'smtp', 'subject' => 'Hi']],
    'a notification' => [RecordType::NOTIFICATION, ['channel' => 'mail'], ['channel' => 'mail']],
    'an outgoing request' => [RecordType::OUTGOING_REQUEST, ['host' => 'api.test', 'method' => 'GET', 'url' => 'https://api.test/x', 'status_code' => 200, 'response_size' => 512], ['host' => 'api.test', 'method' => 'GET', 'url' => 'https://api.test/x', 'status_code' => 200, 'response_size_bytes' => 512]],
    'a queued job' => [RecordType::QUEUED_JOB, ['job_id' => 'j', 'connection' => 'redis', 'queue' => 'q'], ['job_id' => 'j', 'connection' => 'redis', 'queue' => 'q']],
]);

it('labels a request that matched no route', function () {
    ingest([occRecord(RecordType::REQUEST, ['route_path' => ''])]);

    $rows = occRows(['type' => 'request']);

    expect($rows[0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
});

it('filters requests and outgoing requests by method, case-insensitively', function (RecordType $type) {
    ingest([
        occRecord($type, ['method' => 'GET', 'status_code' => 200]),
        occRecord($type, ['method' => 'POST', 'status_code' => 200]),
    ]);

    $rows = occRows(['type' => $type->value, 'method' => 'post']);

    expect(array_column(array_column($rows, 'detail'), 'method'))->toBe(['POST']);
})->with([
    'a request' => RecordType::REQUEST,
    'an outgoing request' => RecordType::OUTGOING_REQUEST,
]);

it('filters by status: one status, a class or a range', function (string $status, array $codes) {
    ingest(array_map(fn (int $code) => occRecord(RecordType::REQUEST, ['status_code' => $code, 'timestamp' => OCC_AT + $code]), [200, 404, 499, 500, 503]));

    $rows = occRows(['type' => 'request', 'status' => $status]);

    expect(array_column(array_column($rows, 'detail'), 'status_code'))->toBe($codes);
})->with([
    'one status' => ['500', [500]],
    'a class' => ['5xx', [503, 500]],
    'a range' => ['400-499', [499, 404]],
    'a range of one' => ['404-404', [404]],
]);

it('refuses a status that is none of the three forms', function (string $status) {
    occRefused(['type' => 'request', 'status' => $status], 'invalid_argument');
})->with([
    'words' => 'bad',
    'a class of nothing' => '6xx',
    'a reversed range' => '500-400',
    'a short status' => '50',
    'a status with a letter' => '5a0',
]);

it('filters job attempts and scheduled tasks by outcome', function (RecordType $type, string $outcome) {
    ingest([
        occRecord($type, ['status' => 'processed', 'timestamp' => OCC_AT + 1]),
        occRecord($type, ['status' => $outcome, 'timestamp' => OCC_AT + 2]),
    ]);

    $rows = occRows(['type' => $type->value, 'outcome' => $outcome]);

    expect(array_column(array_column($rows, 'detail'), 'status'))->toBe([$outcome]);
})->with([
    'a failed job attempt' => [RecordType::JOB_ATTEMPT, 'failed'],
    'a released job attempt' => [RecordType::JOB_ATTEMPT, 'released'],
    'a skipped scheduled task' => [RecordType::SCHEDULED_TASK, 'skipped'],
]);

it('refuses an outcome the type does not have', function (string $type, string $outcome) {
    occRefused(['type' => $type, 'outcome' => $outcome], 'invalid_argument');
})->with([
    'skipped for a job attempt' => ['job-attempt', 'skipped'],
    'released for a scheduled task' => ['scheduled-task', 'released'],
    'words' => ['job-attempt', 'ok'],
]);

it('filters logs by a level and worse', function (string $level, array $messages) {
    ingest(array_map(fn (string $name) => occRecord(RecordType::LOG, ['level' => $name, 'message' => $name]), ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']));

    $rows = occRows(['type' => 'log', 'level' => $level]);

    expect(array_column(array_column($rows, 'detail'), 'message'))->toEqualCanonicalizing($messages);
})->with([
    'debug is every level' => ['debug', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
    'warning and worse' => ['warning', ['warning', 'error', 'critical', 'alert', 'emergency']],
    'emergency alone' => ['emergency', ['emergency']],
]);

it('keeps the records slower than a number of milliseconds', function (float|int $milliseconds, array $names) {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/slow', 'duration' => 250000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/edge', 'duration' => 100000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/fast', 'duration' => 10000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/none', 'duration' => null]),
    ]);

    $rows = occRows(['type' => 'request', 'slower_than_ms' => $milliseconds, 'order' => 'slowest']);

    expect(array_column($rows, 'name'))->toBe($names);
})->with([
    'strictly slower' => [100, ['/slow']],
    'a fraction' => [99.5, ['/slow', '/edge']],
    'zero' => [0, ['/slow', '/edge', '/fast']],
]);

it('keeps the records matching a substring of the fields of the type, and says which field matched', function (RecordType $type, array $fields, string $matching, string $matchedOn) {
    ingest([occRecord($type, $fields), occRecord($type, [])]);

    $rows = occRows(['type' => $type->value, 'matching' => $matching]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['matched_on'])->toBe($matchedOn);
})->with([
    'a request, by its route path' => [RecordType::REQUEST, ['route_path' => '/Needle/{x}'], 'needle', 'route_path'],
    'a request, by its url' => [RecordType::REQUEST, ['url' => 'http://localhost/needle-url'], 'needle-url', 'url'],
    'a request, by its route action' => [RecordType::REQUEST, ['route_action' => 'App\\Needle@show'], 'App\\Needle', 'route_action'],
    'a command, by its command' => [RecordType::COMMAND, ['command' => 'php artisan needle'], 'artisan needle', 'command'],
    'a query, by its sql' => [RecordType::QUERY, ['sql' => 'select needle from t'], 'select needle', 'sql'],
    'an exception, by its message' => [RecordType::EXCEPTION, ['message' => 'a needle appeared'], 'needle', 'message'],
    'an exception, by its file' => [RecordType::EXCEPTION, ['file' => '/app/Needle.php'], 'needle.php', 'file'],
    'a log, by its message' => [RecordType::LOG, ['message' => 'the needle'], 'needle', 'message'],
    'a cache event, by its key' => [RecordType::CACHE_EVENT, ['key' => 'needle:1'], 'needle', 'key'],
    'an outgoing request, by its host' => [RecordType::OUTGOING_REQUEST, ['host' => 'needle.test'], 'needle', 'host'],
    'a mail, by its subject' => [RecordType::MAIL, ['subject' => 'The needle'], 'needle', 'subject'],
    'a notification, by its class' => [RecordType::NOTIFICATION, ['class' => 'App\\Needle'], 'needle', 'class'],
    'a queued job, by its name' => [RecordType::QUEUED_JOB, ['name' => 'App\\Jobs\\Needle'], 'needle', 'name'],
]);

it('names the first field that matched, in the order the fields are listed', function () {
    ingest([occRecord(RecordType::REQUEST, ['route_path' => '/needle', 'url' => 'http://localhost/needle'])]);

    $rows = occRows(['type' => 'request', 'matching' => 'needle']);

    expect($rows[0]['matched_on'])->toBe('route_path');
});

it('names no field when the record matched on one that holds no text', function () {
    ingest([occRecord(RecordType::LOG, ['message' => 12345])]);

    $rows = occRows(['type' => 'log', 'matching' => '234']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['matched_on'])->toBeNull();
});

it('reads a matching as plain text', function (string $matching, array $messages) {
    ingest([
        occRecord(RecordType::LOG, ['message' => '100% done']),
        occRecord(RecordType::LOG, ['message' => '1000 done']),
        occRecord(RecordType::LOG, ['message' => "it's done"]),
    ]);

    $rows = occRows(['type' => 'log', 'matching' => $matching]);

    expect(array_column(array_column($rows, 'detail'), 'message'))->toBe($messages);
})->with([
    'a percent sign' => ['0% d', ['100% done']],
    'a quote' => ["it's", ["it's done"]],
]);

it('restricts the records to the exact deploy it was given, whatever its characters', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/quoted', 'deploy' => "v'1"]),
        occRecord(RecordType::REQUEST, ['route_path' => '/plain', 'deploy' => 'v1']),
    ]);

    $rows = occRows(['type' => 'request', 'deploy' => "v'1"]);

    expect(array_column($rows, 'name'))->toBe(['/quoted']);
});

it('has no matched_on without a matching', function () {
    ingest([occRecord(RecordType::LOG)]);

    $rows = occRows(['type' => 'log']);

    expect($rows[0])->not->toHaveKey('matched_on');
});

it('refuses an argument of the wrong kind or value', function (array $arguments) {
    occRefused($arguments, 'invalid_argument');
})->with([
    'a short group' => [['group' => 'abc']],
    'capitals in a group' => [['group' => str_repeat('A', 32)]],
    'a group that is no text' => [['group' => 5]],
    'a type with an underscore' => [['type' => 'job_attempt']],
    'a type that is no text' => [['type' => 5]],
    'the user directory' => [['type' => 'user']],
    'an empty id' => [['trace_id' => '']],
    'an id that is no text' => [['execution_id' => 5]],
    'an order that is none' => [['type' => 'request', 'order' => 'oldest']],
    'an order that is no text' => [['type' => 'request', 'order' => 5]],
    'a limit of 0' => [['type' => 'request', 'limit' => 0]],
    'a limit of 101' => [['type' => 'request', 'limit' => 101]],
    'a limit that is text' => [['type' => 'request', 'limit' => 'ten']],
    'a deploy that is no text' => [['type' => 'request', 'deploy' => 5]],
    'a method that is no text' => [['type' => 'request', 'method' => 5]],
    'a level that is none' => [['type' => 'log', 'level' => 'loud']],
    'a level that is no text' => [['type' => 'log', 'level' => 5]],
    'an outcome that is none' => [['type' => 'job-attempt', 'outcome' => 'ok']],
    'an at_or_above that is none' => [['type' => 'request', 'at_or_above' => 'p99']],
    'a negative slower_than_ms' => [['type' => 'request', 'slower_than_ms' => -1]],
    'a slower_than_ms that is text' => [['type' => 'request', 'slower_than_ms' => 'slow']],
    'a slower_than_ms that is a boolean' => [['type' => 'request', 'slower_than_ms' => true]],
    'an empty matching' => [['type' => 'log', 'matching' => '']],
    'a matching of 201 characters' => [['type' => 'log', 'matching' => str_repeat('a', 201)]],
]);

it('accepts a matching of 200 characters', function () {
    ingest([occRecord(RecordType::LOG, ['message' => str_repeat('a', 200)])]);

    $rows = occRows(['type' => 'log', 'matching' => str_repeat('a', 200)]);

    expect($rows)->toHaveCount(1);
});

it('refuses a filter that does not fit the type, naming what it fits', function (array $arguments, string $argument, string $accepted) {
    $response = FirewatchServer::tool(Occurrences::class, $arguments);

    $response->assertHasErrors([__('firewatch::messages.conflicting_arguments', [
        'argument' => $argument,
        'with' => $arguments['type'],
        'accepted' => $accepted,
        'example' => 'occurrences(type: "request")',
    ])]);
})->with([
    'a method of a query' => [['type' => 'query', 'method' => 'GET'], 'method', 'a call with `type` request or outgoing-request'],
    'a status of a log' => [['type' => 'log', 'status' => '500'], 'status', 'a call with `type` request or outgoing-request'],
    'an outcome of a request' => [['type' => 'request', 'outcome' => 'failed'], 'outcome', 'a call with `type` job-attempt or scheduled-task'],
    'a level of a request' => [['type' => 'request', 'level' => 'error'], 'level', 'a call with `type` log'],
    'a duration of an exception' => [['type' => 'exception', 'slower_than_ms' => 5], 'slower_than_ms', 'a call with a timed `type`'],
    'a duration of a log' => [['type' => 'log', 'slower_than_ms' => 5], 'slower_than_ms', 'a call with a timed `type`'],
    'the slowest of an exception' => [['type' => 'exception', 'order' => 'slowest'], 'order', 'a call with a timed `type`'],
    'memory of a query' => [['type' => 'query', 'order' => 'memory'], 'order', 'a call with `type` request, command, job-attempt or scheduled-task'],
    'queries of a mail' => [['type' => 'mail', 'order' => 'queries'], 'order', 'a call with `type` request, command, job-attempt or scheduled-task'],
    'a baseline of a log' => [['type' => 'log', 'at_or_above' => 'p95'], 'at_or_above', 'a call with a timed `type`'],
]);

it('refuses a filter that needs a type when no type is given or found', function (array $arguments) {
    occRefused([...$arguments, 'trace_id' => 't1'], 'conflicting_arguments');
})->with([
    'a method' => [['method' => 'GET']],
    'a status' => [['status' => '500']],
    'an outcome' => [['outcome' => 'failed']],
    'a level' => [['level' => 'error']],
    'memory' => [['order' => 'memory']],
    'a matching' => [['matching' => 'x']],
    'at_or_above' => [['at_or_above' => 'p95']],
]);

it('takes the type of a group that one type holds', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'status_code' => 500]),
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'status_code' => 200]),
    ]);

    $rows = occRows(['group' => occHash('a'), 'status' => '5xx']);

    expect(array_column(array_column($rows, 'detail'), 'status_code'))->toBe([500]);
});

it('applies a matching to the type of a group that one type holds, and says which field matched', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'url' => 'http://localhost/needle']),
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'url' => 'http://localhost/hay']),
    ]);

    $rows = occRows(['group' => occHash('a'), 'matching' => 'needle']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['matched_on'])->toBe('url');
});

it('lists the dispatches and the attempts of a job group together', function () {
    ingest([
        occRecord(RecordType::QUEUED_JOB, ['_group' => occHash('a'), 'timestamp' => OCC_AT]),
        occRecord(RecordType::JOB_ATTEMPT, ['_group' => occHash('a'), 'timestamp' => OCC_AT + 1]),
    ]);

    $rows = occRows(['group' => occHash('a')]);

    expect(array_column($rows, 'type'))->toBe(['job-attempt', 'queued-job']);
});

it('refuses a type that does not hold the group', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    $response = FirewatchServer::tool(Occurrences::class, ['group' => occHash('a'), 'type' => 'query']);

    $response->assertHasErrors([__('firewatch::messages.conflicting_arguments', [
        'argument' => 'group',
        'with' => 'type',
        'accepted' => 'a `type` that holds the group: request',
        'example' => 'occurrences(type: "request")',
    ])]);
});

it('answers that nothing matched a group the store does not hold', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    $envelope = Envelope::assert(Occurrences::class, ['group' => occHash('f')]);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'group: '.occHash('f')]));
});

it('judges a filter by the type the group is held by', function (array $arguments, ?string $refused) {
    ingest([
        occRecord(RecordType::REQUEST, ['_group' => occHash('a')]),
        occRecord(RecordType::JOB_ATTEMPT, ['_group' => occHash('b'), 'status' => 'failed']),
        occRecord(RecordType::QUEUED_JOB, ['_group' => occHash('b')]),
    ]);

    $refused === null
        ? expect(occRows($arguments))->toHaveCount(1)
        : occRefused($arguments, $refused);
})->with([
    'an outcome of a request group' => [['group' => str_repeat('a', 32), 'outcome' => 'failed'], 'conflicting_arguments'],
    'a matching of a job group, which holds two types' => [['group' => str_repeat('b', 32), 'matching' => 'x'], 'conflicting_arguments'],
    'an outcome of a job group, which holds two types' => [['group' => str_repeat('b', 32), 'outcome' => 'failed'], 'conflicting_arguments'],
    'an outcome of a job group with its attempt type' => [['group' => str_repeat('b', 32), 'type' => 'job-attempt', 'outcome' => 'failed'], null],
    'an outcome the job-attempt type does not have' => [['group' => str_repeat('b', 32), 'type' => 'job-attempt', 'outcome' => 'skipped'], 'invalid_argument'],
]);

it('keeps the records at or above the median or the 95th percentile of the selection, ties kept', function (string $percentile, int $records, array $kept, ?float $threshold) {
    ingest(array_map(fn (int $milliseconds) => occRecord(RecordType::REQUEST, ['route_path' => "/{$milliseconds}", 'duration' => $milliseconds * 1000, 'timestamp' => OCC_AT + $milliseconds]), range(1, $records)));
    ingest([occRecord(RecordType::REQUEST, ['route_path' => '/outside', 'duration' => 999000, 'timestamp' => OCC_AT + 500])]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'at_or_above' => $percentile, 'until' => OCC_AT + 100, 'limit' => 100]);

    expect(array_column($envelope['result']['rows'], 'name'))->toEqualCanonicalizing(array_map(fn (int $milliseconds) => "/{$milliseconds}", $kept))
        ->and($envelope['result']['baseline'])->toEqual(['percentile' => $percentile, 'threshold_ms' => $threshold, 'samples' => $records, 'withheld' => null]);
})->with([
    'the median of 3' => ['median', 3, [2, 3], 2.0],
    'the median of 4' => ['median', 4, [2, 3, 4], 2.0],
    'the 95th percentile of 20' => ['p95', 20, [19, 20], 19.0],
]);

it('withholds the baseline below its floor, and lists everything with a note', function (string $percentile, int $records, int $needed) {
    ingest(array_map(fn (int $milliseconds) => occRecord(RecordType::REQUEST, ['duration' => $milliseconds * 1000, 'timestamp' => OCC_AT + $milliseconds]), range(1, $records)));

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'at_or_above' => $percentile, 'limit' => 100]);

    expect($envelope['result']['rows'])->toHaveCount($records)
        ->and($envelope['result']['baseline'])->toEqual(['percentile' => $percentile, 'threshold_ms' => null, 'samples' => $records, 'withheld' => ['reason' => 'sample_too_small', 'have' => $records, 'needed' => $needed]])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.occurrences_baseline_withheld', ['percentile' => $percentile, 'have' => $records, 'needed' => $needed])]);
})->with([
    'the median of 2' => ['median', 2, 3],
    'the 95th percentile of 19' => ['p95', 19, 20],
]);

it('restricts the records to a window and a deploy', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/old', 'deploy' => 'v1', 'timestamp' => OCC_AT]),
        occRecord(RecordType::REQUEST, ['route_path' => '/new', 'deploy' => 'v2', 'timestamp' => OCC_AT + 100]),
    ]);

    $since = occRows(['type' => 'request', 'since' => OCC_AT + 50]);
    $until = occRows(['type' => 'request', 'until' => OCC_AT + 100]);
    $deploy = occRows(['type' => 'request', 'deploy' => 'v1']);

    expect(array_column($since, 'name'))->toBe(['/new'])
        ->and(array_column($until, 'name'))->toBe(['/old'])
        ->and(array_column($deploy, 'name'))->toBe(['/old']);
});

it('cuts the list at the limit', function () {
    ingest(array_map(fn (int $second) => occRecord(RecordType::REQUEST, ['timestamp' => OCC_AT + $second]), range(1, 3)));

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'limit' => 2]);

    expect($envelope['result']['rows'])->toHaveCount(2)
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.occurrences_summary', 2, ['count' => 2, 'order' => 'recent']))
        ->and($envelope['truncated'][0])->toMatchArray(['section' => 'rows', 'shown' => 2, 'matched' => null, 'reason' => 'limit']);
});

it('lists a list of exactly the limit as complete', function () {
    ingest(array_map(fn (int $second) => occRecord(RecordType::REQUEST, ['timestamp' => OCC_AT + $second]), range(1, 2)));

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'limit' => 2]);

    expect($envelope['result']['rows'])->toHaveCount(2)
        ->and($envelope['truncated'])->toBe([]);
});

it('lists the distinct call sites of one query group, most frequent first, over the whole selection', function () {
    ingest([
        ...array_map(fn (int $second) => occRecord(RecordType::QUERY, ['_group' => occHash('d'), 'file' => 'app/A.php', 'line' => 1, 'timestamp' => OCC_AT + $second]), range(1, 3)),
        occRecord(RecordType::QUERY, ['_group' => occHash('d'), 'file' => 'app/B.php', 'line' => 9]),
        occRecord(RecordType::QUERY, ['_group' => occHash('e'), 'file' => 'app/C.php', 'line' => 3]),
    ]);

    $group = Envelope::assert(Occurrences::class, ['group' => occHash('d'), 'limit' => 1]);
    $type = Envelope::assert(Occurrences::class, ['type' => 'query']);

    expect($group['result']['call_sites'])->toEqual([['location' => 'app/A.php:1', 'count' => 3], ['location' => 'app/B.php:9', 'count' => 1]])
        ->and($type['result'])->not->toHaveKey('call_sites');
});

it('says a user filter reads the recorded user only', function () {
    ingest([occRecord(RecordType::REQUEST, ['user' => 'u1'])]);

    $envelope = Envelope::assert(Occurrences::class, ['user_id' => 'u1']);

    expect($envelope['notes'])->toBe([__('firewatch::messages.occurrences_user_only')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
});

it('answers that nothing matched, naming the filters', function () {
    ingest([occRecord(RecordType::REQUEST, ['status_code' => 200])]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'status' => '5xx', 'deploy' => 'v9']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'type: request, status: 5xx, deploy: v9']));
});

it('names every filter that was given when nothing matched', function (array $arguments, string $filters) {
    ingest([occRecord(RecordType::REQUEST), occRecord(RecordType::JOB_ATTEMPT), occRecord(RecordType::LOG)]);

    $envelope = Envelope::assert(Occurrences::class, $arguments);

    expect($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 3, 'filters' => $filters]));
})->with([
    'the filters of a request' => [['type' => 'request', 'trace_id' => 't9', 'method' => 'GET', 'slower_than_ms' => 5, 'at_or_above' => 'median', 'matching' => 'x'], 'type: request, trace_id: t9, method: GET, slower_than_ms: 5, at_or_above: median, matching: x'],
    'the outcome of a job attempt' => [['type' => 'job-attempt', 'outcome' => 'failed'], 'type: job-attempt, outcome: failed'],
    'the level of a log' => [['type' => 'log', 'level' => 'error'], 'type: log, level: error'],
    'the user, the job and the execution' => [['user_id' => 'u9', 'job_id' => 'j9', 'execution_id' => 'e9'], 'execution_id: e9, job_id: j9, user_id: u9'],
]);

it('answers that the window holds no records', function () {
    ingest([occRecord(RecordType::REQUEST)]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'since' => OCC_AT + 1]);

    expect($envelope['empty']['kind'])->toBe('window_empty');
});

it('answers that the store is missing or empty', function () {
    $missing = Envelope::assert(Occurrences::class, ['type' => 'request']);

    app(Writer::class)->transaction(fn () => null);

    $empty = Envelope::assert(Occurrences::class, ['type' => 'request']);

    expect($missing['empty']['kind'])->toBe('no_store')
        ->and($empty['empty']['kind'])->toBe('store_empty');
});

it('points from a list to the group of its first row, in a call that runs', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request']);
    $ranked = Envelope::assert(Rank::class, $envelope['next'][0]['arguments']);

    expect($envelope['next'])->toEqual([['tool' => 'rank', 'arguments' => ['group' => occHash('a')], 'why' => __('firewatch::messages.occurrences_next_group')]])
        ->and($ranked['empty'])->toBeNull();
});

it('points to the group over the instants its window resolved to, so the call reads the same window when it runs later', function () {
    $this->travelTo(Date::createFromTimestamp(OCC_AT + 60));
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'since' => '-2h']);
    $this->travel(3)->hours();
    $ranked = Envelope::assert(Rank::class, $envelope['next'][0]['arguments']);

    expect($envelope['next'])->toEqual([['tool' => 'rank', 'arguments' => ['group' => occHash('a'), 'since' => OCC_AT - 7140], 'why' => __('firewatch::messages.occurrences_next_group')]])
        ->and($ranked['empty'])->toBeNull();
});

it('points nowhere from the list of one group, or from a first row that has no group', function (array $arguments) {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')]), occRecord(RecordType::LOG, ['timestamp' => OCC_AT + 1])]);

    $envelope = Envelope::assert(Occurrences::class, $arguments);

    expect($envelope['next'])->toBe([]);
})->with([
    'a group' => [['type' => 'request', 'group' => str_repeat('a', 32)]],
    'a log' => [['type' => 'log']],
]);

it('lists the records the real sensor recorded', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');

    $rows = occRows(['type' => 'request']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['type' => 'request', 'name' => '/', 'source' => 'request'])
        ->and($rows[0]['detail'])->toMatchArray(['method' => 'GET', 'status_code' => 200]);
});

it('answers that the store is unusable, and refuses a bad call before reading it', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a store', 500));

    $unusable = Envelope::assert(Occurrences::class, ['type' => 'request']);

    expect($unusable['empty']['kind'])->toBe('store_unusable');

    occRefused(['type' => 'request', 'status' => 'bad'], 'invalid_argument');
});

it('describes itself in at most 150 words', function () {
    $tool = new Occurrences(app(Configuration::class), app(Reader::class), app(Conditions::class));

    expect($tool->description())->toBe(__('firewatch::messages.tools.occurrences'))
        ->and(str_word_count($tool->description()))->toBeLessThanOrEqual(150);
});

it('continues a cut list with the cursor until it is complete, in every order, ties included', function (string $order) {
    occFiveRequests();
    $arguments = ['type' => 'request', 'order' => $order, 'limit' => 2];

    $first = Envelope::assert(Occurrences::class, $arguments);
    $second = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($first)]);
    $third = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($second)]);
    $all = Envelope::assert(Occurrences::class, [...$arguments, 'limit' => 100]);
    $names = fn (array $envelope) => array_column($envelope['result']['rows'], 'name');

    expect([...$names($first), ...$names($second), ...$names($third)])->toBe($names($all))
        ->and($names($third))->toHaveCount(1)
        ->and($third['truncated'])->toBe([]);
})->with([
    'recent' => 'recent',
    'slowest' => 'slowest',
    'memory' => 'memory',
    'queries' => 'queries',
]);

it('keeps the window of the first page, and the call the cursor continues', function () {
    occFiveRequests();
    $arguments = ['type' => 'request', 'limit' => 2];

    $first = Envelope::assert(Occurrences::class, $arguments);
    ingest([occRecord(RecordType::REQUEST, ['route_path' => '/later', 'timestamp' => $first['now'] + 100])]);
    $second = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($first)]);

    expect(array_column($second['result']['rows'], 'name'))->toBe(['/c', '/b'])
        ->and($first['truncated'][0]['how'])->toStartWith(__('firewatch::messages.occurrences_cursor_how', ['call' => 'occurrences(type: "request", limit: 2, cursor: "']));
});

it('refuses a cursor that is no cursor, or of another tool or call, or from before a rebuild', function (Closure $arrange) {
    occFiveRequests();
    $arguments = ['type' => 'request', 'limit' => 2];
    $cursor = occCursor(Envelope::assert(Occurrences::class, $arguments));

    $changed = $arrange($cursor, $arguments);
    $response = FirewatchServer::tool(Occurrences::class, $changed);

    $response->assertHasErrors([__('firewatch::messages.bad_cursor', ['tool' => 'occurrences'])]);
})->with([
    'a string that is no cursor' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => 'not a cursor']],
    'a cursor of another tool' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make(tool: 'rank', arguments: $arguments, createdAt: occCreatedAt(), last: ['value' => 1, 'id' => 1], since: null, until: null)]],
    'a key that is not one' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make(tool: 'occurrences', arguments: $arguments, createdAt: occCreatedAt(), last: ['value' => 'x', 'id' => 1], since: null, until: null)]],
    'a key without an id' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make(tool: 'occurrences', arguments: $arguments, createdAt: occCreatedAt(), last: ['value' => 1], since: null, until: null)]],
    'a key with a decimal id' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make(tool: 'occurrences', arguments: $arguments, createdAt: occCreatedAt(), last: ['value' => 1, 'id' => 1.5], since: null, until: null)]],
    'another order' => [fn (string $cursor, array $arguments) => [...$arguments, 'order' => 'slowest', 'cursor' => $cursor]],
    'another type' => [fn (string $cursor, array $arguments) => [...$arguments, 'type' => 'command', 'cursor' => $cursor]],
    'a rebuilt store' => [function (string $cursor, array $arguments) {
        test()->travel(1)->seconds();
        app(Writer::class)->rebuild();
        occFiveRequests();

        return [...$arguments, 'cursor' => $cursor];
    }],
    'a rebuilt store that holds nothing yet' => [function (string $cursor, array $arguments) {
        test()->travel(1)->seconds();
        app(Writer::class)->rebuild();

        return [...$arguments, 'cursor' => $cursor];
    }],
]);

it('keeps a cursor valid across a clear', function () {
    occFiveRequests();
    $arguments = ['type' => 'request', 'limit' => 2];
    $cursor = occCursor(Envelope::assert(Occurrences::class, $arguments));

    $this->artisan('firewatch:clear', ['--force' => true])->assertExitCode(0);

    $envelope = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => $cursor]);

    expect($envelope['empty']['kind'])->toBe('store_empty');
});

it('lists a record of an unknown type beside the records it shares a trace with, as stored and without detail', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['trace_id' => 'tr1', 'timestamp' => OCC_AT - 1]),
        occRecord(RecordType::CACHE_EVENT, ['t' => 'future-type', '_group' => occHash('f'), 'trace_id' => 'tr1', 'execution_id' => 'e1', 'user' => 'u1', 'deploy' => 'v1', 'duration' => 2500]),
    ]);

    $rows = occRows(['trace_id' => 'tr1']);

    expect(array_column($rows, 'type'))->toBe(['future-type', 'request'])
        ->and($rows[0])->toEqual(['started_at' => OCC_AT, 'type' => 'future-type', 'source' => 'command', 'stage' => 'action', 'duration_ms' => 2.5, 'execution_id' => 'e1', 'trace_id' => 'tr1', 'group' => occHash('f'), 'name' => null, 'location' => null, 'user_id' => 'u1', 'deploy' => 'v1', 'detail' => []]);
});

it('lists and orders a record whose duration is not a number as one without a duration', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/slow', 'duration' => 'slow', 'timestamp' => OCC_AT + 1]),
        ...array_map(fn (int $milliseconds) => occRecord(RecordType::REQUEST, ['route_path' => "/{$milliseconds}", 'duration' => $milliseconds * 1000]), [10, 20, 30]),
    ]);
    $arguments = ['type' => 'request', 'order' => 'slowest', 'limit' => 3];

    $first = Envelope::assert(Occurrences::class, $arguments);
    $rest = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($first)]);
    $baseline = Envelope::assert(Occurrences::class, ['type' => 'request', 'at_or_above' => 'median']);
    $slower = occRows(['type' => 'request', 'slower_than_ms' => 15]);

    expect(array_column($first['result']['rows'], 'duration_ms'))->toEqual([30.0, 20.0, 10.0])
        ->and($rest['result']['rows'])->toHaveCount(1)
        ->and($rest['result']['rows'][0])->toMatchArray(['name' => '/slow', 'duration_ms' => null])
        ->and($baseline['result']['baseline'])->toEqual(['percentile' => 'median', 'threshold_ms' => 20.0, 'samples' => 3, 'withheld' => null])
        ->and(array_column($slower, 'name'))->toBe(['/30', '/20']);
});

it('lists and orders a request whose measure is not a number as one without it', function (string $order, string $field, int $value, array $detail) {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/drifted', $field => 'lots', 'timestamp' => OCC_AT + 1]),
        occRecord(RecordType::REQUEST, ['route_path' => '/counted', $field => $value]),
    ]);
    $arguments = ['type' => 'request', 'order' => $order, 'limit' => 1];

    $first = Envelope::assert(Occurrences::class, $arguments);
    $rest = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($first)]);

    expect(array_column($first['result']['rows'], 'name'))->toBe(['/counted'])
        ->and(array_column($rest['result']['rows'], 'name'))->toBe(['/drifted'])
        ->and($rest['result']['rows'][0]['detail'])->toMatchArray($detail);
})->with([
    'memory' => ['order' => 'memory', 'field' => 'peak_memory_usage', 'value' => 2097152, 'detail' => ['memory_mb' => null]],
    'queries' => ['order' => 'queries', 'field' => 'queries', 'value' => 2, 'detail' => ['queries' => 'lots']],
]);

it('lists a query whose file is not a string without a location or a call site', function () {
    ingest([
        occRecord(RecordType::QUERY, ['_group' => occHash('b'), 'file' => ['app/A.php'], 'line' => 1]),
        occRecord(RecordType::QUERY, ['_group' => occHash('b'), 'file' => 'app/B.php', 'line' => 9]),
    ]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'query', 'group' => occHash('b')]);

    expect(array_column($envelope['result']['rows'], 'location'))->toBe(['app/B.php:9', null])
        ->and($envelope['result']['call_sites'])->toBe([['location' => 'app/B.php:9', 'count' => 1]]);
});

it('lists a record the real sensor recorded without a user or a deploy with neither', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');

    $row = occRows(['type' => 'request'])[0];

    expect($row['user_id'])->toBeNull()
        ->and($row['deploy'])->toBeNull();
});

it('shows no stage for a record sent with an empty one, as the timeline does', function () {
    ingest([occRecord(RecordType::QUERY, ['execution_stage' => ''])]);

    $row = occRows(['type' => 'query'])[0];

    expect($row['stage'])->toBeNull();
});
