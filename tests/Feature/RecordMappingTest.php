<?php

use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Notifications\OrderShipped;

/**
 * @return list<array<string, mixed>>
 */
function selectFromStore(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

function forceRequestTo(string $uri): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    test()->get($uri);
}

/**
 * @param  array<string, mixed>  $record
 */
function ingestNow(array $record): void
{
    app(Core::class)->ingest->writeNow($record);
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function childRecord(string $type, array $fields = []): array
{
    return [
        'v' => 1,
        't' => $type,
        'timestamp' => 1767225600.25,
        'deploy' => 'v1.2.3',
        'server' => 'web-1',
        '_group' => str_repeat('a', 32),
        'trace_id' => 'trace-1',
        'execution_source' => 'request',
        'execution_id' => 'trace-1',
        'execution_preview' => 'GET /',
        'execution_stage' => 'action',
        'user' => '',
        ...$fields,
    ];
}

it('stores each record type the sensors write with its common columns and its fields in data', function (Closure $traffic, string $view, string $type) {
    $traffic();
    Nightwatch::digest();

    $rows = selectFromStore("SELECT * FROM {$view}");
    $types = selectFromStore("SELECT DISTINCT r.type FROM {$view} v JOIN records r USING (id)");
    $contractFields = array_values(array_diff(array_keys($rows[0]), ['id', 'v', 'started_at', 'duration', 'ended_at', 'group_hash', 'trace_id', 'execution_id', 'source', 'execution_source', 'job_id', 'user_id', 'deploy', 'server', 'data']));
    $data = json_decode($rows[0]['data'], associative: true);

    expect($rows[0])->v->not->toBeNull()
        ->started_at->toBeFloat()
        ->trace_id->not->toBeEmpty()
        ->execution_id->not->toBeEmpty()
        ->and(array_keys($data))->toEqualCanonicalizing($contractFields)
        ->and(array_column($types, 'type'))->toBe([$type]);
})->with([
    'request' => ['traffic' => fn () => forceRequestTo('/'), 'view' => 'requests', 'type' => 'request'],
    'command' => ['traffic' => fn () => runArtisan(['command' => 'env']), 'view' => 'commands', 'type' => 'command'],
    'job attempt' => ['traffic' => function () {
        config()->set('queue.default', 'database');
        dispatch(fn () => Cache::get('in-the-job'));

        runArtisan(['command' => 'queue:work', '--once' => true]);
    }, 'view' => 'job_attempts', 'type' => 'job-attempt'],
    'scheduled task' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => Cache::get('in-the-task'))->everyMinute();

        runArtisan(['command' => 'schedule:run']);
    }, 'view' => 'scheduled_tasks', 'type' => 'scheduled-task'],
    'query' => ['traffic' => fn () => DB::select('select 1'), 'view' => 'queries', 'type' => 'query'],
    'exception' => ['traffic' => fn () => Nightwatch::report(new RuntimeException('The payment failed.')), 'view' => 'exceptions', 'type' => 'exception'],
    'log' => ['traffic' => fn () => Log::channel('nightwatch')->warning('The payment is slow.', ['order' => 7]), 'view' => 'logs', 'type' => 'log'],
    'cache event' => ['traffic' => fn () => Cache::get('first'), 'view' => 'cache_events', 'type' => 'cache-event'],
    'mail' => ['traffic' => function () {
        config()->set('mail.default', 'array');

        Mail::raw('Your order shipped.', fn ($message) => $message->to('taylor@example.com')->subject('Shipped'));
    }, 'view' => 'mail', 'type' => 'mail'],
    'notification' => ['traffic' => fn () => (new AnonymousNotifiable)->notifyNow(new OrderShipped), 'view' => 'notifications', 'type' => 'notification'],
    'outgoing request' => ['traffic' => function () {
        Http::fake(['https://example.com/ping' => Http::response('pong')]);

        Http::get('https://example.com/ping');
    }, 'view' => 'outgoing_requests', 'type' => 'outgoing-request'],
    'queued job' => ['traffic' => function () {
        config()->set('queue.default', 'database');

        dispatch(fn () => null);
    }, 'view' => 'queued_jobs', 'type' => 'queued-job'],
]);

it('links a job attempt\'s children to the attempt, which keeps the trace of its dispatch', function () {
    config()->set('queue.default', 'database');
    dispatch(fn () => Cache::get('in-the-job'));
    Nightwatch::digest();

    runArtisan(['command' => 'queue:work', '--once' => true]);

    [$dispatch] = selectFromStore('SELECT trace_id, job_id FROM queued_jobs');
    [$attempt] = selectFromStore('SELECT trace_id, job_id, execution_id FROM job_attempts');
    $children = selectFromStore("SELECT execution_id FROM cache_events WHERE key = 'in-the-job'");

    expect($attempt)->toMatchArray(['trace_id' => $dispatch['trace_id'], 'job_id' => $dispatch['job_id']])
        ->and($attempt['execution_id'])->not->toBe($attempt['trace_id'])
        ->and(array_column($children, 'execution_id'))->toBe([$attempt['execution_id']]);
});

