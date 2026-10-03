<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const EXECUTION_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(EXECUTION_AT - 3600));
});

/**
 * @param  array<string, mixed>  $fields
 */
function execRecord(RecordType $type, string $id, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->inExecution($id)->with(['timestamp' => EXECUTION_AT, ...$fields]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function execChild(RecordType $type, string $id, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->inExecution($id)->with(['timestamp' => EXECUTION_AT + 0.5, ...$fields]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function execQuery(string $sql, float $offset = 0.5, array $fields = [], string $id = 'one'): RecordBuilder
{
    return execChild(RecordType::QUERY, $id, ['sql' => $sql, 'timestamp' => EXECUTION_AT + $offset, 'file' => 'app/Orders.php', 'line' => 10, ...$fields]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function execAnswer(array $arguments = []): array
{
    return Envelope::assert(Execution::class, $arguments);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function execRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Execution::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

/**
 * @param  array<string, string|null>|null  $code
 * @return array<string, mixed>
 */
function execFrame(string $file, ?array $code = null, string $source = 'App\\Orders->place()'): array
{
    return ['file' => $file, 'source' => $source, 'code' => $code];
}

/**
 * @param  list<array<string, mixed>>  $frames
 */
function execException(array $frames = [], array $fields = [], string $id = 'one'): RecordBuilder
{
    return execChild(RecordType::EXCEPTION, $id, ['trace' => json_encode($frames, JSON_THROW_ON_ERROR), ...$fields]);
}

it('opens the execution that finished last, which may have started first', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'slow-request', ['duration' => 5_000_000]),
        execRecord(RecordType::COMMAND, 'late-command', ['timestamp' => EXECUTION_AT + 1, 'duration' => 1_000_000]),
    ]);

    $envelope = execAnswer();

    expect($envelope['result']['header']['execution_id'])->toBe('slow-request');
});

it('breaks a tie on the end time by the record stored last', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'first'),
        execRecord(RecordType::REQUEST, 'second'),
    ]);

    $envelope = execAnswer();

    expect($envelope['result']['header']['execution_id'])->toBe('second');
});

it('opens the latest of one type', function (string $type, string $expected) {
    ingest([
        execRecord(RecordType::REQUEST, 'request', ['duration' => 5_000_000]),
        execRecord(RecordType::COMMAND, 'command', ['duration' => 1_000_000]),
        execRecord(RecordType::JOB_ATTEMPT, 'attempt', ['duration' => 2_000_000]),
        execRecord(RecordType::SCHEDULED_TASK, 'task', ['duration' => 3_000_000]),
    ]);

    $envelope = execAnswer(['type' => $type]);

    expect($envelope['result']['header'])->toMatchArray(['type' => $type, 'execution_id' => $expected]);
})->with([
    'request' => ['request', 'request'],
    'command' => ['command', 'command'],
    'job-attempt' => ['job-attempt', 'attempt'],
    'scheduled-task' => ['scheduled-task', 'task'],
]);

it('opens the execution with the id, of any type', function (RecordType $type) {
    ingest([
        execRecord(RecordType::REQUEST, 'other', ['timestamp' => EXECUTION_AT + 10]),
        execRecord($type, 'wanted'),
    ]);

    $envelope = execAnswer(['execution_id' => 'wanted']);

    expect($envelope['result']['header'])->toMatchArray(['type' => $type->value, 'execution_id' => 'wanted']);
})->with([
    'a request' => [RecordType::REQUEST],
    'a command' => [RecordType::COMMAND],
    'a job attempt' => [RecordType::JOB_ATTEMPT],
    'a scheduled task' => [RecordType::SCHEDULED_TASK],
]);

it('answers that no execution of the type exists, naming the filter and the records it looked among', function () {
    ingest([execRecord(RecordType::REQUEST, 'request'), execChild(RecordType::LOG, 'request')]);

    $envelope = execAnswer(['type' => 'command']);

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => 2, 'message' => __('firewatch::messages.no_match', ['population' => 2, 'filters' => 'type: command'])])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.no_match'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 2, 'types_read' => ['command']]);
});

it('answers that no execution exists when the store holds only children', function () {
    ingest([execChild(RecordType::LOG, 'orphan')]);

    $envelope = execAnswer();

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => 1, 'message' => __('firewatch::messages.no_match', ['population' => 1, 'filters' => __('firewatch::messages.execution_any_type')])]);
});

it('refuses an execution id the store does not hold, as not found', function () {
    ingest([execRecord(RecordType::REQUEST, 'request')]);

    $text = execRefusal(['execution_id' => 'missing']);

    expect($text)->toBe(__('firewatch::messages.not_found', ['id' => 'missing', 'argument' => 'execution_id', 'accepted' => 'an execution id, not a trace id', 'example' => 'execution(execution_id: "<execution id>")']));
});

it('refuses an id that only children carry as not found, with no trace hint', function () {
    ingest([execChild(RecordType::QUERY, 'orphan-attempt', ['trace_id' => 'orphan-trace'])]);

    $text = execRefusal(['execution_id' => 'orphan-attempt']);

    expect($text)->toBe(__('firewatch::messages.not_found', ['id' => 'orphan-attempt', 'argument' => 'execution_id', 'accepted' => 'an execution id, not a trace id', 'example' => 'execution(execution_id: "<execution id>")']));
});

it('says a trace id that is not an execution id is one, and points at its records', function () {
    ingest([execRecord(RecordType::JOB_ATTEMPT, 'attempt', ['trace_id' => 'job-trace'])]);

    $text = execRefusal(['execution_id' => 'job-trace']);
    $rows = Envelope::assert(Occurrences::class, ['trace_id' => 'job-trace'])['result']['rows'];

    expect($text)->toBe(__('firewatch::messages.execution_not_found_trace', ['id' => 'job-trace', 'argument' => 'execution_id', 'accepted' => 'an execution id, not a trace id', 'example' => 'execution(execution_id: "<execution id>")']))
        ->and($rows)->toHaveCount(1);
});

