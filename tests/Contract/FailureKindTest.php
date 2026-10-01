<?php

use ClaudioDekker\Firewatch\Store\FailureKind;

test('each kind has its stored name', function (FailureKind $kind, string $name) {
    expect($kind->value)->toBe($name);
})->with([
    [FailureKind::BUSY, 'busy'],
    [FailureKind::FULL, 'full'],
    [FailureKind::CORRUPT, 'corrupt'],
    [FailureKind::FOREIGN, 'foreign'],
    [FailureKind::SCHEMA, 'schema'],
    [FailureKind::IO, 'io'],
    [FailureKind::OTHER, 'other'],
]);
