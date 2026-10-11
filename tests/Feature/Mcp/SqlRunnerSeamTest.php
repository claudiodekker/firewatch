<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Console\Doctor\ServerChecks;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use ClaudioDekker\Firewatch\Tests\Support\FakeSqlRunner;

beforeEach(function () {
    $writer = new Writer(app(Configuration::class), app(FailureLog::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
});

/**
 * Bind a fake SQL runner that reports the given reason, and fail the test if the real runner or its checks are ever built.
 */
function srUnavailable(Unavailable $reason): void
{
    app()->instance(SqlRunner::class, new FakeSqlRunner(reason: $reason));
    app()->bind(ChildRunner::class, fn () => throw new LogicException('The real SQL runner was built.'));
    app()->bind(Availability::class, fn () => throw new LogicException('The real availability checks were built.'));
}

it('reports the SQL tool unavailable in the doctor without spawning a child', function () {
    srUnavailable(Unavailable::SPAWN_FAILED);

    expect(app(ServerChecks::class)->sqlAccess()->message)->toBe(__('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.spawn_failed')]));
});

it('quotes the PHP binary in the spawn_failed fix only when it needs quoting, for the platform\'s shell', function (string $osFamily, string $binary, string $expected) {
    $runner = new FakeSqlRunner(reason: Unavailable::SPAWN_FAILED, binary: $binary);

    expect((new ServerChecks(app(), $runner, $osFamily))->sqlAccess()->fix)
        ->toBe("run `{$expected} -r \"echo 1;\"` to see why a PHP process can't start");
})->with([
    'windows without spaces' => ['Windows', 'C:\php\php.exe', 'C:\php\php.exe'],
    'windows with spaces' => ['Windows', 'C:\Program Files\php\php.exe', '"C:\Program Files\php\php.exe"'],
    'linux without spaces' => ['Linux', '/usr/bin/php', '/usr/bin/php'],
    'linux with spaces' => ['Linux', '/opt/my php/php', "'/opt/my php/php'"],
]);

it('reports the SQL tool available in the doctor from a fake that finds nothing wrong, without spawning a child', function () {
    app()->instance(SqlRunner::class, new FakeSqlRunner);
    app()->bind(ChildRunner::class, fn () => throw new LogicException('The real SQL runner was built.'));

    expect(app(ServerChecks::class)->sqlAccess()->message)->toBe(__('firewatch::messages.doctor.sql-access.ok'));
});

it('reports the SQL tool unavailable in describe, and offers no query, without spawning a child', function () {
    srUnavailable(Unavailable::HEAP_LIMIT);

    $answer = Answers::assert(Describe::class);

    expect($answer['result']['sql']['available'])->toBeFalse()
        ->and($answer['result']['sql']['reason'])->toBe(__('firewatch::messages.sql_unavailable.heap_limit'))
        ->and(array_column($answer['next'], 'tool'))->not->toContain('query');
});