it('opens an execution whose id is also the trace id of other records', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'shared'),
        execChild(RecordType::JOB_ATTEMPT, 'attempt', ['trace_id' => 'shared']),
    ]);

    $envelope = execAnswer(['execution_id' => 'shared']);

    expect($envelope['result']['header']['type'])->toBe('request');
});

it('refuses a type that is none of the four, matching it exactly', function (mixed $type, string $shown) {
    $text = execRefusal(['type' => $type]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'type', 'expected' => 'one of the four execution types', 'value' => $shown, 'accepted' => 'request, command, job-attempt, scheduled-task', 'example' => 'execution(type: "request")']));
})->with([
    'an underscore' => ['job_attempt', '"job_attempt"'],
    'a capital' => ['Request', '"Request"'],
    'a type that is not an execution' => ['query', '"query"'],
    'padding' => [' request', '" request"'],
    'not a string' => [5, '5'],
]);

it('refuses a type given with an execution id', function () {
    $text = execRefusal(['type' => 'request', 'execution_id' => 'abc']);

    expect($text)->toBe(__('firewatch::messages.conflicting_arguments', ['argument' => 'type', 'with' => 'execution_id', 'accepted' => 'a call with `execution_id` or with `type`, not both', 'example' => 'execution(execution_id: "<execution id>")']));
});

it('refuses an execution id that is not a non-empty string', function (mixed $id, string $shown) {
    $text = execRefusal(['execution_id' => $id]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'execution_id', 'expected' => 'an execution id', 'value' => $shown, 'accepted' => 'an execution id', 'example' => 'execution(execution_id: "<execution id>")']));
})->with([
    'empty' => ['', '""'],
    'a number' => [7, '7'],
]);

it('accepts a limit from 1 to 100 and refuses any other', function (mixed $limit, bool $accepted) {
    ingest([execRecord(RecordType::REQUEST, 'request')]);

    $text = execRefusal(['limit' => $limit]);

    expect(str_starts_with($text, 'error: invalid_argument'))->toBe(! $accepted);
})->with([
    'zero' => [0, false],
    'one' => [1, true],
    'a hundred' => [100, true],
    'a hundred and one' => [101, false],
    'negative' => [-1, false],
    'a fraction' => [5.5, false],
    'a string' => ['5', false],
]);

it('words the refusal of a limit with the range and an example', function () {
    $text = execRefusal(['limit' => 500]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'limit', 'expected' => '1 to 100', 'value' => '500', 'accepted' => 'a whole number from 1 to 100', 'example' => 'execution(limit: 50)']));
});

it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
    $text = execRefusal([$argument => 'now']);

    expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'execution', 'accepted' => 'execution_id, type, limit, format', 'example' => 'execution(format: "json")']));
})->with([
    'a misspelling' => ['execution', 'unknown_argument'],
    'since' => ['since', 'inapplicable_argument'],
    'until' => ['until', 'inapplicable_argument'],
    'deploy' => ['deploy', 'inapplicable_argument'],
    'cursor' => ['cursor', 'inapplicable_argument'],
    'group' => ['group', 'inapplicable_argument'],
    'trace_id' => ['trace_id', 'inapplicable_argument'],
]);

it('refuses a format that is none', function () {
    $text = execRefusal(['format' => 'xml']);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'format', 'expected' => 'markdown or json', 'value' => '"xml"', 'accepted' => 'markdown or json', 'example' => 'execution(format: "json")']));
});

it('refuses the arguments before it reads the store', function () {
    app()->instance(Reader::class, new class(app(Configuration::class)) extends Reader
    {
        public function snapshot(Closure $callback): mixed
        {
            throw new RuntimeException('the store was read');
        }
    });

    $texts = [execRefusal(['type' => 'query']), execRefusal(['limit' => 0]), execRefusal(['type' => 'request', 'execution_id' => 'a'])];

    expect($texts)->each->toStartWith('error: ');
    Exceptions::assertNothingReported();
});

it('states that it is not windowed, with the reason', function () {
    ingest([execRecord(RecordType::REQUEST, 'request')]);

    $envelope = execAnswer();

    expect($envelope['window'])->toBe(['windowed' => false, 'reason' => __('firewatch::messages.execution_window_reason')])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.execution_summary', ['type' => 'request', 'id' => 'request', 'outcome' => 200]));
});

it('answers that no store exists, with nothing to follow', function () {
    $envelope = execAnswer();

    expect($envelope['empty']['kind'])->toBe('no_store')
        ->and($envelope['result'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and($envelope['coverage']['state'])->toBe('absent');
});

it('answers that the store is empty, even for an execution id', function () {
    app(Writer::class)->transaction(fn () => null);

    $envelope = execAnswer(['execution_id' => 'abc']);

    expect($envelope['empty']['kind'])->toBe('store_empty')
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0])
        ->and($envelope['next'])->toBe([]);
});

it('answers that the store is unusable', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));

    $envelope = execAnswer();

    expect($envelope['empty']['kind'])->toBe('store_unusable')
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'foreign_file'])
        ->and($envelope['result'])->toBe([]);
});

