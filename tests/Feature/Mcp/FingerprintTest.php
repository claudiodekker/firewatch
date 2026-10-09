<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Arr;

const FP_AT = 1790776000.5;

/**
 * Get the group id of an input as Nightwatch hashes it.
 */
function fpGroup(string $input): string
{
    return hash('xxh128', $input);
}

/**
 * Get a synthetic record of the type that Nightwatch grouped by the input, started after the given seconds.
 *
 * @param  array<string, mixed>  $fields
 */
function fpRecord(RecordType $type, string $input, array $fields = [], int $after = 0): RecordBuilder
{
    return syntheticRecord($type)->with(['timestamp' => FP_AT + $after, '_group' => fpGroup($input), ...$fields]);
}

/**
 * Run the call a `next` entry offers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function fpFollow(array $call): array
{
    return Envelope::assert(['occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']], $call['arguments']);
}

/**
 * Assert that the call is refused with the text.
 *
 * @param  array<string, mixed>  $arguments
 */
function fpRefused(array $arguments, string $text): void
{
    FirewatchServer::tool(Fingerprint::class, $arguments)->assertHasErrors([$text]);
}

it('computes the group id of each type from the facts read in source, in full', function (array $arguments, string $input) {
    $envelope = Envelope::assert(Fingerprint::class, $arguments);

    expect($envelope['result'])->toBe([
        'type' => $arguments['type'],
        'candidates' => [['group' => fpGroup($input), 'input' => $input, 'assumption' => null]],
        'held' => [],
        'recipe_check' => array_map(fn (string $type) => ['type' => $type, 'check' => 'not_evaluated', 'detail' => null], $arguments['type'] === 'job-attempt' || $arguments['type'] === 'queued-job' ? ['job-attempt', 'queued-job'] : [$arguments['type']]),
    ]);
})->with([
    'a request, with HEAD added and the slash put on' => [['type' => 'request', 'methods' => ['GET'], 'path' => 'users/{user}'], 'GET|HEAD,,/users/{user}'],
    'a request on a domain, its methods sorted' => [['type' => 'request', 'methods' => ['POST', 'DELETE'], 'path' => '/orders', 'domain' => 'api.example.com'], 'DELETE|POST,api.example.com,/orders'],
    'the root path' => [['type' => 'request', 'methods' => ['GET', 'HEAD'], 'path' => '/'], 'GET|HEAD,,/'],
    'a command' => [['type' => 'command', 'name' => 'orders:prune'], 'orders:prune'],
    'a job attempt' => [['type' => 'job-attempt', 'name' => 'App\\Jobs\\ShipOrder'], 'App\\Jobs\\ShipOrder'],
    'a queued job' => [['type' => 'queued-job', 'name' => 'App\\Jobs\\ShipOrder'], 'App\\Jobs\\ShipOrder'],
    'a task that repeats' => [['type' => 'scheduled-task', 'name' => 'prune', 'cron' => '* * * * *', 'timezone' => 'Europe/Amsterdam', 'repeat_seconds' => 30], 'prune,* * * * *,Europe/Amsterdam,30'],
    'a task that does not' => [['type' => 'scheduled-task', 'name' => 'prune', 'cron' => '0 * * * *', 'timezone' => 'UTC', 'repeat_seconds' => 0], 'prune,0 * * * *,UTC'],
    'a query with nothing to normalise' => [['type' => 'query', 'connection' => 'mysql', 'sql' => 'select * from `orders` where `id` = ?'], 'mysql,select * from `orders` where `id` = ?'],
    'a cache event' => [['type' => 'cache-event', 'store' => 'redis', 'key' => 'settings'], 'redis,settings'],
    'an outgoing request' => [['type' => 'outgoing-request', 'host' => 'api.stripe.com'], 'api.stripe.com'],
    'a mail' => [['type' => 'mail', 'class' => 'App\\Mail\\OrderShipped'], 'App\\Mail\\OrderShipped'],
    'a notification' => [['type' => 'notification', 'class' => 'App\\Notifications\\OrderShipped'], 'App\\Notifications\\OrderShipped'],
]);

