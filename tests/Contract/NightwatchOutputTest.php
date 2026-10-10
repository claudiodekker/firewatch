<?php

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Mcp\Recipe;
use ClaudioDekker\Firewatch\RecordType;
use Laravel\Nightwatch\Core;
use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\Sensors;
use Workbench\App\Fixtures\WireFixture;

/**
 * The instant Nightwatch's clock starts at while the sensors are observed.
 */
const OBSERVED_CLOCK_START = 2000000000.0;

/**
 * @param  (Closure(Core<*>): void)|null  $prepare
 * @return array<string, mixed>
 */
function sensorRecord(Producer $producer, ?Closure $prepare = null): array
{
    $record = app(Sensors::class)->recordOf($producer, $prepare);

    return json_decode(json_encode($record, RecordMapper::JSON_FLAGS), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<array<string, mixed>>
 */
function sensorRecords(Producer $producer): array
{
    $records = app(Sensors::class)->record($producer);

    return array_map(fn (array $record) => json_decode(json_encode($record, RecordMapper::JSON_FLAGS), associative: true, flags: JSON_THROW_ON_ERROR), $records);
}

/**
 * Get when Nightwatch stamps a record, from a clock whose readings are powers of two milliseconds apart.
 */
function stampedAt(Producer $producer): string
{
    $readings = [];

    $record = sensorRecord($producer, function (Core $core) use (&$readings) {
        $core->clock->microtimeResolver = function () use (&$readings) {
            return $readings[] = OBSERVED_CLOCK_START + 2 ** count($readings) / 1000;
        };
    });

    $isReading = fn (float $instant) => collect($readings)->contains(fn (float $reading) => abs($reading - $instant) < 1e-6);
    $duration = $record['duration'] / 1e6;

    return match (true) {
        $isReading($record['timestamp'] + $duration) => 'start',
        $isReading($record['timestamp']) && $isReading($record['timestamp'] - $duration) => 'end',
        default => 'unknown',
    };
}

test('Nightwatch writes the fields of the contract table, of the kinds it accepts', function (Producer $producer) {
    $record = sensorRecord($producer);

    $kinds = array_map(fn (mixed $value) => [WireFixture::kindOf($value)], $record);

    expect($kinds)->toEqual($producer->type()->acceptedTypes(), "Nightwatch's {$producer->value} records no longer match the contract table.");
})->with(Producer::cases());

test('Nightwatch writes the version of each type the contract table was built from', function (Producer $producer) {
    $record = sensorRecord($producer);

    expect($producer->type()->hasVersion($record['v']))->toBeTrue("Nightwatch writes {$producer->value} records at version {$record['v']}.");
})->with(Producer::cases());

test('each type with a duration is stamped at its start or its end, as the contract table says', function (Producer $producer) {
    $stampedAt = stampedAt($producer);

    expect($stampedAt)->toBe($producer->type()->isStampedAtEnd() ? 'end' : 'start');
})->with(array_filter(Producer::cases(), fn (Producer $producer) => array_key_exists('duration', $producer->type()->acceptedTypes())));

test('each type with a recipe is grouped by a candidate the shipped recipe computes from its record', function (Producer $producer) {
    $record = sensorRecord($producer);

    expect(array_column(Recipe::candidates($producer->type(), $record), 'group'))->toContain($record['_group']);
})->with(array_filter(Producer::cases(), fn (Producer $producer) => ! in_array($producer->type(), [RecordType::EXCEPTION, RecordType::LOG, RecordType::USER], true)));

test('an exception is grouped by its class, code, file and line', function (Producer $producer) {
    $record = sensorRecord($producer);

    expect(hash('xxh128', "{$record['class']},{$record['code']},{$record['file']},{$record['line']}"))->toBe($record['_group']);
})->with([Producer::EXCEPTION, Producer::FATAL_ERROR]);

test('a query on a driver Nightwatch normalises is grouped by its normalised SQL, and on another by its SQL as written', function () {
    $record = sensorRecord(Producer::QUERY_LIST);

    $normalised = Recipe::candidates(RecordType::QUERY, $record, 'sqlite');
    $written = Recipe::candidates(RecordType::QUERY, $record, 'array');
    $open = Recipe::candidates(RecordType::QUERY, $record);

    expect($record['sql'])->toBe('select 1 where 1 in (?, ?)')
        ->and(array_column($normalised, 'group'))->toBe([$record['_group']])
        ->and($normalised[0]['input'])->toBe("{$record['connection']},select 1 where 1 in (...?)")
        ->and(array_column($written, 'group'))->not->toContain($record['_group'])
        ->and(array_column($open, 'group'))->toBe([$record['_group'], $written[0]['group']]);
});

test('Nightwatch writes no group for the types that have none', function (Producer $producer) {
    $record = sensorRecord($producer);

    expect($record)->not->toHaveKey('_group');
})->with(['a log' => Producer::LOG, 'a user' => Producer::USER]);

test('Nightwatch writes a fatal error without a trace or an execution id, and any other exception with a trace', function () {
    $fatal = sensorRecord(Producer::FATAL_ERROR);
    $thrown = sensorRecord(Producer::EXCEPTION);
    $fixture = app(WireFixture::class)->load(Producer::FATAL_ERROR);
    $written = app(WireFixture::class)->produce(Producer::FATAL_ERROR);

    $frames = json_decode($thrown['trace'], associative: true, flags: JSON_THROW_ON_ERROR);

    $message = 'The wire no longer tells a fatal error by its missing trace: drop `fatal` from exception-clusters.';

    expect([$fatal['trace'], $fatal['execution_id'], $fatal['handled']])->toBe(['', '', false], $message)
        ->and([$fixture['trace'], $fixture['execution_id'], $fixture['handled']])->toBe(['', '', false], $message)
        ->and($written)->toBe($fixture, 'Run `composer fixtures`: the fatal error fixture is not what the sensors write now.')
        ->and($frames)->toBeArray($message)->not->toBeEmpty($message)
        ->and(array_is_list($frames))->toBeTrue($message);
});

test('Nightwatch keeps a log written outside an execution, joined to the request it is written around', function () {
    [$before, $request, $after] = sensorRecords(Producer::LOG_OUTSIDE_EXECUTION);
    $fixture = app(WireFixture::class)->load(Producer::LOG_OUTSIDE_EXECUTION);

    expect([$before['t'], $request['t'], $after['t']])->toBe(['log', 'request', 'log'])
        ->and($before['execution_id'])->toBe($request['trace_id'])
        ->and($after['execution_id'])->toBe($request['trace_id'])
        ->and($before['execution_stage'])->toBe('before_middleware')
        ->and($after['execution_stage'])->toBe('end')
        ->and($request['logs'])->toBe(1)
        ->and($fixture['execution_source'])->toBe('request')
        ->and($fixture['execution_id'])->toBe('{uuid}')
        ->and(app(WireFixture::class)->produce(Producer::LOG_OUTSIDE_EXECUTION))->toBe($fixture, 'Run `composer fixtures`: the log fixture is not what the sensors write now.');
});

test('Nightwatch writes a request prepared the way Octane prepares one with a bootstrap of 0, and any other request with a bootstrap above 0', function () {
    $octane = sensorRecord(Producer::OCTANE_REQUEST);
    $served = sensorRecord(Producer::REQUEST);

    expect($octane['bootstrap'])->toBe(0)
        ->and($octane['before_middleware'])->toBeGreaterThan(0)
        ->and($served['bootstrap'])->toBeGreaterThan(0)
        ->and(app(WireFixture::class)->load(Producer::OCTANE_REQUEST)['bootstrap'])->toBe('{duration}');
});

test('Nightwatch gives every unrouted request one group, whatever its path, and a routed request another', function () {
    [$first, $second] = sensorRecords(Producer::UNROUTED_REQUEST);
    $routed = sensorRecord(Producer::REQUEST);

    expect([$first['url'], $second['url']])->not->toBeEmpty()
        ->and($first['url'])->not->toBe($second['url'])
        ->and([$first['route_path'], $first['route_methods'], $first['route_domain']])->toBe(['', [], ''])
        ->and($first['status_code'])->toBe(404)
        ->and($first['_group'])->toBe(hash('xxh128', ',,'))
        ->and($second['_group'])->toBe($first['_group'])
        ->and($routed['_group'])->not->toBe($first['_group']);
});
