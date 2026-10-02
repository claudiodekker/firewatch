<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Cursor;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use SQLite3;

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

it('refuses a call with no selector, naming the six', function () {
    FirewatchServer::tool(Occurrences::class)->assertHasErrors(["error: missing_argument\n`selector` is required.\nargument: selector\naccepted: group, type, execution_id, trace_id, job_id or user_id\nexample: occurrences(type: \"request\")"]);
});

it('lists the records of a type, newest first, and the other selectors narrow them', function (array $arguments, array $paths) {
    ingest([
        occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'route_path' => '/one', 'timestamp' => OCC_AT + 1, 'trace_id' => 't1', 'user' => 'u1']),
        occRecord(RecordType::REQUEST, ['_group' => occHash('b'), 'route_path' => '/two', 'timestamp' => OCC_AT + 2, 'trace_id' => 't2', 'user' => 'u2']),
        occRecord(RecordType::QUERY, ['_group' => occHash('c'), 'execution_id' => 't2', 'trace_id' => 't2', 'timestamp' => OCC_AT + 3, 'sql' => 'select 2']),
        occRecord(RecordType::JOB_ATTEMPT, ['_group' => occHash('d'), 'name' => 'Ship', 'job_id' => 'j1', 'attempt_id' => 'a1', 'timestamp' => OCC_AT + 4, 'user' => 'u1']),
    ]);

    expect(array_column(occRows($arguments), 'name'))->toBe($paths);
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

    expect(array_column(occRows(['type' => 'request', 'order' => $order]), 'name'))->toBe($names);
})->with([
    'recent' => ['recent', ['/a', '/c', '/b']],
    'slowest' => ['slowest', ['/b', '/a', '/c']],
    'memory' => ['memory', ['/a', '/c', '/b']],
    'queries' => ['queries', ['/b', '/c', '/a']],
]);

it('breaks ties by the highest store id', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/first']),
        occRecord(RecordType::REQUEST, ['route_path' => '/second']),
    ]);

    expect(array_column(occRows(['type' => 'request']), 'name'))->toBe(['/second', '/first']);
});

it('gives every row its fixed fields, in order', function () {
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

it('shows the detail of each type', function (RecordType $type, array $fields, array $detail) {
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

    expect(occRows(['type' => 'request'])[0]['name'])->toBe('(no route matched)');
});

it('filters requests and outgoing requests by method, case-insensitively', function (RecordType $type) {
    ingest([
        occRecord($type, ['method' => 'GET', 'status_code' => 200]),
        occRecord($type, ['method' => 'POST', 'status_code' => 200]),
    ]);

    expect(array_column(array_column(occRows(['type' => $type->value, 'method' => 'post']), 'detail'), 'method'))->toBe(['POST']);
})->with([RecordType::REQUEST, RecordType::OUTGOING_REQUEST]);

it('filters by status: one status, a class or a range', function (string $status, array $codes) {
    ingest(array_map(fn (int $code) => occRecord(RecordType::REQUEST, ['status_code' => $code, 'timestamp' => OCC_AT + $code]), [200, 404, 499, 500, 503]));

    expect(array_column(array_column(occRows(['type' => 'request', 'status' => $status, 'order' => 'recent']), 'detail'), 'status_code'))->toBe($codes);
})->with([
    'one status' => ['500', [500]],
    'a class' => ['5xx', [503, 500]],
    'a range' => ['400-499', [499, 404]],
    'a range of one' => ['404-404', [404]],
]);

it('refuses a status that is none of the three forms', function (string $status) {
    occRefused(['type' => 'request', 'status' => $status], 'invalid_argument');
})->with(['words' => 'bad', 'a class of nothing' => '6xx', 'a reversed range' => '500-400', 'a short status' => '50', 'a status with a letter' => '5a0']);

it('filters job attempts and scheduled tasks by outcome', function (RecordType $type, string $outcome) {
    ingest([
        occRecord($type, ['status' => 'processed', 'timestamp' => OCC_AT + 1]),
        occRecord($type, ['status' => $outcome, 'timestamp' => OCC_AT + 2]),
    ]);

    expect(array_column(array_column(occRows(['type' => $type->value, 'outcome' => $outcome]), 'detail'), 'status'))->toBe([$outcome]);
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

    expect(array_column(array_column(occRows(['type' => 'log', 'level' => $level, 'order' => 'recent']), 'detail'), 'message'))->toEqualCanonicalizing($messages);
})->with([
    'debug is every level' => ['debug', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
    'warning and worse' => ['warning', ['warning', 'error', 'critical', 'alert', 'emergency']],
    'emergency alone' => ['emergency', ['emergency']],
]);

it('refuses a level that is none', function () {
    occRefused(['type' => 'log', 'level' => 'loud'], 'invalid_argument');
});

it('keeps the records slower than a number of milliseconds', function (float|int $milliseconds, array $names) {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/slow', 'duration' => 250000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/edge', 'duration' => 100000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/fast', 'duration' => 10000]),
        occRecord(RecordType::REQUEST, ['route_path' => '/none', 'duration' => null]),
    ]);

    expect(array_column(occRows(['type' => 'request', 'slower_than_ms' => $milliseconds, 'order' => 'slowest']), 'name'))->toBe($names);
})->with([
    'strictly slower' => [100, ['/slow']],
    'a fraction' => [99.5, ['/slow', '/edge']],
    'zero' => [0, ['/slow', '/edge', '/fast']],
]);

it('refuses a slower_than_ms that is negative or no number', function (mixed $value) {
    occRefused(['type' => 'request', 'slower_than_ms' => $value], 'invalid_argument');
})->with(['negative' => -1, 'words' => 'slow', 'true' => true]);

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

    expect(occRows(['type' => 'request', 'matching' => 'needle'])[0]['matched_on'])->toBe('route_path');
});