it('adds HEAD beside GET and says so, and says nothing when HEAD was given', function (array $methods, bool $noted) {
    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => $methods, 'path' => '/a']);

    expect($envelope['notes'])->toBe($noted ? [__('firewatch::messages.fingerprint_head_note')] : [])
        ->and($envelope['result']['candidates'][0]['input'])->toBe($methods === ['POST'] ? 'POST,,/a' : 'GET|HEAD,,/a');
})->with([
    'GET alone' => [['GET'], true],
    'GET and HEAD' => [['HEAD', 'GET'], false],
    'POST' => [['POST'], false],
]);

it('gives a task that names no timezone the one Laravel schedules it in, and says so', function (array $schedule, string $expected) {
    config()->set('app.timezone', 'Asia/Tokyo');
    config()->set('app', Arr::except(config()->array('app'), 'schedule_timezone'));
    config()->set($schedule);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'scheduled-task', 'name' => 'prune', 'cron' => '* * * * *']);

    expect($envelope['result']['candidates'][0]['input'])->toBe("prune,* * * * *,{$expected}")
        ->and($envelope['notes'])->toBe([__('firewatch::messages.fingerprint_timezone_note', ['timezone' => $expected])]);
})->with([
    'the schedule one' => [['app.schedule_timezone' => 'Europe/Amsterdam'], 'Europe/Amsterdam'],
    'the application one when no schedule timezone is set' => [[], 'Asia/Tokyo'],
    'none when the schedule timezone is set to null' => [['app.schedule_timezone' => null], ''],
]);

it('reads a driver and a domain as Laravel gives them: the driver in lower case, the domain without its scheme', function () {
    $sql = 'select * from "orders" where "id" in (?, ?)';

    $driver = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql, 'driver' => 'MySQL']);
    $domain = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => ['GET', 'HEAD'], 'path' => '/orders', 'domain' => 'https://api.example.com']);

    expect($driver['result']['candidates'][0]['input'])->toBe('testing,select * from "orders" where "id" in (...?)')
        ->and($domain['result']['candidates'][0]['input'])->toBe('GET|HEAD,api.example.com,/orders');
});

it('reads a query without a driver both ways when normalising changes it, and with one reading only one', function () {
    $sql = 'select * from "orders" where "id" in (?, ?)';
    $written = "testing,{$sql}";
    $normalised = 'testing,select * from "orders" where "id" in (...?)';

    ingest([fpRecord(RecordType::QUERY, $normalised, ['connection' => 'testing', 'sql' => $sql])]);

    $open = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql]);
    $sqlite = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql, 'driver' => 'sqlite']);
    $other = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql, 'driver' => 'array']);

    expect($open['result']['candidates'])->toBe([
        ['group' => fpGroup($normalised), 'input' => $normalised, 'assumption' => __('firewatch::messages.fingerprint_assumption_normalised')],
        ['group' => fpGroup($written), 'input' => $written, 'assumption' => __('firewatch::messages.fingerprint_assumption_written')],
    ])
        ->and(array_column($open['result']['held'], 'group'))->toBe([fpGroup($normalised)])
        ->and($open['result']['recipe_check'])->toBe([['type' => 'query', 'check' => 'agrees', 'detail' => __('firewatch::messages.fingerprint_assumption_normalised')]])
        ->and($sqlite['result']['candidates'])->toBe([['group' => fpGroup($normalised), 'input' => $normalised, 'assumption' => null]])
        ->and(array_column($sqlite['result']['held'], 'group'))->toBe([fpGroup($normalised)])
        ->and($other['result']['candidates'])->toBe([['group' => fpGroup($written), 'input' => $written, 'assumption' => null]])
        ->and($other['result']['held'])->toBe([]);
});

it('refuses exception, log and user with the reason', function (string $type) {
    fpRefused(['type' => $type], __('firewatch::messages.no_recipe', [
        'type' => $type,
        'reason' => __("firewatch::messages.no_recipe_reasons.{$type}"),
        'accepted' => 'request, command, job-attempt, queued-job, scheduled-task, query, cache-event, outgoing-request, mail, notification',
        'example' => 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")',
    ]));
})->with(['exception', 'log', 'user']);

