<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Auth\GenericUser;

it('keeps the signed-in user of a request in the user directory, not as a record', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']));

    $this->get('/');

    [$user] = storeRows('SELECT * FROM users');

    expect($user)->toMatchArray(['id' => '7', 'name' => 'Taylor', 'username' => 'taylor@example.com', 'last_seen' => $user['first_seen']])
        ->and($user['first_seen'])->toBeFloat()
        ->and(storeRows("SELECT id FROM records WHERE type = 'user'"))->toBe([]);
});

it('counts no drift for the signed-in user of a request', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']));

    $this->get('/');

    expect(storeRows("SELECT kind, detail FROM drift WHERE kind <> 'version'"))->toBe([]);
});

it('keeps when a user was first seen and takes the rest from the latest sighting', function () {
    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1767225600.25])]);

    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1767225900.5, 'name' => 'Taylor Otwell', 'username' => 'taylor@laravel.com'])]);

    expect(storeRows('SELECT * FROM users'))->toBe([
        ['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@laravel.com', 'first_seen' => 1767225600.25, 'last_seen' => 1767225900.5],
    ]);
});

it('never moves a user\'s last sighting back for a batch stored out of order', function () {
    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1767225900.5])]);

    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1767225600.25])]);

    expect(storeRows('SELECT last_seen FROM users'))->toBe([['last_seen' => 1767225900.5]]);
});

it('never keeps a timestamp that is not a number as a user\'s sighting', function () {
    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 'soon'])]);

    ingest([syntheticRecord(RecordType::USER)->with(['timestamp' => 1767225900.25])]);

    expect(storeRows('SELECT first_seen, last_seen FROM users'))->toBe([['first_seen' => null, 'last_seen' => 1767225900.25]]);
});

it('keeps a user record without a usable id as a record', function (RecordBuilder $user) {
    ingest([$user]);

    expect(storeRows('SELECT type, started_at FROM records'))->toBe([['type' => 'user', 'started_at' => 1767225600.25]])
        ->and(storeRows('SELECT id FROM users'))->toBe([]);
})->with([
    'empty' => [syntheticRecord(RecordType::USER)->with(['id' => ''])],
    'missing' => [syntheticRecord(RecordType::USER)->without('id')],
    'not a string' => [syntheticRecord(RecordType::USER)->with(['id' => 7])],
]);

it('counts the id of a user record without a usable id as drift', function (RecordBuilder $user, string $kind, string $detail) {
    ingest([$user]);

    expect(storeRows('SELECT kind, type, v, detail, count FROM drift'))->toBe([['kind' => $kind, 'type' => 'user', 'v' => '1', 'detail' => $detail, 'count' => 1]]);
})->with([
    'empty' => ['user' => syntheticRecord(RecordType::USER)->with(['id' => '']), 'kind' => 'missing_field', 'detail' => 'id'],
    'missing' => ['user' => syntheticRecord(RecordType::USER)->without('id'), 'kind' => 'missing_field', 'detail' => 'id'],
    'not a string' => ['user' => syntheticRecord(RecordType::USER)->with(['id' => 7]), 'kind' => 'structure', 'detail' => 'id: expected string, got integer'],
]);

it('keeps a user whose id is zero in the user directory', function () {
    ingest([syntheticRecord(RecordType::USER)->with(['id' => '0'])]);

    expect(storeRows('SELECT id FROM users'))->toBe([['id' => '0']]);
});

it('never counts a user as a record', function () {
    ingest([syntheticRecord(RecordType::USER)]);

    $response = FirewatchServer::tool(Overview::class);

    $response->assertSee(__('firewatch::messages.store_empty', ['path' => app(Configuration::class)->database]));
});