it('reads the execution and its children as the types it examined', function () {
    ingest([execRecord(RecordType::COMMAND, 'command')]);

    $envelope = execAnswer();

    expect($envelope['coverage']['types_read'])->toBe(['command', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('dead-counters', 'memory-is-process-peak', 'query-bindings-unpaired', 'exceptions-unreported', 'failed-flag-unpopulated')
        ->and(array_column($envelope['blind_spots'], 'id'))->not->toContain('payload-on-server-error-only');
});

it('attaches the request blind spots to a request', function () {
    ingest([execRecord(RecordType::REQUEST, 'request')]);

    $envelope = execAnswer(['execution_id' => 'request']);

    expect(array_column($envelope['blind_spots'], 'id'))->toContain('payload-on-server-error-only', 'console-requests', 'octane-bootstrap');
});

it('says an execution is recorded when it finishes only when it was found as the latest', function (array $arguments, bool $attached) {
    ingest([execRecord(RecordType::REQUEST, 'request')]);

    $envelope = execAnswer($arguments);

    expect(in_array('visible-at-completion', array_column($envelope['blind_spots'], 'id'), true))->toBe($attached);
})->with([
    'no argument' => [[], true],
    'a type' => [['type' => 'request'], true],
    'an execution id' => [['execution_id' => 'request'], false],
]);

it('offers to rank the group of the execution, and the call runs', function () {
    ingest([execRecord(RecordType::REQUEST, 'request', ['_group' => str_repeat('b', 32)])]);

    $envelope = execAnswer();
    $call = $envelope['next'][0];
    $ranked = Envelope::assert(Rank::class, $call['arguments']);

    expect($envelope['next'])->toHaveCount(1)
        ->and($call)->toBe(['tool' => 'rank', 'arguments' => ['group' => str_repeat('b', 32)], 'why' => __('firewatch::messages.execution_next_rank')])
        ->and($ranked['empty'])->toBeNull();
});

it('offers to list the queries of an execution that captured some, and the call runs', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'request', ['_group' => str_repeat('b', 32)]),
        execQuery('select 1', id: 'request'),
    ]);

    $envelope = execAnswer();
    $call = $envelope['next'][1];
    $listed = Envelope::assert(Occurrences::class, $call['arguments']);

    expect($envelope['next'])->toHaveCount(2)
        ->and($call)->toBe(['tool' => 'occurrences', 'arguments' => ['execution_id' => 'request', 'type' => 'query'], 'why' => __('firewatch::messages.execution_next_occurrences')])
        ->and($listed['result']['rows'])->toHaveCount(1);
});

it('offers no list of queries for an execution that captured none', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'request', ['_group' => str_repeat('b', 32)]),
        execChild(RecordType::LOG, 'request'),
    ]);

    $envelope = execAnswer();

    expect(array_column($envelope['next'], 'tool'))->toBe(['rank']);
});

it('offers nothing to follow for an execution without a group', function () {
    ingest([execRecord(RecordType::COMMAND, 'command')->without('_group')]);

    $envelope = execAnswer();

    expect($envelope['next'])->toBe([]);
});

test('the tool is listed with its description, arguments and annotations', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tool = collect($listing['tools'])->firstWhere('name', 'execution');

    expect($tool['description'])->toBe(__('firewatch::messages.tools.execution'))
        ->and(array_keys($tool['inputSchema']['properties']))->toBe(['execution_id', 'type', 'limit', 'format'])
        ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false])
        ->and(array_map(fn (array $property) => str_word_count($property['description']), $tool['inputSchema']['properties']))->each->toBeLessThanOrEqual(30);
});

it('states the outcome of each type: a status code, an exit code or a status', function (RecordType $type, array $fields, mixed $outcome) {
    ingest([execRecord($type, 'one', $fields)]);

    $header = execAnswer()['result']['header'];

    expect($header['outcome'])->toBe($outcome);
})->with([
    'a request' => [RecordType::REQUEST, ['status_code' => 503], 503],
    'a command' => [RecordType::COMMAND, ['exit_code' => 2], 2],
    'a job attempt' => [RecordType::JOB_ATTEMPT, ['status' => 'released'], 'released'],
    'a scheduled task' => [RecordType::SCHEDULED_TASK, ['status' => 'skipped'], 'skipped'],
]);

it('states who, where and when, with the memory in megabytes and blank fields as null', function () {
    ingest([execRecord(RecordType::REQUEST, 'one', ['user' => '7', 'deploy' => 'v2', 'server' => 'web-3', 'duration' => 12_345, 'peak_memory_usage' => 5_242_880, '_group' => str_repeat('c', 32)])]);

    $header = execAnswer()['result']['header'];

    expect($header)->toEqual([
        'type' => 'request',
        'execution_id' => 'one',
        'trace_id' => 'one',
        'source' => 'request',
        'group' => str_repeat('c', 32),
        'label' => '/',
        'outcome' => 200,
        'started_at' => EXECUTION_AT,
        'duration_ms' => 12.35,
        'stages' => ['bootstrap' => 1.0, 'before_middleware' => 1.0, 'action' => 1.0, 'render' => 1.0, 'after_middleware' => 1.0, 'sending' => 1.0, 'terminating' => 1.0],
        'peak_memory_mb' => 5.0,
        'user_id' => '7',
        'deploy' => 'v2',
        'server' => 'web-3',
    ]);
});

it('leaves a missing user and deploy null', function () {
    ingest([execRecord(RecordType::REQUEST, 'one', ['user' => ''])]);

    $header = execAnswer()['result']['header'];

    expect($header['user_id'])->toBeNull()
        ->and($header['deploy'])->toBeNull();
});

it('labels a request by its route and a request that matched none with a word', function (string $path, string $label) {
    ingest([execRecord(RecordType::REQUEST, 'one', ['route_path' => $path])]);

    $header = execAnswer()['result']['header'];

    expect($header['label'])->toBe($label);
})->with([
    'a route' => ['/orders/{order}', '/orders/{order}'],
    'no route' => ['', '(no route matched)'],
]);

it('labels the other types by their name', function (RecordType $type, string $source) {
    ingest([execRecord($type, 'one', ['name' => 'orders:ship'])]);

    $header = execAnswer()['result']['header'];

    expect($header)->toMatchArray(['label' => 'orders:ship', 'source' => $source]);
})->with([
    'a command' => [RecordType::COMMAND, 'command'],
    'a job attempt' => [RecordType::JOB_ATTEMPT, 'job'],
    'a scheduled task' => [RecordType::SCHEDULED_TASK, 'schedule'],
]);

it('records the stages of a command, and none for a job attempt or a scheduled task', function (RecordType $type, ?array $stages) {
    ingest([execRecord($type, 'one', ['bootstrap' => 2_000, 'action' => 3_000, 'terminating' => 4_000])]);

    $header = execAnswer()['result']['header'];

    expect($header['stages'])->toEqual($stages);
})->with([
    'a command' => [RecordType::COMMAND, ['bootstrap' => 2.0, 'action' => 3.0, 'terminating' => 4.0]],
    'a job attempt' => [RecordType::JOB_ATTEMPT, null],
    'a scheduled task' => [RecordType::SCHEDULED_TASK, null],
]);