it('refuses an argument that belongs to another type, naming the arguments of this one', function (array $arguments, string $argument, string $accepted, string $example) {
    fpRefused($arguments, __('firewatch::messages.conflicting_arguments', [
        'argument' => $argument,
        'with' => "type: {$arguments['type']}",
        'accepted' => $accepted,
        'example' => $example,
    ]));
})->with([
    'a path on a command' => [['type' => 'command', 'name' => 'env', 'path' => '/x'], 'path', 'name', 'fingerprint(type: "command", name: "<name>")'],
    'a name on a request' => [['type' => 'request', 'methods' => ['GET'], 'path' => '/x', 'name' => 'x'], 'name', 'methods, path, domain (optional)', 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")'],
    'a host on a query' => [['type' => 'query', 'connection' => 'a', 'sql' => 'b', 'host' => 'x'], 'host', 'connection, sql, driver (optional)', 'fingerprint(type: "query", connection: "<connection>", sql: "<sql>")'],
    'a key on a mail' => [['type' => 'mail', 'class' => 'x', 'key' => 'x'], 'key', 'class', 'fingerprint(type: "mail", class: "<class>")'],
    'a driver on a task' => [['type' => 'scheduled-task', 'name' => 'x', 'cron' => 'y', 'driver' => 'mysql'], 'driver', 'name, cron, timezone (optional), repeat_seconds (optional)', 'fingerprint(type: "scheduled-task", name: "<name>", cron: "<cron>")'],
    'a repeat on a cache event' => [['type' => 'cache-event', 'store' => 'a', 'key' => 'b', 'repeat_seconds' => 5], 'repeat_seconds', 'store, key', 'fingerprint(type: "cache-event", store: "<store>", key: "<key>")'],
]);

it('refuses a missing argument by name', function (array $arguments, string $argument, string $accepted, string $example) {
    fpRefused($arguments, __('firewatch::messages.missing_argument', [
        'argument' => $argument,
        'accepted' => $accepted,
        'example' => $example,
    ]));
})->with([
    'no type' => [[], 'type', 'request, command, job-attempt, queued-job, scheduled-task, query, cache-event, outgoing-request, mail, notification', 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")'],
    'no methods' => [['type' => 'request', 'path' => '/'], 'methods', 'methods, path, domain (optional)', 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")'],
    'no cron' => [['type' => 'scheduled-task', 'name' => 'x'], 'cron', 'name, cron, timezone (optional), repeat_seconds (optional)', 'fingerprint(type: "scheduled-task", name: "<name>", cron: "<cron>")'],
    'no connection' => [['type' => 'query', 'sql' => 'select 1'], 'connection', 'connection, sql, driver (optional)', 'fingerprint(type: "query", connection: "<connection>", sql: "<sql>")'],
    'no key' => [['type' => 'cache-event', 'store' => 'array'], 'key', 'store, key', 'fingerprint(type: "cache-event", store: "<store>", key: "<key>")'],
    'no name' => [['type' => 'queued-job'], 'name', 'name', 'fingerprint(type: "queued-job", name: "<name>")'],
]);

it('refuses an argument of the wrong shape with the values it accepts', function (array $arguments, string $argument, string $expected, string $value, string $accepted, string $example) {
    fpRefused($arguments, __('firewatch::messages.invalid_argument', [
        'argument' => $argument,
        'expected' => $expected,
        'value' => $value,
        'accepted' => $accepted,
        'example' => $example,
    ]));
})->with(function () {
    $request = 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")';
    $methods = ['methods', 'a non-empty list of uppercase HTTP methods'];
    $accepted = 'uppercase HTTP methods, such as ["GET","HEAD"]';
    $repeat = ['repeat_seconds', 'a whole number of seconds that divides the minute'];
    $task = ['type' => 'scheduled-task', 'name' => 'x', 'cron' => 'y'];
    $taskExample = 'fingerprint(type: "scheduled-task", name: "<name>", cron: "<cron>")';

    return [
        'methods as a string' => [['type' => 'request', 'path' => '/', 'methods' => 'GET'], ...$methods, '"GET"', $accepted, $request],
        'no methods' => [['type' => 'request', 'path' => '/', 'methods' => []], ...$methods, '[]', $accepted, $request],
        'a lowercase method' => [['type' => 'request', 'path' => '/', 'methods' => ['get']], ...$methods, '["get"]', $accepted, $request],
        'a method that is no string' => [['type' => 'request', 'path' => '/', 'methods' => ['GET', 1]], ...$methods, '["GET",1]', $accepted, $request],
        'seven repeat seconds' => [[...$task, 'repeat_seconds' => 7], ...$repeat, '7', '0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30', $taskExample],
        'sixty repeat seconds' => [[...$task, 'repeat_seconds' => 60], ...$repeat, '60', '0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30', $taskExample],
        'repeat seconds as text' => [[...$task, 'repeat_seconds' => '5'], ...$repeat, '"5"', '0, 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30', $taskExample],
        'a name that is no string' => [['type' => 'command', 'name' => 5], 'name', 'a string', '5', 'a string', 'fingerprint(type: "command", name: "<name>")'],
        'an empty driver' => [['type' => 'query', 'connection' => 'a', 'sql' => 'b', 'driver' => ''], 'driver', 'a non-empty string', '""', 'a driver name, such as "mysql"', 'fingerprint(type: "query", connection: "<connection>", sql: "<sql>")'],
        'a type that is none' => [['type' => 'requests'], 'type', 'one of the ten types with a recipe', '"requests"', 'request, command, job-attempt, queued-job, scheduled-task, query, cache-event, outgoing-request, mail, notification', $request],
    ];
});

it('computes the id before any store exists, saying the check could not be made', function () {
    $path = app(Configuration::class)->database;

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'queued-job', 'name' => 'App\\Jobs\\ShipOrder']);

    expect($envelope['empty'])->toBe(['kind' => 'no_store', 'population' => null, 'message' => __('firewatch::messages.no_store', ['path' => $path])])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.no_store'))
        ->and($envelope['window'])->toBe(['windowed' => false, 'reason' => __('firewatch::messages.fingerprint_window_reason')])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'absent', 'types_read' => ['job-attempt', 'queued-job']])
        ->and($envelope['result']['candidates'][0]['group'])->toBe(fpGroup('App\\Jobs\\ShipOrder'))
        ->and($envelope['result']['held'])->toBe([])
        ->and(array_column($envelope['result']['recipe_check'], 'check'))->toBe(['not_evaluated', 'not_evaluated'])
        ->and($envelope['next'])->toBe([])
        ->and(dirname($path))->not->toBeDirectory();
});