it('decodes the JSON-string fields of a record', function (Closure $traffic, string $view, string $field, string $decoded) {
    $traffic();
    Nightwatch::digest();

    [$record] = selectFromStore("SELECT json_type(data, '$.{$field}') AS decoded FROM {$view}");

    expect($record['decoded'])->toBe($decoded);
})->with([
    'request context' => ['traffic' => fn () => forceRequestTo('/'), 'view' => 'requests', 'field' => 'context', 'decoded' => 'object'],
    'request headers' => ['traffic' => fn () => forceRequestTo('/'), 'view' => 'requests', 'field' => 'headers', 'decoded' => 'object'],
    'command context' => ['traffic' => fn () => runArtisan(['command' => 'env']), 'view' => 'commands', 'field' => 'context', 'decoded' => 'object'],
    'job attempt context' => ['traffic' => function () {
        config()->set('queue.default', 'database');
        dispatch(fn () => null);

        runArtisan(['command' => 'queue:work', '--once' => true]);
    }, 'view' => 'job_attempts', 'field' => 'context', 'decoded' => 'object'],
    'scheduled task context' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => null)->everyMinute();

        runArtisan(['command' => 'schedule:run']);
    }, 'view' => 'scheduled_tasks', 'field' => 'context', 'decoded' => 'object'],
    'exception trace' => ['traffic' => fn () => Nightwatch::report(new RuntimeException('The payment failed.')), 'view' => 'exceptions', 'field' => 'trace', 'decoded' => 'array'],
    'log context' => ['traffic' => fn () => Log::channel('nightwatch')->warning('The payment is slow.', ['order' => 7]), 'view' => 'logs', 'field' => 'context', 'decoded' => 'object'],
    'log extra' => ['traffic' => fn () => Log::channel('nightwatch')->warning('The payment is slow.'), 'view' => 'logs', 'field' => 'extra', 'decoded' => 'object'],
]);

it('keeps a wire zero and an empty string as sent', function () {
    forceRequestTo('/');

    [$request] = selectFromStore('SELECT lazy_loads, payload FROM requests');

    expect($request)->toBe(['lazy_loads' => 0, 'payload' => '']);
});

it('starts the types Nightwatch stamps at their end one duration before their timestamp', function (string $type, float $startedAt) {
    ingestNow(childRecord($type, ['duration' => 250000]));

    [$record] = selectFromStore('SELECT started_at FROM records');

    expect($record['started_at'])->toBe($startedAt);
})->with([
    'mail' => ['type' => 'mail', 'startedAt' => 1767225600.0],
    'notification' => ['type' => 'notification', 'startedAt' => 1767225600.0],
    'queued job' => ['type' => 'queued-job', 'startedAt' => 1767225600.0],
    'outgoing request' => ['type' => 'outgoing-request', 'startedAt' => 1767225600.25],
    'cache event' => ['type' => 'cache-event', 'startedAt' => 1767225600.25],
    'query' => ['type' => 'query', 'startedAt' => 1767225600.25],
]);

it('keeps the timestamp of a type Nightwatch stamps at its end when its duration is not a number', function () {
    ingestNow(childRecord('mail', ['duration' => 'slow']));

    [$record] = selectFromStore('SELECT started_at FROM records');

    expect($record['started_at'])->toBe(1767225600.25);
});

it('links a fatal error, which Nightwatch sends without an execution, to its trace unless it ended a job', function (string $source, ?string $executionId) {
    ingestNow(childRecord('exception', ['execution_source' => $source, 'execution_id' => '', 'trace' => '']));

    [$exception] = selectFromStore('SELECT execution_id, trace FROM exceptions');

    expect($exception)->toBe(['execution_id' => $executionId, 'trace' => null]);
})->with([
    'request' => ['source' => 'request', 'executionId' => 'trace-1'],
    'command' => ['source' => 'command', 'executionId' => 'trace-1'],
    'scheduled task' => ['source' => 'schedule', 'executionId' => 'trace-1'],
    'job' => ['source' => 'job', 'executionId' => null],
]);

it('keeps a JSON-string field that is not JSON as sent', function () {
    ingestNow(childRecord('log', ['level' => 'info', 'message' => 'Hello.', 'context' => '{"order":', 'extra' => '{}']));

    [$log] = selectFromStore("SELECT context, json_type(data, '$.extra') AS extra FROM logs");

    expect($log)->toBe(['context' => '{"order":', 'extra' => 'object']);
});

it('keeps an unknown field in data under its wire name, even one named like a column', function () {
    ingestNow(childRecord('log', ['level' => 'info', 'message' => 'Hello.', 'context' => '{}', 'extra' => '{}', 'duration' => 5, 'colour' => 'red']));

    [$log] = selectFromStore("SELECT data ->> '$.duration' AS data_duration, data ->> '$.colour' AS colour, duration FROM records");

    expect($log)->toBe(['data_duration' => 5, 'colour' => 'red', 'duration' => null]);
});

it('stores a record of an unknown type with its common columns filled from the wire and the rest in data', function () {
    ingestNow(childRecord('future-type', ['duration' => 5, 'job_id' => 'job-1', 'user' => '7', 'colour' => 'red']));

    [$record] = selectFromStore('SELECT type, started_at, duration, group_hash, trace_id, execution_id, source, job_id, user_id, deploy, server, data FROM records');

    expect($record)->toBe([
        'type' => 'future-type',
        'started_at' => 1767225600.25,
        'duration' => 5,
        'group_hash' => str_repeat('a', 32),
        'trace_id' => 'trace-1',
        'execution_id' => 'trace-1',
        'source' => 'request',
        'job_id' => 'job-1',
        'user_id' => '7',
        'deploy' => 'v1.2.3',
        'server' => 'web-1',
        'data' => '{"execution_preview":"GET /","execution_stage":"action","colour":"red"}',
    ]);
});