it('keeps a job attempt\'s trace id apart from its execution id', function () {
    ingest([execRecord(RecordType::JOB_ATTEMPT, 'attempt-1', ['trace_id' => 'trace-1'])]);

    $envelope = execAnswer();

    expect($envelope['result']['header'])->toMatchArray(['execution_id' => 'attempt-1', 'trace_id' => 'trace-1'])
        ->and($envelope['result']['caused'])->toBe(['jobs_queued' => 0, 'trace_id' => 'trace-1']);
});

it('states how many jobs the execution queued', function () {
    ingest([execRecord(RecordType::REQUEST, 'one', ['jobs_queued' => 3])]);

    $caused = execAnswer()['result']['caused'];

    expect($caused)->toBe(['jobs_queued' => 3, 'trace_id' => 'one']);
});

it('shows the headers and the payload as stored', function () {
    $headers = json_encode(['host' => ['localhost'], 'accept' => ['*/*']], JSON_THROW_ON_ERROR);
    $payload = json_encode(['order' => ['id' => 5], 'note' => 'late'], JSON_THROW_ON_ERROR);
    ingest([execRecord(RecordType::REQUEST, 'one', ['status_code' => 500, 'headers' => $headers, 'payload' => $payload])]);

    $request = execAnswer()['result']['request'];

    expect($request)->toBe(['headers' => ['host' => ['localhost'], 'accept' => ['*/*']], 'payload' => ['order' => ['id' => 5], 'note' => 'late']]);
});

it('has no payload when none was stored', function () {
    ingest([execRecord(RecordType::REQUEST, 'one', ['payload' => ''])]);

    $request = execAnswer()['result']['request'];

    expect($request['payload'])->toBeNull()
        ->and($request['headers'])->toBeArray();
});

it('cuts a cell at 2,000 characters and says so', function () {
    $payload = json_encode(['body' => str_repeat('x', 2500)], JSON_THROW_ON_ERROR);
    ingest([execRecord(RecordType::REQUEST, 'one', ['status_code' => 500, 'payload' => $payload])]);

    $envelope = execAnswer();

    expect($envelope['result']['request']['payload']['body'])->toBe(str_repeat('x', 2000).__('firewatch::messages.cell_truncated', ['count' => 500]))
        ->and($envelope['truncated'])->toBe([['section' => 'request', 'shown' => 1, 'matched' => null, 'reason' => 'cap', 'how' => __('firewatch::messages.cap_how', ['characters' => '2,000'])]]);
});

it('has no request details for the other types', function (RecordType $type) {
    ingest([execRecord($type, 'one')]);

    $result = execAnswer()['result'];

    expect($result)->not->toHaveKey('request');
})->with([
    'a command' => [RecordType::COMMAND],
    'a job attempt' => [RecordType::JOB_ATTEMPT],
    'a scheduled task' => [RecordType::SCHEDULED_TASK],
]);

it('has a row for each of the eight counters, a match for none counted and none captured', function () {
    ingest([execRecord(RecordType::REQUEST, 'one')]);

    $accounting = execAnswer()['result']['accounting'];

    expect(array_column($accounting['counters'], 'counter'))->toBe(['queries', 'exceptions', 'logs', 'cache_events', 'mail', 'notifications', 'outgoing_requests', 'jobs_queued'])
        ->and(array_unique(array_column($accounting['counters'], 'state')))->toBe(['match'])
        ->and($accounting['lines'])->toBe([]);
});

it('states what each counter counted against what the store holds', function (string $counter, RecordType $type, string $noun) {
    ingest([
        execRecord(RecordType::REQUEST, 'fewer', [$counter => 4]),
        ...array_map(fn (int $index) => execChild($type, 'fewer', ['timestamp' => EXECUTION_AT + 0.1 * $index]), [1, 2, 3]),
    ]);

    $accounting = execAnswer(['execution_id' => 'fewer'])['result']['accounting'];
    $row = collect($accounting['counters'])->firstWhere('counter', $counter);

    expect($row)->toBe(['counter' => $counter, 'counted' => 4, 'captured' => 3, 'state' => 'fewer'])
        ->and($accounting['lines'])->toBe([__('firewatch::messages.accounting_incomplete', ['captured' => 3, 'counted' => 4, 'noun' => $noun])]);
})->with([
    'queries' => ['queries', RecordType::QUERY, 'queries'],
    'exceptions' => ['exceptions', RecordType::EXCEPTION, 'exceptions'],
    'logs' => ['logs', RecordType::LOG, 'logs'],
    'cache events' => ['cache_events', RecordType::CACHE_EVENT, 'cache events'],
    'mail' => ['mail', RecordType::MAIL, 'mail'],
    'notifications' => ['notifications', RecordType::NOTIFICATION, 'notifications'],
    'outgoing requests' => ['outgoing_requests', RecordType::OUTGOING_REQUEST, 'outgoing requests'],
    'jobs queued' => ['jobs_queued', RecordType::QUEUED_JOB, 'jobs queued'],
]);

it('states 12 of 40 queries as incomplete, in the pinned words', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 40]),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 12)),
    ]);

    $accounting = execAnswer()['result']['accounting'];

    expect($accounting['counters'][0])->toBe(['counter' => 'queries', 'counted' => 40, 'captured' => 12, 'state' => 'fewer'])
        ->and($accounting['lines'])->toBe(['Incomplete: 12 of 40 counted queries were captured.']);
});

it('states more captured than counted, in the pinned words', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 12]),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.01 * $index), range(1, 15)),
    ]);

    $accounting = execAnswer()['result']['accounting'];

    expect($accounting['counters'][0])->toBe(['counter' => 'queries', 'counted' => 12, 'captured' => 15, 'state' => 'more'])
        ->and($accounting['lines'])->toBe(['More records captured than counted: 15 queries captured, 12 counted.']);
});