it('computes the id from a store it cannot read, saying why', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'command', 'name' => 'env']);

    expect($envelope['empty']['kind'])->toBe('store_unusable')
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.store_unusable.foreign_file', ['path' => $path]))
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'foreign_file'])
        ->and($envelope['result']['candidates'][0]['group'])->toBe(fpGroup('env'))
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'command', 'check' => 'not_evaluated', 'detail' => null]])
        ->and($envelope['next'])->toBe([]);
});

it('says an empty store holds nothing, with the check not evaluated', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn () => null);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'command', 'name' => 'env']);

    expect($envelope['empty'])->toBe(['kind' => 'store_empty', 'population' => 0, 'message' => __('firewatch::messages.store_empty', ['path' => $path])])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0])
        ->and($envelope['result']['held'])->toBe([])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'command', 'check' => 'not_evaluated', 'detail' => 'no_records']])
        ->and($envelope['next'])->toBe([]);
});

it('finds the types that hold the id, with their span and label, and offers their records', function () {
    ingest([
        fpRecord(RecordType::REQUEST, 'GET|HEAD,,/orders', ['route_methods' => ['GET', 'HEAD'], 'route_domain' => '', 'route_path' => '/orders'], 5),
        fpRecord(RecordType::REQUEST, 'GET|HEAD,,/orders', ['route_methods' => ['GET', 'HEAD'], 'route_domain' => '', 'route_path' => '/orders'], 60),
        fpRecord(RecordType::REQUEST, 'GET|HEAD,,/carts', ['route_methods' => ['GET', 'HEAD'], 'route_domain' => '', 'route_path' => '/carts'], 90),
    ]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => ['GET', 'HEAD'], 'path' => 'orders']);
    $span = storeRows("SELECT min(started_at) AS first, max(started_at) AS last FROM records WHERE group_hash = '".fpGroup('GET|HEAD,,/orders')."'")[0];
    $group = fpGroup('GET|HEAD,,/orders');

    expect($envelope['result']['held'])->toBe([['group' => $group, 'type' => 'request', 'records' => 2, 'first_started_at' => $span['first'], 'last_started_at' => $span['last'], 'label' => '/orders']])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'request', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['empty'])->toBeNull()
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.fingerprint_summary_held', 2, [
            'group' => $group,
            'records' => 2,
            'types' => 'request',
            'check' => __('firewatch::messages.fingerprint_check.agrees'),
        ]))
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'types_read' => ['request'], 'records' => 3])
        ->and($envelope['blind_spots'])->toContain(...BlindSpots::for([RecordType::REQUEST]))
        ->and(array_column($envelope['next'], 'tool'))->toBe(['occurrences', 'rank'])
        ->and(array_column($envelope['next'], 'arguments'))->toBe([['group' => $group], ['group' => $group]]);

    foreach ($envelope['next'] as $call) {
        expect(fpFollow($call)['tool'])->toBe($call['tool']);
    }
});

