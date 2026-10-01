<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\Writer;

it('answers that no store exists yet, with the store clock', function () {
    $this->travelTo('2026-09-30 14:00:00.250000');
    $path = app(Configuration::class)->database;

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(implode("\n", [
        '## overview',
        __('firewatch::messages.store_clock', ['time' => '2026-09-30 14:00:00.250000', 'epoch' => '1790776800.25']),
        __('firewatch::messages.no_store', ['path' => $path]),
    ]));
});

it('answers that the store is empty when it holds no records', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn () => null);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_empty', ['path' => $path]));
});

it('counts a request the application served', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->get('/');

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee('- **request**: 1');
});

it('creates nothing where the store would be', function () {
    $path = app(Configuration::class)->database;

    FirewatchServer::tool(Overview::class);

    expect(dirname($path))->not->toBeDirectory();
});

it('answers that the store is unusable, with its reason', function (Closure $arrange, string $key, Closure $replace) {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    $arrange($path);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__("firewatch::messages.store_unusable.{$key}", $replace($path)));
})->with([
    'a foreign file' => [
        fn (string $path) => (new SQLite3($path))->exec('CREATE TABLE orders (id INTEGER)'),
        'foreign_file',
        fn (string $path) => ['path' => $path],
    ],
    'a file that is not a database' => [
        fn (string $path) => file_put_contents($path, str_repeat('not a database ', 100)),
        'foreign_file',
        fn (string $path) => ['path' => $path],
    ],
    'an older schema' => [
        fn (string $path) => (new SQLite3($path))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 0; CREATE TABLE records (id INTEGER)'),
        'older_schema',
        fn (string $path) => ['path' => $path, 'found' => 0, 'expected' => Schema::VERSION],
    ],
    'a newer schema' => [
        fn (string $path) => (new SQLite3($path))->exec('PRAGMA application_id = '.Schema::APPLICATION_ID.'; PRAGMA user_version = 2; CREATE TABLE records (id INTEGER)'),
        'newer_schema',
        fn (string $path) => ['path' => $path, 'found' => 2, 'expected' => Schema::VERSION],
    ],
]);

it('answers that a damaged store cannot be read', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
    clearstatcache(true, $path);
    $handle = fopen($path, 'r+b');
    fseek($handle, 4096);
    fwrite($handle, str_repeat("\xff", filesize($path) - 4096));
    fclose($handle);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.corrupt')]));
});

it('answers that a busy store cannot be read yet', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
    $connection = new SQLite3($path);
    $connection->exec('PRAGMA journal_mode = DELETE');
    $connection->exec('BEGIN EXCLUSIVE');
    app()->instance(Reader::class, new class(app(Configuration::class)) extends Reader
    {
        protected const BUSY_TIMEOUT_MILLISECONDS = 20;
    });

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.busy')]));
    $connection->exec('ROLLBACK');
});

it('answers that SQLite is too old to read the store', function () {
    $path = app(Configuration::class)->database;
    app(Writer::class)->transaction(fn (SQLite3 $connection) => null);
    app()->instance(Reader::class, new Reader(app(Configuration::class), sqliteVersion: '3.37.2'));

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_unusable.sqlite_too_old', ['path' => $path, 'version' => '3.37.2', 'minimum' => '3.38.0']));
});

it('does not touch an unusable store', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));
    $before = md5_file($path);

    FirewatchServer::tool(Overview::class);

    expect(md5_file($path))->toBe($before)
        ->and(scandir(dirname($path)))->toBe(['.', '..', basename($path)]);
});