it('compares one fewer, equal and one more', function (int $captured, string $state, int $lines) {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 3]),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, $captured)),
    ]);

    $accounting = execAnswer()['result']['accounting'];

    expect($accounting['counters'][0])->toBe(['counter' => 'queries', 'counted' => 3, 'captured' => $captured, 'state' => $state])
        ->and($accounting['lines'])->toHaveCount($lines);
})->with([
    'one fewer' => [2, 'fewer', 1],
    'equal' => [3, 'match', 0],
    'one more' => [4, 'more', 1],
]);

it('states one line for each counter that differs, in the order of the counters', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 1, 'logs' => 2, 'mail' => 1]),
        execChild(RecordType::LOG, 'one'),
        execChild(RecordType::MAIL, 'one'),
        execChild(RecordType::MAIL, 'one'),
    ]);

    $lines = execAnswer()['result']['accounting']['lines'];

    expect($lines)->toBe([
        __('firewatch::messages.accounting_incomplete', ['captured' => 0, 'counted' => 1, 'noun' => 'queries']),
        __('firewatch::messages.accounting_incomplete', ['captured' => 1, 'counted' => 2, 'noun' => 'logs']),
        __('firewatch::messages.accounting_more', ['captured' => 2, 'counted' => 1, 'noun' => 'mail']),
    ]);
});

it('covers the whole execution whatever the limit', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 3]),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 3)),
    ]);

    $result = execAnswer(['limit' => 1])['result'];

    expect($result['timeline'])->toHaveCount(1)
        ->and($result['accounting']['counters'][0]['captured'])->toBe(3);
});

it('says children are outside coverage, not incomplete, when their type was cleared after the execution started', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 3, 'logs' => 1]),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 3)),
        execChild(RecordType::LOG, 'one'),
    ]);
    $this->travelTo(Date::createFromTimestamp(EXECUTION_AT + 600));
    $exitCode = $this->artisan('firewatch:clear', ['--type' => 'query', '--force' => true])->run();

    $envelope = execAnswer(['execution_id' => 'one']);
    $from = $envelope['coverage']['history']['from'];
    $accounting = $envelope['result']['accounting'];

    expect($exitCode)->toBe(0)
        ->and($from)->toEqual(EXECUTION_AT + 600)
        ->and($accounting['counters'][0])->toBe(['counter' => 'queries', 'counted' => 3, 'captured' => 0, 'state' => 'fewer'])
        ->and($accounting['lines'])->toBe([__('firewatch::messages.accounting_outside_coverage', ['noun' => 'queries', 'from' => Instant::format(EXECUTION_AT + 600, config()->string('app.timezone'))])])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('history-cleared');
});

it('says incomplete when the type that lacks children was not cleared', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 3]),
        execQuery('select 1'),
    ]);
    $this->travelTo(Date::createFromTimestamp(EXECUTION_AT + 600));
    $exitCode = $this->artisan('firewatch:clear', ['--type' => 'log', '--force' => true])->run();

    $accounting = execAnswer()['result']['accounting'];

    expect($exitCode)->toBe(0)
        ->and($accounting['lines'])->toBe([__('firewatch::messages.accounting_incomplete', ['captured' => 1, 'counted' => 3, 'noun' => 'queries'])]);
});

it('says children are outside coverage only when their type was cleared after the execution started', function (float $clearedAt, bool $outside) {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 3]),
        execQuery('select 1'),
    ]);
    $this->travelTo(Date::createFromTimestamp($clearedAt));
    $exitCode = $this->artisan('firewatch:clear', ['--type' => 'query', '--force' => true])->run();

    $lines = execAnswer()['result']['accounting']['lines'];

    $expected = $outside
        ? __('firewatch::messages.accounting_outside_coverage', ['noun' => 'queries', 'from' => Instant::format($clearedAt, config()->string('app.timezone'))])
        : __('firewatch::messages.accounting_incomplete', ['captured' => 0, 'counted' => 3, 'noun' => 'queries']);

    expect($exitCode)->toBe(0)
        ->and($lines)->toBe([$expected]);
})->with([
    'before it started' => ['clearedAt' => EXECUTION_AT - 1, 'outside' => false],
    'as it started' => ['clearedAt' => EXECUTION_AT, 'outside' => false],
    'after it started' => ['clearedAt' => EXECUTION_AT + 1, 'outside' => true],
]);

it('shows the class, message, handled flag and location of an exception', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(fields: ['class' => 'DomainException', 'message' => 'No stock.', 'file' => 'app/Orders.php', 'line' => 31, 'handled' => true]),
    ]);

    $exceptions = execAnswer()['result']['exceptions'];

    expect($exceptions)->toBe([['class' => 'DomainException', 'message' => 'No stock.', 'handled' => true, 'location' => 'app/Orders.php:31', 'frames' => [], 'frames_note' => null]]);
});

it('shows application frames with their stored lines and collapses runs of vendor frames', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(frames: [
            execFrame('app/Orders.php:41', ['40' => 'if ($stock < 1) {', '41' => '    throw new DomainException;', '42' => '}'], source: ''),
            execFrame('vendor/laravel/framework/src/A.php:10'),
            execFrame('/srv/app/vendor/laravel/framework/src/B.php:11'),
            execFrame('[internal function]'),
            execFrame('app/Http/OrderController.php:19', ['19' => '$orders->place();']),
            execFrame('vendor/laravel/framework/src/C.php:12'),
        ]),
    ]);

    $exception = execAnswer()['result']['exceptions'][0];

    expect($exception['frames'])->toBe([
        ['file' => 'app/Orders.php:41', 'source' => null, 'code' => [40 => 'if ($stock < 1) {', 41 => '    throw new DomainException;', 42 => '}']],
        ['vendor_frames' => 3],
        ['file' => 'app/Http/OrderController.php:19', 'source' => 'App\\Orders->place()', 'code' => [19 => '$orders->place();']],
        ['vendor_frames' => 1],
    ])
        ->and($exception['frames_note'])->toBeNull();
});