it('says when the store holds the query under the reading the given driver rules out', function () {
    $sql = 'select * from "orders" where "id" in (?, ?)';
    $normalised = 'testing,select * from "orders" where "id" in (...?)';

    ingest([fpRecord(RecordType::QUERY, $normalised, ['connection' => 'testing', 'sql' => $sql])]);

    $missed = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql, 'driver' => 'array']);
    $held = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => $sql, 'driver' => 'sqlite']);

    expect($missed['result']['held'])->toBe([])
        ->and($missed['notes'])->toBe([__('firewatch::messages.fingerprint_driver_note', ['group' => fpGroup($normalised)])])
        ->and($held['notes'])->toBe([]);
});

it('answers an id the store does not hold with a miss the agreeing check lets the assistant trust', function () {
    ingest([fpRecord(RecordType::COMMAND, 'env', ['name' => 'env'])]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'command', 'name' => 'orders:refund']);
    $group = fpGroup('orders:refund');

    expect($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['empty']['population'])->toBe(1)
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.no_match', ['population' => 1, 'filters' => "group: {$group}"]))
        ->and($envelope['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.agrees', ['group' => $group, 'types' => 'command']))
        ->and($envelope['result']['held'])->toBe([])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'command', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['next'])->toBe([]);
});

it('says a miss cannot be trusted when the latest stored record does not hash the way the recipe says', function () {
    ingest([fpRecord(RecordType::COMMAND, 'env', ['name' => 'env', '_group' => str_repeat('a', 32)])]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'command', 'name' => 'env']);

    expect($envelope['result']['held'])->toBe([])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'command', 'check' => 'disagrees', 'detail' => null]])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.disagrees', ['group' => fpGroup('env'), 'types' => 'command']));
});

it('lets the newest record decide the check, so a recipe that agrees again is trusted again', function () {
    ingest([
        fpRecord(RecordType::COMMAND, 'env', ['name' => 'env', '_group' => str_repeat('a', 32)], 0),
        fpRecord(RecordType::COMMAND, 'env', ['name' => 'env'], 10),
    ]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'command', 'name' => 'env']);

    expect($envelope['result']['recipe_check'])->toBe([['type' => 'command', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['result']['held'][0]['records'])->toBe(1);
});

it('does not evaluate the check without a record of the type, and says a miss then proves nothing', function () {
    ingest([fpRecord(RecordType::COMMAND, 'env', ['name' => 'env'])]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'mail', 'class' => 'App\\Mail\\OrderShipped']);

    expect($envelope['result']['recipe_check'])->toBe([['type' => 'mail', 'check' => 'not_evaluated', 'detail' => 'no_records']])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.not_evaluated', ['group' => fpGroup('App\\Mail\\OrderShipped'), 'types' => 'mail']))
        ->and($envelope['empty']['kind'])->toBe('no_match');
});

