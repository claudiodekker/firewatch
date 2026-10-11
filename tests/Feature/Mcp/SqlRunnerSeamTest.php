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
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\FakeSqlRunner;
use Illuminate\Support\ProcessUtils;

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

    $result = app(ServerChecks::class)->sqlAccess();

    expect($result->message)->toBe(__('firewatch::messages.doctor.sql-access.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.spawn_failed')]))
        ->and($result->fix)->toBe('run `'.ProcessUtils::escapeArgument(PHP_BINARY).' -r "echo 1;"` to see why a PHP process can\'t start');
});

it('reports the SQL tool available in the doctor from a fake that finds nothing wrong, without spawning a child', function () {
    app()->instance(SqlRunner::class, new FakeSqlRunner);
    app()->bind(ChildRunner::class, fn () => throw new LogicException('The real SQL runner was built.'));

    expect(app(ServerChecks::class)->sqlAccess()->message)->toBe(__('firewatch::messages.doctor.sql-access.ok'));
});

it('reports the SQL tool unavailable in describe, and offers no query, without spawning a child', function () {
    srUnavailable(Unavailable::HEAP_LIMIT);

    $answer = Envelope::assert(Describe::class);

    expect($answer['result']['sql']['available'])->toBeFalse()
        ->and($answer['result']['sql']['reason'])->toBe(__('firewatch::messages.sql_unavailable.heap_limit'))
        ->and(array_column($answer['next'], 'tool'))->not->toContain('query');
});