it('lists an application frame with no stored lines without them, and says whether the frame limit was reached', function (int $withCode, string $note) {
    $frames = [
        ...array_map(fn (int $index) => execFrame("app/Step{$index}.php:1", ['1' => 'code']), range(1, $withCode)),
        execFrame('app/Late.php:1'),
    ];
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(frames: $frames),
    ]);

    $exception = execAnswer()['result']['exceptions'][0];
    $late = end($exception['frames']);

    expect($late)->toBe(['file' => 'app/Late.php:1', 'source' => 'App\\Orders->place()', 'code' => null])
        ->and($exception['frames_note'])->toBe(__("firewatch::messages.{$note}"));
})->with([
    'nine frames with lines' => [9, 'execution_frames_limit_not_reached'],
    'ten frames with lines' => [10, 'execution_frames_limit_reached'],
]);

it('shows a fatal error, which has no trace, without frames', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execChild(RecordType::EXCEPTION, 'one')->with(['trace' => '']),
    ]);

    $exception = execAnswer()['result']['exceptions'][0];

    expect($exception['frames'])->toBe([])
        ->and($exception['frames_note'])->toBeNull();
});

it('shows the earliest five of six exceptions and says there are six', function (int $count, bool $cut) {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => $count]),
        ...array_map(fn (int $index) => execException(fields: ['class' => "Error{$index}", 'timestamp' => EXECUTION_AT + 0.1 * (10 - $index)]), range(1, $count)),
    ]);

    $envelope = execAnswer();
    $classes = array_column($envelope['result']['exceptions'], 'class');

    expect($classes)->toBe(array_slice(array_reverse(array_map(fn (int $index) => "Error{$index}", range(1, $count))), 0, 5))
        ->and($envelope['truncated'])->toBe($cut ? [['section' => 'exceptions', 'shown' => 5, 'matched' => 6, 'reason' => 'limit', 'how' => __('firewatch::messages.execution_exceptions_how', ['id' => 'one'])]] : [])
        ->and($envelope['result']['accounting']['counters'][1]['captured'])->toBe($count);
})->with([
    'five' => [5, false],
    'six' => [6, true],
]);

it('points at the call that lists every exception, which lists all six', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 6]),
        ...array_map(fn (int $index) => execException(fields: ['class' => "Error{$index}"]), range(1, 6)),
    ]);

    $how = execAnswer()['truncated'][0]['how'];
    $rows = Envelope::assert(Occurrences::class, ['execution_id' => 'one', 'type' => 'exception'])['result']['rows'];

    expect($how)->toBe(__('firewatch::messages.execution_exceptions_how', ['id' => 'one']))
        ->and($rows)->toHaveCount(6);
});

it('orders exceptions that started together by the order they were stored', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 2]),
        execException(fields: ['class' => 'First']),
        execException(fields: ['class' => 'Second']),
    ]);

    $classes = array_column(execAnswer()['result']['exceptions'], 'class');

    expect($classes)->toBe(['First', 'Second']);
});

it('cuts a message at 2,000 characters and says so', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(fields: ['message' => str_repeat('m', 2100)]),
    ]);

    $envelope = execAnswer();

    expect($envelope['result']['exceptions'][0]['message'])->toBe(str_repeat('m', 2000).__('firewatch::messages.cell_truncated', ['count' => 100]))
        ->and(array_column($envelope['truncated'], 'section'))->toContain('exceptions');
});

it('lists the children of every type in the order they started', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execChild(RecordType::NOTIFICATION, 'one', ['timestamp' => EXECUTION_AT + 0.7, 'duration' => 0]),
        execChild(RecordType::LOG, 'one', ['timestamp' => EXECUTION_AT + 0.1]),
        execQuery('select 1', 0.3),
        execChild(RecordType::CACHE_EVENT, 'one', ['timestamp' => EXECUTION_AT + 0.2]),
        execChild(RecordType::OUTGOING_REQUEST, 'one', ['timestamp' => EXECUTION_AT + 0.4]),
        execChild(RecordType::EXCEPTION, 'one', ['timestamp' => EXECUTION_AT + 0.5]),
        execChild(RecordType::MAIL, 'one', ['timestamp' => EXECUTION_AT + 0.8, 'duration' => 0]),
        execChild(RecordType::QUEUED_JOB, 'one', ['timestamp' => EXECUTION_AT + 0.9, 'duration' => 0]),
    ]);

    $timeline = execAnswer()['result']['timeline'];

    expect(array_column($timeline, 'type'))->toBe(['log', 'cache-event', 'query', 'outgoing-request', 'exception', 'notification', 'mail', 'queued-job'])
        ->and(array_column($timeline, 'started_at'))->toEqual([EXECUTION_AT + 0.1, EXECUTION_AT + 0.2, EXECUTION_AT + 0.3, EXECUTION_AT + 0.4, EXECUTION_AT + 0.5, EXECUTION_AT + 0.7, EXECUTION_AT + 0.8, EXECUTION_AT + 0.9]);
});

it('orders children that started together by the order they were stored', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execQuery('select b'),
        execQuery('select a'),
    ]);

    $names = array_column(execAnswer()['result']['timeline'], 'name');

    expect($names)->toBe(['select b', 'select a']);
});

it('does not list the children of another execution', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execRecord(RecordType::REQUEST, 'two', ['timestamp' => EXECUTION_AT - 10]),
        execQuery('select 1'),
        execQuery('select 2', id: 'two'),
    ]);

    $names = array_column(execAnswer()['result']['timeline'], 'name');

    expect($names)->toBe(['select 1']);
});

