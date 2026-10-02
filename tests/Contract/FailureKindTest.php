<?php

use ClaudioDekker\Firewatch\Store\FailureKind;

test('each kind has its stored name', function (FailureKind $kind, string $name) {
    expect($kind->value)->toBe($name);
})->with([
    'busy' => [FailureKind::BUSY, 'busy'],
    'full' => [FailureKind::FULL, 'full'],
    'corrupt' => [FailureKind::CORRUPT, 'corrupt'],
    'foreign' => [FailureKind::FOREIGN, 'foreign'],
    'schema' => [FailureKind::SCHEMA, 'schema'],
    'io' => [FailureKind::IO, 'io'],
    'other' => [FailureKind::OTHER, 'other'],
]);
