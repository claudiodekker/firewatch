<?php

use ClaudioDekker\Firewatch\Capture\RecordMapper;
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

test('each type is grouped by the recipe recomputed from its record', function (Producer $producer, Closure $recipe) {
    $record = sensorRecord($producer);

    expect(hash('xxh128', $recipe($record)))->toBe($record['_group']);
})->with([
    'request' => [Producer::REQUEST, fn (array $record) => implode('|', $record['route_methods']).",{$record['route_domain']},{$record['route_path']}"],
    'command' => [Producer::COMMAND, fn (array $record) => $record['name']],
    'job attempt' => [Producer::JOB_ATTEMPT, fn (array $record) => $record['name']],
    'scheduled task' => [Producer::SCHEDULED_TASK, fn (array $record) => "{$record['name']},{$record['cron']},{$record['timezone']}"],
    'query' => [Producer::QUERY, fn (array $record) => "{$record['connection']},{$record['sql']}"],
    'exception' => [Producer::EXCEPTION, fn (array $record) => "{$record['class']},{$record['code']},{$record['file']},{$record['line']}"],
    'cache event' => [Producer::CACHE_EVENT, fn (array $record) => "{$record['store']},{$record['key']}"],
    'mail' => [Producer::MAIL, fn (array $record) => $record['class']],
    'notification' => [Producer::NOTIFICATION, fn (array $record) => $record['class']],
    'outgoing request' => [Producer::OUTGOING_REQUEST, fn (array $record) => $record['host']],
    'queued job' => [Producer::QUEUED_JOB, fn (array $record) => $record['name']],
]);

test('Nightwatch writes no group for the types that have none', function (Producer $producer) {
    $record = sensorRecord($producer);

    expect($record)->not->toHaveKey('_group');
})->with(['a log' => Producer::LOG, 'a user' => Producer::USER]);