it('lists each type with what names it and what it adds', function (RecordType $type, array $fields, array $row) {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execChild($type, 'one', ['execution_stage' => 'action', ...$fields]),
    ]);

    $entry = execAnswer()['result']['timeline'][0];
    $startedAt = $entry['started_at'];
    unset($entry['started_at']);

    expect($startedAt)->toEqualWithDelta(EXECUTION_AT + 0.5, 0.000001)
        ->and($entry)->toEqual([
            'type' => $type->value,
            'stage' => 'action',
            'duration_ms' => $row['duration_ms'],
            'name' => $row['name'],
            'location' => $row['location'],
            'count' => null,
            'total_ms' => null,
            'detail' => $row['detail'],
        ]);
})->with([
    'a query' => [RecordType::QUERY, ['sql' => 'select 1', 'file' => 'app/Orders.php', 'line' => 10, 'duration' => 2500, 'connection' => 'mysql'], ['duration_ms' => 2.5, 'name' => 'select 1', 'location' => 'app/Orders.php:10', 'detail' => ['connection' => 'mysql', 'connection_type' => 'write', 'bindings' => null]]],
    'an exception' => [RecordType::EXCEPTION, ['class' => 'Boom', 'file' => 'app/Boom.php', 'line' => 3, 'message' => 'Boom!', 'handled' => false], ['duration_ms' => null, 'name' => 'Boom', 'location' => 'app/Boom.php:3', 'detail' => ['message' => 'Boom!', 'handled' => false]]],
    'a log' => [RecordType::LOG, ['level' => 'warning', 'message' => 'Low stock'], ['duration_ms' => null, 'name' => 'warning', 'location' => null, 'detail' => ['message' => 'Low stock']]],
    'a cache event' => [RecordType::CACHE_EVENT, ['key' => 'orders:5', 'type' => 'hit', 'store' => 'redis', 'ttl' => 60, 'duration' => 1500], ['duration_ms' => 1.5, 'name' => 'orders:5', 'location' => null, 'detail' => ['event' => 'hit', 'store' => 'redis', 'ttl' => 60]]],
    'mail' => [RecordType::MAIL, ['class' => 'App\\Mail\\Receipt', 'mailer' => 'smtp', 'subject' => 'Thanks', 'to' => 1, 'cc' => 0, 'bcc' => 2, 'attachments' => 3, 'duration' => 4000, 'timestamp' => EXECUTION_AT + 0.504], ['duration_ms' => 4.0, 'name' => 'App\\Mail\\Receipt', 'location' => null, 'detail' => ['mailer' => 'smtp', 'subject' => 'Thanks', 'to' => 1, 'cc' => 0, 'bcc' => 2, 'attachments' => 3, 'failed' => false]]],
    'a notification' => [RecordType::NOTIFICATION, ['class' => 'App\\Notifications\\Late', 'channel' => 'mail', 'duration' => 2000, 'timestamp' => EXECUTION_AT + 0.502], ['duration_ms' => 2.0, 'name' => 'App\\Notifications\\Late', 'location' => null, 'detail' => ['channel' => 'mail', 'failed' => false]]],
    'an outgoing request' => [RecordType::OUTGOING_REQUEST, ['method' => 'POST', 'host' => 'api.example.com', 'url' => 'https://api.example.com/charges', 'status_code' => 201, 'request_size' => 10, 'response_size' => 20, 'duration' => 30000], ['duration_ms' => 30.0, 'name' => 'https://api.example.com/charges', 'location' => null, 'detail' => ['method' => 'POST', 'host' => 'api.example.com', 'status_code' => 201, 'request_size_bytes' => 10, 'response_size_bytes' => 20]]],
    'a queued job' => [RecordType::QUEUED_JOB, ['name' => 'App\\Jobs\\ShipOrder', 'job_id' => 'job-1', 'connection' => 'redis', 'queue' => 'default', 'duration' => 1000, 'timestamp' => EXECUTION_AT + 0.501], ['duration_ms' => 1.0, 'name' => 'App\\Jobs\\ShipOrder', 'location' => null, 'detail' => ['job_id' => 'job-1', 'connection' => 'redis', 'queue' => 'default']]],
]);

it('collapses identical repeated queries into one row at the first of them, with a count and the total time', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execQuery('select * from orders', 0.1, ['duration' => 1000]),
        execQuery('select 1', 0.2),
        execQuery('select * from orders', 0.3, ['duration' => 2000]),
        execQuery('select * from orders', 0.4, ['duration' => 3000]),
    ]);

    $timeline = execAnswer()['result']['timeline'];

    expect(array_column($timeline, 'name'))->toBe(['select * from orders', 'select 1'])
        ->and($timeline[0])->toMatchArray(['started_at' => EXECUTION_AT + 0.1, 'duration_ms' => 1.0, 'count' => 3, 'total_ms' => 6.0])
        ->and($timeline[0]['detail'])->toBe(['connection' => 'testing', 'connection_type' => 'write'])
        ->and($timeline[1])->toMatchArray(['count' => null, 'total_ms' => null]);
});

it('keeps queries apart that differ in connection or location, and collapses ones that differ only in bindings', function (array $second, int $rows) {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execQuery('select 1', 0.1),
        execQuery('select 1', 0.2, $second),
    ]);

    $timeline = execAnswer()['result']['timeline'];

    expect($timeline)->toHaveCount($rows);
})->with([
    'the same' => [[], 1],
    'another connection' => [['connection' => 'replica'], 2],
    'another file' => [['file' => 'app/Other.php'], 2],
    'another line' => [['line' => 11], 2],
    'another group' => [['_group' => str_repeat('d', 32)], 1],
]);

it('lists no more entries than the limit, and says how many there are', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 3)),
    ]);

    $envelope = execAnswer(['limit' => 2]);

    expect(array_column($envelope['result']['timeline'], 'name'))->toBe(['select 1', 'select 2'])
        ->and($envelope['truncated'])->toBe([['section' => 'timeline', 'shown' => 2, 'matched' => 3, 'reason' => 'limit', 'how' => __('firewatch::messages.execution_timeline_how', ['id' => 'one'])]]);
});

it('points at the call that lists every record of a cut timeline, which lists them all', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 3)),
    ]);

    $how = execAnswer(['limit' => 2])['truncated'][0]['how'];
    $rows = Envelope::assert(Occurrences::class, ['execution_id' => 'one'])['result']['rows'];

    expect($how)->toBe(__('firewatch::messages.execution_timeline_how', ['id' => 'one']))
        ->and($rows)->toHaveCount(4);
});

