<?php

use ClaudioDekker\Firewatch\Store\FileKind;
use ClaudioDekker\Firewatch\Store\Schema;

function fileKindOf(?Closure $write): FileKind
{
    $path = tempnam(sys_get_temp_dir(), 'firewatch-kind-');

    test()->afterEach(fn () => @unlink($path));

    if ($write === null) {
        unlink($path);
    } else {
        $write($path);
    }

    return FileKind::of($path);
}

function sqliteFileWithApplicationId(string $path, int $applicationId): void
{
    $connection = new SQLite3($path);
    $connection->exec('PRAGMA application_id = '.$applicationId.'; CREATE TABLE orders (id INTEGER)');
    $connection->close();
}

it('classifies a file by its header', function (?Closure $write, FileKind $kind) {
    expect(fileKindOf($write))->toBe($kind);
})->with([
    'no file' => [null, FileKind::Empty],
    'a zero-byte file' => [fn (string $path) => null, FileKind::Empty],
    'a text file' => [fn (string $path) => file_put_contents($path, str_repeat('not a database ', 100)), FileKind::NotSqlite],
    'a file shorter than the SQLite magic' => [fn (string $path) => file_put_contents($path, 'SQLite'), FileKind::NotSqlite],
    'a SQLite file cut off before its application id' => [fn (string $path) => file_put_contents($path, "SQLite format 3\0".str_repeat("\0", 20)), FileKind::Foreign],
    'a SQLite file of another application' => [fn (string $path) => sqliteFileWithApplicationId($path, 0), FileKind::Foreign],
    'a SQLite file of another application id' => [fn (string $path) => sqliteFileWithApplicationId($path, Schema::APPLICATION_ID + 1), FileKind::Foreign],
    'a SQLite file with Firewatch\'s application id' => [fn (string $path) => sqliteFileWithApplicationId($path, Schema::APPLICATION_ID), FileKind::Firewatch],
]);

it('reads a directory as no store', function () {
    expect(FileKind::of(sys_get_temp_dir()))->toBe(FileKind::Empty);
});
