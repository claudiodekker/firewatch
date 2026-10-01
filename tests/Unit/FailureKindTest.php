<?php

use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\StoreFailure;

test('a failure is classified by its SQLite result code, or by the kind the store gave it', function (Throwable $exception, FailureKind $kind) {
    expect(FailureKind::of($exception))->toBe($kind);
})->with([
    'busy' => [new SQLite3Exception('database is locked', 5), FailureKind::BUSY],
    'busy recovering' => [new SQLite3Exception('database is locked', 261), FailureKind::BUSY],
    'busy snapshot' => [new SQLite3Exception('database is locked', 517), FailureKind::BUSY],
    'busy timeout' => [new SQLite3Exception('database is locked', 773), FailureKind::BUSY],
    'full' => [new SQLite3Exception('database or disk is full', 13), FailureKind::FULL],
    'corrupt' => [new SQLite3Exception('database disk image is malformed', 11), FailureKind::CORRUPT],
    'corrupt index' => [new SQLite3Exception('database disk image is malformed', 779), FailureKind::CORRUPT],
    'not a database' => [new SQLite3Exception('file is not a database', 26), FailureKind::FOREIGN],
    'io' => [new SQLite3Exception('disk I/O error', 10), FailureKind::IO],
    'io on write' => [new SQLite3Exception('disk I/O error', 778), FailureKind::IO],
    'cannot open' => [new SQLite3Exception('unable to open database file', 14), FailureKind::IO],
    'locked' => [new SQLite3Exception('database table is locked', 6), FailureKind::OTHER],
    'another SQLite error' => [new SQLite3Exception('no such table: records', 1), FailureKind::OTHER],
    'a store failure' => [new StoreFailure(FailureKind::FOREIGN, 'The file is not a store.'), FailureKind::FOREIGN],
    'any other failure' => [new RuntimeException('Something failed.', 5), FailureKind::OTHER],
]);