it('reads a matching as plain text', function () {
    ingest([occRecord(RecordType::LOG, ['message' => '100% done']), occRecord(RecordType::LOG, ['message' => '1000 done'])]);

    expect(array_column(array_column(occRows(['type' => 'log', 'matching' => '0% d']), 'detail'), 'message'))->toBe(['100% done']);
});

it('has no matched_on without a matching', function () {
    ingest([occRecord(RecordType::LOG)]);

    expect(occRows(['type' => 'log'])[0])->not->toHaveKey('matched_on');
});

it('refuses a matching of no characters or of more than 200', function (string $matching) {
    occRefused(['type' => 'log', 'matching' => $matching], 'invalid_argument');
})->with(['empty' => '', 'too long' => str_repeat('a', 201)]);

it('refuses a filter that does not fit the type, naming what it fits', function (array $arguments, string $sentence, string $accepted) {
    FirewatchServer::tool(Occurrences::class, $arguments)->assertHasErrors([__('firewatch::messages.conflicting_arguments', ['argument' => $sentence, 'with' => $arguments['type'] ?? 'group', 'accepted' => $accepted, 'example' => 'occurrences(type: "request")'])]);
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
]);

it('refuses a filter that needs a type when no type is given or found', function (array $arguments, string $argument) {
    occRefused([...$arguments, 'trace_id' => 't1'], 'conflicting_arguments');
})->with([
    'a method' => [['method' => 'GET'], 'method'],
    'a status' => [['status' => '500'], 'status'],
    'an outcome' => [['outcome' => 'failed'], 'outcome'],
    'a level' => [['level' => 'error'], 'level'],
    'memory' => [['order' => 'memory'], 'order'],
    'a matching' => [['matching' => 'x'], 'matching'],
    'at_or_above' => [['at_or_above' => 'p95'], 'at_or_above'],
]);

it('takes the type of a group that one type holds', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'status_code' => 500]), occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'status_code' => 200])]);

    expect(array_column(array_column(occRows(['group' => occHash('a'), 'status' => '5xx']), 'detail'), 'status_code'))->toBe([500]);
});

it('applies a matching to the type of a group that one type holds, and says which field matched', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'url' => 'http://localhost/needle']), occRecord(RecordType::REQUEST, ['_group' => occHash('a'), 'url' => 'http://localhost/hay'])]);

    $rows = occRows(['group' => occHash('a'), 'matching' => 'needle']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['matched_on'])->toBe('url');
});