it('does not trust a key Nightwatch stored cut at 255 bytes, and checks the newest record whose fields are whole', function () {
    $whole = str_repeat('k', 300);
    $cut = fpRecord(RecordType::CACHE_EVENT, "array,{$whole}", ['store' => 'array', 'key' => substr($whole, 0, 255)], 10);

    ingest([$cut]);

    $onlyCut = Envelope::assert(Fingerprint::class, ['type' => 'cache-event', 'store' => 'array', 'key' => 'orders']);

    ingest([fpRecord(RecordType::CACHE_EVENT, 'array,orders', ['store' => 'array', 'key' => 'orders'], 0)]);

    $withWhole = Envelope::assert(Fingerprint::class, ['type' => 'cache-event', 'store' => 'array', 'key' => 'orders']);

    expect($onlyCut['result']['recipe_check'])->toBe([['type' => 'cache-event', 'check' => 'not_evaluated', 'detail' => 'cut']])
        ->and($onlyCut['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.not_evaluated', ['group' => fpGroup('array,orders'), 'types' => 'cache-event']))
        ->and($withWhole['result']['recipe_check'])->toBe([['type' => 'cache-event', 'check' => 'agrees', 'detail' => null]]);
});

it('skips a record whose recipe field Firewatch cut, and checks the next', function () {
    $sql = 'select '.str_repeat('1, ', 30000).'1';

    ingest([
        fpRecord(RecordType::QUERY, 'testing,select 1', ['connection' => 'testing', 'sql' => 'select 1'], 0),
        fpRecord(RecordType::QUERY, "testing,{$sql}", ['connection' => 'testing', 'sql' => $sql], 10),
    ]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'query', 'connection' => 'testing', 'sql' => 'select 1']);

    expect(storeRows("SELECT sql FROM queries WHERE sql LIKE '%truncated%'"))->toHaveCount(1)
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'query', 'check' => 'agrees', 'detail' => null]]);
});

it('reports a job name under its attempts and its dispatch, each with its own check, and leaves out a command of that name', function () {
    $name = 'App\\Jobs\\ShipOrder';

    ingest([
        fpRecord(RecordType::JOB_ATTEMPT, $name, ['name' => $name], 0),
        fpRecord(RecordType::QUEUED_JOB, $name, ['name' => $name], 10),
        fpRecord(RecordType::QUEUED_JOB, $name, ['name' => $name], 20),
        fpRecord(RecordType::COMMAND, $name, ['name' => $name], 30),
    ]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'job-attempt', 'name' => $name]);

    expect(array_map(fn (array $row) => [$row['type'], $row['records']], $envelope['result']['held']))->toBe([['job-attempt', 1], ['queued-job', 2]])
        ->and($envelope['result']['recipe_check'])->toBe([
            ['type' => 'job-attempt', 'check' => 'agrees', 'detail' => null],
            ['type' => 'queued-job', 'check' => 'agrees', 'detail' => null],
        ])
        ->and($envelope['coverage']['types_read'])->toBe(['job-attempt', 'queued-job'])
        ->and(array_column($envelope['next'], 'tool'))->toBe(['occurrences', 'rank']);
});

it('lets one type that disagrees outweigh one that agrees', function () {
    $name = 'App\\Jobs\\ShipOrder';

    ingest([
        fpRecord(RecordType::JOB_ATTEMPT, $name, ['name' => $name]),
        fpRecord(RecordType::QUEUED_JOB, $name, ['name' => $name, '_group' => str_repeat('a', 32)]),
    ]);

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'queued-job', 'name' => $name]);

    expect(array_column($envelope['result']['recipe_check'], 'check'))->toBe(['agrees', 'disagrees'])
        ->and($envelope['summary'])->toBe(trans_choice('firewatch::messages.fingerprint_summary_held', 1, [
            'group' => fpGroup($name),
            'records' => 1,
            'types' => 'job-attempt, queued-job',
            'check' => __('firewatch::messages.fingerprint_check.disagrees'),
        ]));
});
