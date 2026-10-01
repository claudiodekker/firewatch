<?php

use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreStamp;

function stampOf(string ...$statements): StoreStamp
{
    $connection = new SQLite3(':memory:');

    foreach ($statements as $statement) {
        $connection->exec($statement);
    }

    return StoreStamp::read($connection);
}

it('reads the stamps and the table count of a store', function () {
    $stamp = stampOf('PRAGMA application_id = 7', 'PRAGMA user_version = 3', 'CREATE TABLE orders (id INTEGER)');

    expect($stamp->applicationId)->toBe(7)
        ->and($stamp->userVersion)->toBe(3)
        ->and($stamp->objects)->toBe(1);
});

it('names what a store is by its stamps', function (array $statements, bool $isCurrent, bool $isFresh, bool $isFirewatch) {
    $stamp = stampOf(...$statements);

    expect([$stamp->isCurrent(), $stamp->isFresh(), $stamp->isFirewatch()])->toBe([$isCurrent, $isFresh, $isFirewatch]);
})->with([
    'a database with nothing in it' => [[], false, true, false],
    'a current store' => [['PRAGMA application_id = '.Schema::APPLICATION_ID, 'PRAGMA user_version = '.Schema::VERSION, 'CREATE TABLE records (id INTEGER)'], true, false, true],
    'a store of an older schema' => [['PRAGMA application_id = '.Schema::APPLICATION_ID, 'PRAGMA user_version = '.(Schema::VERSION - 1), 'CREATE TABLE records (id INTEGER)'], false, false, true],
    'a store of a newer schema' => [['PRAGMA application_id = '.Schema::APPLICATION_ID, 'PRAGMA user_version = '.(Schema::VERSION + 1), 'CREATE TABLE records (id INTEGER)'], false, false, true],
    'a database of another application' => [['CREATE TABLE orders (id INTEGER)'], false, false, false],
    'a stamped database of another application' => [['PRAGMA application_id = 5', 'PRAGMA user_version = '.Schema::VERSION], false, false, false],
    'a database with a version but no tables' => [['PRAGMA user_version = 4'], false, false, false],
]);