it('lists the dispatches and the attempts of a job group together', function () {
    ingest([
        occRecord(RecordType::QUEUED_JOB, ['_group' => occHash('a'), 'timestamp' => OCC_AT]),
        occRecord(RecordType::JOB_ATTEMPT, ['_group' => occHash('a'), 'timestamp' => OCC_AT + 1]),
    ]);

    expect(array_column(occRows(['group' => occHash('a')]), 'type'))->toBe(['job-attempt', 'queued-job']);
});

it('refuses a type that does not hold the group, and answers that the store holds no such group', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    occRefused(['group' => occHash('a'), 'type' => 'query'], 'conflicting_arguments');

    $envelope = Envelope::assert(Occurrences::class, ['group' => occHash('f')]);

    expect($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 1])
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'group: '.occHash('f')]));
});

it('refuses a group that is not 32 lowercase hex characters, and a type that is none', function (array $arguments) {
    occRefused($arguments, 'invalid_argument');
})->with([
    'a short group' => [['group' => 'abc']],
    'capitals' => [['group' => str_repeat('A', 32)]],
    'a type with an underscore' => [['type' => 'job_attempt']],
    'the user directory' => [['type' => 'user']],
    'an empty id' => [['trace_id' => '']],
    'an id that is no text' => [['execution_id' => 5]],
]);

it('keeps the records at or above the median or the 95th percentile of the selection, ties kept', function (string $percentile, int $records, array $kept, ?float $threshold) {
    ingest(array_map(fn (int $milliseconds) => occRecord(RecordType::REQUEST, ['route_path' => "/{$milliseconds}", 'duration' => $milliseconds * 1000, 'timestamp' => OCC_AT + $milliseconds]), range(1, $records)));
    // A record slower than every other one, outside the selection.
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

it('refuses an at_or_above that is none', function () {
    occRefused(['type' => 'request', 'at_or_above' => 'p99'], 'invalid_argument');
});

it('refuses a call that is no valid order, limit or deploy', function (array $arguments) {
    occRefused(['type' => 'request', ...$arguments], 'invalid_argument');
})->with([
    'an order that is none' => [['order' => 'oldest']],
    'a limit of 0' => [['limit' => 0]],
    'a limit of 101' => [['limit' => 101]],
    'a limit that is text' => [['limit' => 'ten']],
    'a deploy that is no text' => [['deploy' => 5]],
]);

it('restricts the records to a window and a deploy', function () {
    ingest([
        occRecord(RecordType::REQUEST, ['route_path' => '/old', 'deploy' => 'v1', 'timestamp' => OCC_AT]),
        occRecord(RecordType::REQUEST, ['route_path' => '/new', 'deploy' => 'v2', 'timestamp' => OCC_AT + 100]),
    ]);

    expect(array_column(occRows(['type' => 'request', 'since' => OCC_AT + 50]), 'name'))->toBe(['/new'])
        ->and(array_column(occRows(['type' => 'request', 'until' => OCC_AT + 100]), 'name'))->toBe(['/old'])
        ->and(array_column(occRows(['type' => 'request', 'deploy' => 'v1']), 'name'))->toBe(['/old']);
});

it('cuts the list at the limit', function () {
    ingest(array_map(fn (int $second) => occRecord(RecordType::REQUEST, ['timestamp' => OCC_AT + $second]), range(1, 3)));

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request', 'limit' => 2]);

    expect($envelope['result']['rows'])->toHaveCount(2)
        ->and($envelope['truncated'][0])->toMatchArray(['section' => 'rows', 'shown' => 2, 'matched' => null, 'reason' => 'limit']);
});

it('lists the distinct call sites of one query group, most frequent first, over the whole selection', function () {
    ingest([
        ...array_map(fn (int $second) => occRecord(RecordType::QUERY, ['_group' => occHash('d'), 'file' => 'app/A.php', 'line' => 1, 'timestamp' => OCC_AT + $second]), range(1, 3)),
        occRecord(RecordType::QUERY, ['_group' => occHash('d'), 'file' => 'app/B.php', 'line' => 9]),
        occRecord(RecordType::QUERY, ['_group' => occHash('e'), 'file' => 'app/C.php', 'line' => 3]),
    ]);

    $envelope = Envelope::assert(Occurrences::class, ['group' => occHash('d'), 'limit' => 1]);

    expect($envelope['result']['call_sites'])->toEqual([['location' => 'app/A.php:1', 'count' => 3], ['location' => 'app/B.php:9', 'count' => 1]])
        ->and(Envelope::assert(Occurrences::class, ['type' => 'query'])['result'])->not->toHaveKey('call_sites');
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
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => 'type: request, status: 5xx, deploy: v9']))
        ->and(Envelope::assert(Occurrences::class, ['type' => 'request', 'since' => OCC_AT + 1])['empty']['kind'])->toBe('window_empty');
});