it('lists a timeline of exactly the limit as complete', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 2)),
    ]);

    $envelope = execAnswer(['limit' => 2]);

    expect($envelope['result']['timeline'])->toHaveCount(2)
        ->and($envelope['truncated'])->toBe([]);
});

it('counts a collapsed row once against the limit', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery('select 1', 0.01 * $index), range(1, 10)),
        execQuery('select 2', 0.9),
    ]);

    $envelope = execAnswer(['limit' => 2]);

    expect($envelope['result']['timeline'])->toHaveCount(2)
        ->and($envelope['truncated'])->toBe([])
        ->and($envelope['result']['timeline'][0]['count'])->toBe(10);
});

it('lists fifty entries by default', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.001 * $index), range(1, 60)),
    ]);

    $envelope = execAnswer();

    expect($envelope['result']['timeline'])->toHaveCount(50)
        ->and($envelope['truncated'][0])->toMatchArray(['section' => 'timeline', 'shown' => 50, 'matched' => 60, 'reason' => 'limit']);
});

it('drops the last timeline entries when a hundred of them are over the size of an answer', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.001 * $index), range(1, 120)),
    ]);

    $envelope = execAnswer(['limit' => 100]);
    $shown = count($envelope['result']['timeline']);

    expect($shown)->toBeLessThan(100)
        ->and($envelope['truncated'])->toBe([['section' => 'timeline', 'shown' => $shown, 'matched' => 120, 'reason' => 'size', 'how' => __('firewatch::messages.size_how', ['characters' => '24,000'])]]);
});

it('prints the cut timeline in markdown, with how to see the rest', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (int $index) => execQuery("select {$index}", 0.1 * $index), range(1, 3)),
    ]);

    $response = FirewatchServer::tool(Execution::class, ['limit' => 2]);
    $markdown = (fn () => $this->content())->call($response)[0];

    expect($markdown)->toContain(__('firewatch::messages.execution_timeline_how', ['id' => 'one']));
});

it('totals the time of the repeated runs that have a duration, and none for runs that have none', function (array $durations, ?float $total) {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        ...array_map(fn (?int $duration, int $index) => execQuery('select 1', 0.1 * ($index + 1), ['duration' => $duration]), $durations, array_keys($durations)),
    ]);

    $row = execAnswer()['result']['timeline'][0];

    expect($row['count'])->toBe(count($durations))
        ->and($row['total_ms'])->toEqual($total);
})->with([
    'no run has a duration' => ['durations' => [null, null], 'total' => null],
    'one run has a duration' => ['durations' => [null, 2000], 'total' => 2.0],
    'every run has a duration' => ['durations' => [1000, 2000], 'total' => 3.0],
]);

it('does not collapse repeated children that are not queries', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one'),
        execChild(RecordType::LOG, 'one', ['level' => 'info', 'message' => 'Same', 'timestamp' => EXECUTION_AT + 0.1]),
        execChild(RecordType::LOG, 'one', ['level' => 'info', 'message' => 'Same', 'timestamp' => EXECUTION_AT + 0.2]),
    ]);

    $timeline = execAnswer()['result']['timeline'];

    expect(array_column($timeline, 'count'))->toBe([null, null]);
});

it('treats a trace that is not JSON, an entry that is no frame and a frame with no file as frames without lines', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 2]),
        execException(fields: ['trace' => 'not json', 'timestamp' => EXECUTION_AT + 0.1]),
        execException(frames: ['not a frame', ['source' => 'App\\Orders->place()', 'code' => null], execFrame('app/Orders.php:3')]),
    ]);

    $exceptions = execAnswer()['result']['exceptions'];

    expect($exceptions[0]['frames'])->toBe([])
        ->and($exceptions[1]['frames'])->toBe([
            ['vendor_frames' => 1],
            ['file' => 'app/Orders.php:3', 'source' => 'App\\Orders->place()', 'code' => null],
        ]);
});

it('names the place of a record by its file and line, by its file alone, or not at all', function (array $fields, ?string $location) {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(fields: $fields),
    ]);

    $exception = execAnswer()['result']['exceptions'][0];

    expect($exception['location'])->toBe($location);
})->with([
    'a file and a line' => ['fields' => ['file' => 'app/Orders.php', 'line' => 31], 'location' => 'app/Orders.php:31'],
    'a file without a line' => ['fields' => ['file' => 'app/Orders.php', 'line' => null], 'location' => 'app/Orders.php'],
    'an empty file' => ['fields' => ['file' => '', 'line' => 31], 'location' => null],
    'no file' => ['fields' => ['file' => null, 'line' => null], 'location' => null],
]);

it('leaves a flag the wire did not carry unknown, rather than false', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['exceptions' => 1]),
        execException(fields: ['handled' => null]),
    ]);

    $exception = execAnswer()['result']['exceptions'][0];

    expect($exception['handled'])->toBeNull();
});

it('leaves the memory unknown when the record has none', function () {
    ingest([execRecord(RecordType::REQUEST, 'one', ['peak_memory_usage' => null])]);

    $header = execAnswer()['result']['header'];

    expect($header['peak_memory_mb'])->toBeNull();
});

it('shows an execution that has no id with no children', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['queries' => 1])->without('trace_id'),
        execQuery('select 1'),
    ]);

    $result = execAnswer()['result'];

    expect($result['header']['execution_id'])->toBeNull()
        ->and($result['timeline'])->toBe([])
        ->and($result['accounting']['counters'][0])->toBe(['counter' => 'queries', 'counted' => 1, 'captured' => 0, 'state' => 'fewer']);
});

it('counts a counter the record does not carry as none counted', function () {
    ingest([
        execRecord(RecordType::REQUEST, 'one', ['logs' => null]),
        execChild(RecordType::LOG, 'one'),
    ]);

    $counter = collect(execAnswer()['result']['accounting']['counters'])->firstWhere('counter', 'logs');

    expect($counter)->toBe(['counter' => 'logs', 'counted' => 0, 'captured' => 1, 'state' => 'more']);
});
