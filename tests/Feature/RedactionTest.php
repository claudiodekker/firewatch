<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\Request as RequestRecord;

/**
 * @param  array<string, string>  $variables
 */
function serveFailingCheckout(array $variables = []): void
{
    foreach ($variables as $name => $value) {
        setEnvironmentVariable(name: $name, value: $value);
    }

    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    // Nightwatch keeps a payload only for a response with status 500.
    Route::post('/checkout', fn () => response('failed', 500));
}

/**
 * @return array<string, mixed>
 */
function storedRequest(): array
{
    [$request] = storeRows('SELECT headers, payload FROM requests');

    return [
        'headers' => json_decode($request['headers'], associative: true, flags: JSON_THROW_ON_ERROR),
        'payload' => json_decode($request['payload'], associative: true, flags: JSON_THROW_ON_ERROR),
    ];
}

it('stores payload fields and headers unredacted by default, whatever Nightwatch\'s own variables say', function () {
    serveFailingCheckout([
        'NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD' => 'false',
        'NIGHTWATCH_REDACT_PAYLOAD_FIELDS' => 'password',
        'NIGHTWATCH_REDACT_HEADERS' => 'Authorization,Cookie,X-XSRF-TOKEN',
    ]);

    test()->withHeaders(['Authorization' => 'Bearer abc123', 'Cookie' => 'session=abc', 'X-XSRF-TOKEN' => 'xsrf'])
        ->post('/checkout', ['_token' => 'csrf', 'password' => 'secret', 'password_confirmation' => 'secret']);

    $request = storedRequest();

    expect($request['payload'])->toMatchArray(['_token' => 'csrf', 'password' => 'secret', 'password_confirmation' => 'secret'])
        ->and($request['headers'])->toMatchArray(['authorization' => ['Bearer abc123'], 'cookie' => ['session=abc'], 'x-xsrf-token' => ['xsrf']]);
});

it('redacts the listed payload fields by exact key at any depth, and only string values', function () {
    serveFailingCheckout(['FIREWATCH_REDACT_PAYLOAD_FIELDS' => 'password,cvc,pin']);

    test()->postJson('/checkout', [
        'password' => 'secret',
        'password_confirmation' => 'secret',
        'card' => ['number' => '4242', 'cvc' => '123'],
        'pin' => 1234,
    ]);

    expect(storedRequest()['payload'])->toMatchArray([
        'password' => '[6 bytes redacted]',
        'password_confirmation' => 'secret',
        'card' => ['number' => '4242', 'cvc' => '[3 bytes redacted]'],
        'pin' => 1234,
    ]);
});

it('redacts the listed headers by case-insensitive name, keeping Authorization\'s scheme and Cookie\'s names', function (string $header, string $value, string $stored) {
    serveFailingCheckout(['FIREWATCH_REDACT_HEADERS' => 'AUTHORIZATION,cookie,X-Api-Key']);

    test()->withHeaders([$header => $value, 'X-Trace' => 'trace-1'])->post('/checkout');

    expect(storedRequest()['headers'])->toMatchArray([strtolower($header) => [$stored], 'x-trace' => ['trace-1']]);
})->with([
    'Authorization with a scheme' => ['header' => 'Authorization', 'value' => 'Bearer abc123', 'stored' => 'Bearer [6 bytes redacted]'],
    'Authorization without a scheme' => ['header' => 'Authorization', 'value' => 'abc123', 'stored' => '[6 bytes redacted]'],
    'Cookie' => ['header' => 'Cookie', 'value' => 'session=abc; theme=dark', 'stored' => 'session=[3 bytes redacted]; theme=[4 bytes redacted]'],
    'an unregistered Authorization scheme' => ['header' => 'Authorization', 'value' => 'part1 part2', 'stored' => '[11 bytes redacted]'],
    'a Cookie without names' => ['header' => 'Cookie', 'value' => 'abc', 'stored' => '[3 bytes redacted]'],
    'a Cookie with one nameless part' => ['header' => 'Cookie', 'value' => 'session=abc; theme', 'stored' => '[18 bytes redacted]'],
    'another header' => ['header' => 'x-api-key', 'value' => 'key-123', 'stored' => '[7 bytes redacted]'],
]);

it('shapes headers before the application\'s own redactRequests callbacks, which still apply', function () {
    serveFailingCheckout(['FIREWATCH_REDACT_HEADERS' => 'Authorization']);
    $seen = null;
    Nightwatch::redactRequests(function (RequestRecord $record) use (&$seen) {
        $seen = $record->headers->get('authorization');
        $record->headers->set('x-api-key', 'removed by the application');
    });

    test()->withHeaders(['Authorization' => 'Bearer abc123', 'X-Api-Key' => 'key-123'])->post('/checkout');

    expect($seen)->toBe('Bearer [6 bytes redacted]')
        ->and(storedRequest()['headers'])->toMatchArray(['authorization' => ['Bearer [6 bytes redacted]'], 'x-api-key' => ['removed by the application']]);
});

it('never stores the php-auth headers', function () {
    serveFailingCheckout();

    test()->withServerVariables(['PHP_AUTH_USER' => 'taylor', 'PHP_AUTH_PW' => 'secret', 'PHP_AUTH_DIGEST' => 'digest'])->post('/checkout');

    $headers = storedRequest()['headers'];

    expect($headers)->not->toHaveKeys(['php-auth-user', 'php-auth-pw', 'php-auth-digest'])
        ->and($headers)->toMatchArray(['authorization' => ['Basic '.base64_encode('taylor:secret')]]);
});

it('stores no payload when capturing request payloads is turned off', function () {
    serveFailingCheckout(['FIREWATCH_CAPTURE_REQUEST_PAYLOAD' => 'false']);

    test()->post('/checkout', ['password' => 'secret']);

    expect(storedRequest()['payload'])->toBe(['_nightwatch_error' => 'NOT_ENABLED']);
});

it('strips the userinfo from an outgoing request\'s URL', function () {
    Http::fake(['https://taylor:secret@example.com/ping' => Http::response('pong')]);

    Http::get('https://taylor:secret@example.com/ping');
    Nightwatch::digest();

    expect(storeRows('SELECT url FROM outgoing_requests'))->toBe([['url' => 'https://example.com/ping']]);
});
