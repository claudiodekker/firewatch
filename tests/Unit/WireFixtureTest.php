<?php

use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\Sensors;
use Workbench\App\Fixtures\WireFixture;

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function sensorOutput(array $changes): array
{
    $fixtures = new WireFixture(new Sensors);
    $wire = [...$fixtures->load(Producer::CACHE_EVENT), 'timestamp' => 1767225600.25, 'duration' => 120, ...$changes];

    // A null change is a field the sensors stopped writing.
    return $fixtures->normalise(array_filter($wire, fn (mixed $value) => $value !== null));
}

it('names the fixture and the field where the sensors\' output differs from it', function (array $changes, array $differences) {
    $record = sensorOutput($changes);

    $result = (new WireFixture(new Sensors))->differences(Producer::CACHE_EVENT, $record);

    expect($result)->toBe($differences);
})->with([
    'the same shape' => ['changes' => [], 'differences' => []],
    'a whole-number timestamp' => ['changes' => ['timestamp' => 1767225600], 'differences' => []],
    'a missing field' => ['changes' => ['ttl' => null], 'differences' => ['cache-event.json: ttl is missing from what the sensors write']],
    'a new field' => ['changes' => ['colour' => 'red'], 'differences' => ['cache-event.json: colour is not in the fixture']],
    'a field of another kind' => ['changes' => ['ttl' => '60'], 'differences' => ['cache-event.json: ttl is integer in the fixture, the sensors write string']],
    'a varying field of another kind' => ['changes' => ['duration' => 'slow'], 'differences' => ['cache-event.json: duration is integer in the fixture, the sensors write string']],
]);
