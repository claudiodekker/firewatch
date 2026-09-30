<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Auth\GenericUser;
use Laravel\Nightwatch\Core;

/**
 * @return list<array<string, mixed>>
 */
function readStore(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

/**
 * @param  array<string, mixed>  $fields
 */
function ingestUser(array $fields): void
{
    $record = [
        'v' => 1,
        't' => 'user',
        'timestamp' => 1767225600.25,
        'id' => '7',
        'name' => 'Taylor',
        'username' => 'taylor@example.com',
        ...$fields,
    ];

    // A null field is one the wire omitted.
    app(Core::class)->ingest->writeNow(array_filter($record, fn (mixed $value) => $value !== null));
}

it('keeps the signed-in user of a request in the user directory, not as a record', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']));

    $this->get('/');

    [$user] = readStore('SELECT * FROM users');

    expect($user)->toMatchArray(['id' => '7', 'name' => 'Taylor', 'username' => 'taylor@example.com', 'last_seen' => $user['first_seen']])
        ->and($user['first_seen'])->toBeFloat()
        ->and(readStore("SELECT id FROM records WHERE type = 'user'"))->toBe([]);
});

it('counts no drift for the signed-in user of a request', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']));

    $this->get('/');

    expect(readStore("SELECT kind, detail FROM drift WHERE kind <> 'version'"))->toBe([]);
});

it('keeps when a user was first seen and takes the rest from the latest sighting', function () {
    ingestUser(['timestamp' => 1767225600.25]);

    ingestUser(['timestamp' => 1767225900.5, 'name' => 'Taylor Otwell', 'username' => 'taylor@laravel.com']);

    expect(readStore('SELECT * FROM users'))->toBe([
        ['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@laravel.com', 'first_seen' => 1767225600.25, 'last_seen' => 1767225900.5],
    ]);
});

it('never moves a user\'s last sighting back for a batch stored out of order', function () {
    ingestUser(['timestamp' => 1767225900.5]);

    ingestUser(['timestamp' => 1767225600.25]);

    expect(readStore('SELECT last_seen FROM users'))->toBe([['last_seen' => 1767225900.5]]);
});

it('keeps a user record without a usable id as a record', function (array $fields) {
    ingestUser($fields);

    expect(readStore('SELECT type, started_at FROM records'))->toBe([['type' => 'user', 'started_at' => 1767225600.25]])
        ->and(readStore('SELECT id FROM users'))->toBe([]);
})->with([
    'empty' => [['id' => '']],
    'missing' => [['id' => null]],
    'not a string' => [['id' => 7]],
]);

it('counts the id of a user record without a usable id as drift', function (array $fields, string $kind, string $detail) {
    ingestUser($fields);

    expect(readStore('SELECT kind, type, v, detail, count FROM drift'))->toBe([['kind' => $kind, 'type' => 'user', 'v' => '1', 'detail' => $detail, 'count' => 1]]);
})->with([
    'empty' => ['fields' => ['id' => ''], 'kind' => 'missing_field', 'detail' => 'id'],
    'missing' => ['fields' => ['id' => null], 'kind' => 'missing_field', 'detail' => 'id'],
    'not a string' => ['fields' => ['id' => 7], 'kind' => 'structure', 'detail' => 'id: expected string, got integer'],
]);

it('keeps a user whose id is zero in the user directory', function () {
    ingestUser(['id' => '0']);

    expect(readStore('SELECT id FROM users'))->toBe([['id' => '0']]);
});

it('never counts a user as a record', function () {
    ingestUser([]);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_empty', ['path' => app(Configuration::class)->database]));
});