it('answers that the store is missing or empty', function () {
    expect(Envelope::assert(Occurrences::class, ['type' => 'request'])['empty']['kind'])->toBe('no_store');

    app(Writer::class)->transaction(fn () => null);

    expect(Envelope::assert(Occurrences::class, ['type' => 'request'])['empty']['kind'])->toBe('store_empty');
});

it('points from a list to the group of its first row, in a call that runs', function () {
    ingest([occRecord(RecordType::REQUEST, ['_group' => occHash('a')])]);

    $envelope = Envelope::assert(Occurrences::class, ['type' => 'request']);

    expect($envelope['next'])->toEqual([['tool' => 'rank', 'arguments' => ['group' => occHash('a')], 'why' => __('firewatch::messages.occurrences_next_group')]]);
    expect(Envelope::assert(Occurrences::class, ['type' => 'request', 'group' => occHash('a')])['next'])->toBe([]);
});

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

    expect(Envelope::assert(Occurrences::class, ['type' => 'request'])['empty']['kind'])->toBe('store_unusable');

    occRefused(['type' => 'request', 'status' => 'bad'], 'invalid_argument');
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

function occCursor(array $envelope): string
{
    preg_match('/cursor: "([^"]+)"/', $envelope['truncated'][0]['how'], $matches);

    return $matches[1];
}

function occCreatedAt(): string
{
    return (string) app(Reader::class)->snapshot(fn (SQLite3 $connection) => $connection->querySingle("SELECT value FROM meta WHERE key = 'created_at'"));
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
})->with(['recent', 'slowest', 'memory', 'queries']);

it('keeps the window of the first page, and the call the cursor continues', function () {
    occFiveRequests();
    $arguments = ['type' => 'request', 'limit' => 2];

    $first = Envelope::assert(Occurrences::class, $arguments);
    ingest([occRecord(RecordType::REQUEST, ['route_path' => '/later', 'timestamp' => $first['now'] + 100])]);
    $second = Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => occCursor($first)]);

    expect(array_column($second['result']['rows'], 'name'))->toBe(['/c', '/b'])
        ->and($first['truncated'][0]['how'])->toStartWith('Call occurrences again with this cursor to see the rest: occurrences(type: "request", limit: 2, cursor: "');
});

it('refuses a cursor that is no cursor, or of another tool or call, or from before a rebuild', function (Closure $arrange) {
    occFiveRequests();
    $arguments = ['type' => 'request', 'limit' => 2];
    $cursor = occCursor(Envelope::assert(Occurrences::class, $arguments));

    $changed = $arrange($cursor, $arguments);

    FirewatchServer::tool(Occurrences::class, $changed)->assertHasErrors([__('firewatch::messages.bad_cursor', ['tool' => 'occurrences'])]);
})->with([
    'a string that is no cursor' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => 'not a cursor']],
    'a cursor of another tool' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make('rank', $arguments, occCreatedAt(), ['value' => 1, 'id' => 1], null, null)]],
    'a key that is not one' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make('occurrences', $arguments, occCreatedAt(), ['value' => 'x', 'id' => 1], null, null)]],
    'a key without an id' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make('occurrences', $arguments, occCreatedAt(), ['value' => 1], null, null)]],
    'a key with a decimal id' => [fn (string $cursor, array $arguments) => [...$arguments, 'cursor' => Cursor::make('occurrences', $arguments, occCreatedAt(), ['value' => 1, 'id' => 1.5], null, null)]],
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

    expect(Envelope::assert(Occurrences::class, [...$arguments, 'cursor' => $cursor])['empty']['kind'])->toBe('store_empty');
});
