<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * @return list<array<string, mixed>>
 */
function storedRecords(string $columns = '*'): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($columns) {
        $result = $connection->query("SELECT {$columns} FROM records ORDER BY id");
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

it('stores a request the application served, with its common columns', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $this->get('/?page=2');

    $requests = array_values(array_filter(storedRecords(), fn (array $record) => $record['type'] === 'request'));
    $data = json_decode($requests[0]['data'], associative: true);

    expect($requests)->toHaveCount(1)
        ->and($requests[0])->toMatchArray(['v' => 1, 'source' => 'request', 'execution_id' => $requests[0]['trace_id']])
        ->and($requests[0]['started_at'])->toBeFloat()
        ->and($requests[0]['duration'])->toBeInt()
        ->and($requests[0]['ended_at'])->toBeGreaterThanOrEqual($requests[0]['started_at'])
        ->and($requests[0]['group_hash'])->toBeString()->not->toBeEmpty()
        ->and($data)->toMatchArray(['method' => 'GET', 'route_path' => '/', 'status_code' => 200])
        ->and($data)->not->toHaveKeys(['t', 'v', 'timestamp', 'duration', '_group', 'trace_id', 'user', 'deploy', 'server']);
});

it('creates a private store directory with its own ignore file on the first batch', function () {
    $path = app(Configuration::class)->database;
    Cache::get('first');

    Nightwatch::digest();

    expect(fileperms(dirname($path)) & 0777)->toBe(0700)
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(file_get_contents(dirname($path).'/.gitignore'))->toBe("*\n");
})->group('posix');

it('keeps the order the sensors wrote a batch in', function () {
    Cache::get('first');
    Cache::get('second');
    Cache::get('third');

    Nightwatch::digest();

    $keys = array_column(storedRecords("json_extract(data, '$.key') AS key"), 'key');

    expect($keys)->toBe(['first', 'second', 'third']);
});

it('stores a child event with the source and execution it belongs to', function () {
    Cache::get('first');

    Nightwatch::digest();

    [$event] = storedRecords('type, source, execution_id, trace_id');

    expect($event)->toMatchArray(['type' => 'cache-event', 'source' => 'command', 'execution_id' => $event['trace_id']])
        ->and($event['execution_id'])->not->toBeEmpty();
});

it('stores a full buffer without waiting for a digest', function (int $writes, bool $stored) {
    for ($write = 1; $write <= $writes; $write++) {
        Cache::get("key-{$write}");
    }

    expect(app(Reader::class)->exists())->toBe($stored);
})->with([
    'one short of full' => ['writes' => 499, 'stored' => false],
    'full' => ['writes' => 500, 'stored' => true],
]);

it('drops the oldest record of a full buffer while the execution is not sampled', function () {
    Nightwatch::dontSample();

    for ($write = 1; $write <= 501; $write++) {
        Cache::get("key-{$write}");
    }

    Nightwatch::sample();
    Nightwatch::digest();

    $keys = array_column(storedRecords("json_extract(data, '$.key') AS key"), 'key');

    expect($keys)->toHaveCount(500)
        ->and($keys[0])->toBe('key-2');
});

it('discards the buffer of an execution that is not sampled', function () {
    Nightwatch::dontSample();
    Cache::get('first');
    Nightwatch::digest();

    Nightwatch::sample();
    Nightwatch::digest();

    expect(app(Reader::class)->exists())->toBeFalse();
});

it('stores a record written at once without waiting for a digest', function () {
    Nightwatch::report(new RuntimeException('The payment failed.'), handled: false);

    $types = array_column(storedRecords('type'), 'type');

    expect($types)->toBe(['exception']);
});

it('stamps a new store with Firewatch\'s application id and schema version', function () {
    Cache::get('first');

    Nightwatch::digest();

    $stamps = app(Reader::class)->snapshot(fn (SQLite3 $connection) => [
        $connection->querySingle('PRAGMA application_id'),
        $connection->querySingle('PRAGMA user_version'),
    ]);

    expect($stamps)->toBe([0x46575443, 1]);
});

it('stores a batch without running a query through Laravel\'s database layer', function () {
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries) {
        $queries++;
    });
    Cache::get('first');

    Nightwatch::digest();

    expect($queries)->toBe(0)
        ->and(app(Reader::class)->exists())->toBeTrue();
});

it('stores nothing when Off', function () {
    config()->set('firewatch.enabled', false);
    registerFirewatch();

    Cache::get('first');
    Nightwatch::digest();

    expect(app(Reader::class)->exists())->toBeFalse();
});

it('reports a batch it could not store without failing the application', function () {
    $blocked = app(Configuration::class)->database;
    mkdir(dirname($blocked), recursive: true);
    touch($blocked.'.parent');
    config()->set('firewatch.database', $blocked.'.parent/firewatch.sqlite');
    registerFirewatch();

    Cache::get('first');
    Nightwatch::digest();

    Exceptions::assertReported(RuntimeException::class);
});

it('ignores what the sensors record while it stores a batch', function () {
    $blocked = app(Configuration::class)->database;
    mkdir(dirname($blocked), recursive: true);
    touch($blocked.'.parent');
    config()->set(['firewatch.database' => $blocked.'.parent/firewatch.sqlite', 'logging.default' => 'null']);
    registerFirewatch();
    app()->instance(ExceptionHandler::class, Exceptions::getFacadeRoot()->handler());
    $failures = 0;
    app(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$failures) {
        if (++$failures === 1) {
            Nightwatch::report($exception, handled: false);
        }
    });
    Cache::get('first');
    Nightwatch::digest();
    unlink($blocked.'.parent');

    Cache::get('second');
    Nightwatch::digest();

    $records = storedRecords("type, json_extract(data, '$.key') AS key");

    expect($records)->toBe([['type' => 'cache-event', 'key' => 'second']])
        ->and($failures)->toBe(1);
});
