<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Sql\Availability;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlFailure;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

beforeEach(function () {
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
});

/**
 * Use the given availability, and a runner on the marker stand-in that leaves a file beside the store when it is spawned.
 */
function qaMarker(Availability $availability): void
{
    app()->instance(Availability::class, $availability);
    app()->instance(SqlRunner::class, new ChildRunner(app(Configuration::class), script: dirname(__DIR__, 2).'/Fixtures/Sql/marker.php', availability: $availability));
}

function qaText(): string
{
    $response = FirewatchServer::tool(Query::class, ['sql' => 'SELECT 1']);

    return (fn () => $this->content())->call($response)[0];
}

function qaUnavailable(string $reason): string
{
    return __('firewatch::messages.unavailable', ['reason' => __("firewatch::messages.sql_unavailable.{$reason}")]);
}

function qaSpawned(): bool
{
    return is_file(app(Configuration::class)->database.'.spawned');
}

describe('the static reasons', function () {
    it('refuses before spawning a child when the host can not isolate it', function (Closure $availability, string $reason) {
        qaMarker($availability());

        expect(qaText())->toBe(qaUnavailable($reason))
            ->and(qaSpawned())->toBeFalse();
    })->with([
        'the sqlite3 extension is not loaded' => [fn () => new Availability(sqliteVersion: fn () => null), 'sqlite3_missing'],
        'PHP_BINARY does not exist' => [fn () => new Availability(phpBinary: sys_get_temp_dir().'/firewatch-missing-php-'.bin2hex(random_bytes(4))), 'php_binary'],
        'PHP_BINARY is a directory' => [fn () => new Availability(phpBinary: sys_get_temp_dir()), 'php_binary'],
        'SQLite is 3.37.2' => [fn () => new Availability(sqliteVersion: fn () => '3.37.2'), 'sqlite_too_old'],
    ])->group('process');

    it('names the first reason in check order when two facts fail', function (Closure $availability, string $reason) {
        qaMarker($availability());

        expect(qaText())->toBe(qaUnavailable($reason))
            ->and(qaSpawned())->toBeFalse();
    })->with([
        'sqlite3 missing and PHP_BINARY a directory' => [fn () => new Availability(phpBinary: sys_get_temp_dir(), sqliteVersion: fn () => null), 'sqlite3_missing'],
        'PHP_BINARY a directory and SQLite too old' => [fn () => new Availability(phpBinary: sys_get_temp_dir(), sqliteVersion: fn () => '3.37.2'), 'php_binary'],
    ])->group('process');

    it('refuses before reading its arguments', function () {
        qaMarker(new Availability(sqliteVersion: fn () => null));

        $response = FirewatchServer::tool(Query::class, ['limit' => 0]);

        expect((fn () => $this->content())->call($response)[0])->toBe(qaUnavailable('sqlite3_missing'));
    });

    it('runs at exactly SQLite 3.38.0', function () {
        app()->instance(Availability::class, new Availability(sqliteVersion: fn () => '3.38.0'));

        expect(Envelope::assert(Query::class, ['sql' => 'SELECT 1 AS n'])['result']['rows'])->toBe([[1]]);
    })->group('process');

    it('answers again once the cause is fixed, with no restart', function () {
        $binary = sys_get_temp_dir().'/firewatch-php-'.bin2hex(random_bytes(4));
        $availability = new Availability(phpBinary: $binary);
        app()->instance(Availability::class, $availability);

        expect(qaText())->toBe(qaUnavailable('php_binary'));

        symlink(PHP_BINARY, $binary);

        try {
            expect(Envelope::assert(Query::class, ['sql' => 'SELECT 1 AS n'])['result']['rows'])->toBe([[1]]);
        } finally {
            unlink($binary);
        }
    })->group('process', 'posix');
});

/**
 * Make an empty directory of the test's own for the runner's temp files.
 */
function qaTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/firewatch-temp-'.bin2hex(random_bytes(4));
    mkdir($directory);

    test()->beforeApplicationDestroyed(fn () => @rmdir($directory));

    return $directory;
}

/**
 * Get the files left in the directory.
 *
 * @return list<string>
 */
function qaLeft(string $directory): array
{
    return array_values(array_diff(scandir($directory), ['.', '..']));
}

describe('the temp files', function () {
    it('leaves none of the call\'s files behind', function (string $script, float $deadline, string $text) {
        $directory = qaTemporaryDirectory();
        app()->instance(SqlRunner::class, new ChildRunner(app(Configuration::class), deadline: $deadline, script: dirname(__DIR__, 2)."/Fixtures/Sql/{$script}.php", temporaryDirectory: $directory));

        expect(qaText())->toStartWith($text)
            ->and(qaLeft($directory))->toBe([]);
    })->with([
        'a child that exits' => ['marker', ChildRunner::DEADLINE_SECONDS, '## query'],
        'a child killed at the deadline' => ['sleep', 0.2, 'error: deadline'],
        'a child killed at the output cap' => ['flood', ChildRunner::DEADLINE_SECONDS, 'error: aborted'],
    ])->group('process');

    it('leaves none of the call\'s files behind when the child can not be started', function () {
        $directory = qaTemporaryDirectory();
        $missing = new Availability(phpBinary: sys_get_temp_dir().'/firewatch-missing-php-'.bin2hex(random_bytes(4)));
        $runner = new ChildRunner(app(Configuration::class), availability: $missing, temporaryDirectory: $directory);

        expect(fn () => $runner->run('SELECT 1', 1))->toThrow(fn (SqlFailure $failure) => expect($failure->unavailable)->toBe(Unavailable::SPAWN_FAILED))
            ->and(qaLeft($directory))->toBe([]);
    })->group('process');
});

/**
 * Get a runner on the given stand-in child, or on the real one.
 */
function qaRunner(?string $script = null, float $deadline = ChildRunner::DEADLINE_SECONDS): ChildRunner
{
    return new ChildRunner(app(Configuration::class), deadline: $deadline, script: $script === null ? ChildRunner::SCRIPT : dirname(__DIR__, 2)."/Fixtures/Sql/{$script}.php");
}

describe('the probe', function () {
    it('finds a working child without reading the store', function () {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink(app(Configuration::class)->database.$suffix);
        }

        expect((new Availability)->reason(probe: qaRunner()))->toBeNull();
    })->group('process');

    it('names the reason a child\'s self-check reports', function () {
        expect((new Availability)->reason(probe: qaRunner('unavailable')))->toBe(Unavailable::HEAP_LIMIT);
    })->group('process');

    it('is spawn_failed when the child never answers', function () {
        expect((new Availability)->reason(probe: qaRunner('sleep', deadline: 0.2)))->toBe(Unavailable::SPAWN_FAILED);
    })->group('process');

    it('never spawns when a static reason holds', function () {
        expect((new Availability(sqliteVersion: fn () => '3.37.2'))->reason(probe: qaRunner('marker')))->toBe(Unavailable::SQLITE_TOO_OLD)
            ->and(qaSpawned())->toBeFalse();
    })->group('process');
});
