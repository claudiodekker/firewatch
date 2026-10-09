<?php

use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/**
 * Serve the checkout twice in a fresh application, once for a tenant that names itself in a header.
 */
function rawSqlRequests(): void
{
    forceRequests();

    Route::get('/checkout', fn () => 'paid');

    test()->get('/checkout', ['X-Tenant' => 'acme']);
    test()->get('/checkout');
}

/**
 * Get the statement that finds the requests that sent a header, by its name in the stored headers.
 */
function rawSqlHeader(string $name): string
{
    return "SELECT j.key AS header, count(*) AS requests FROM requests, json_each(requests.headers) AS j WHERE j.key = '{$name}' GROUP BY j.key";
}

it('finds a request header the other tools do not list, with SQL over the stored headers', function () {
    rawSqlRequests();

    $overview = Envelope::assert(Overview::class);
    $occurrences = Envelope::assert(Occurrences::class, ['type' => 'request']);
    $envelope = Envelope::assert(Query::class, ['sql' => rawSqlHeader('x-tenant')]);

    expect($overview['result']['records'])->toBeGreaterThan(0)
        ->and($occurrences['result']['rows'])->toHaveCount(2)
        ->and($envelope['result']['columns'])->toBe(['header', 'requests'])
        ->and($envelope['result']['rows'])->toBe([['x-tenant', 1]])
        ->and($envelope['result']['stop'])->toBe('complete');
})->group('process');

it('answers a header no request sent with no rows, never as clean', function () {
    rawSqlRequests();

    $envelope = Envelope::assert(Query::class, ['sql' => rawSqlHeader('x-nobody')]);

    expect($envelope['result']['rows'])->toBe([])
        ->and($envelope['result']['columns'])->toBe(['header', 'requests'])
        ->and($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['empty']['message'])->toBe(__('firewatch::messages.query_no_rows'));
})->group('process');

it('states the blind spots of the requests the statement read', function () {
    rawSqlRequests();

    $envelope = Envelope::assert(Query::class, ['sql' => rawSqlHeader('x-tenant')]);
    $structural = array_values(array_filter($envelope['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'structural'));

    expect($envelope['coverage']['types_read'])->toBe(['request'])
        ->and($structural)->toBe(BlindSpots::for([RecordType::REQUEST]));
})->group('process');

it('learns the request columns from describe, then finds the header with the statement it offers', function () {
    rawSqlRequests();

    $store = Envelope::assert(Describe::class);
    $requests = Envelope::assert(Describe::class, ['type' => 'request']);
    $columns = array_column($requests['result']['columns'], null, 'column');
    $headerNames = Arr::first($store['result']['examples'], fn (array $example) => str_contains($example['sql'], 'json_each'));
    $envelope = Envelope::assert(Query::class, ['sql' => $headerNames['sql']]);

    expect(array_column($store['result']['types'], 'records', 'type')['request'])->toBe(2)
        ->and($columns['headers'])->toMatchArray(['sql_type' => 'TEXT', 'wire' => 'headers', 'examples' => []])
        ->and($columns['route_path']['examples'])->toBe(['/checkout'])
        ->and($columns['status_code']['examples'])->toBe([200])
        ->and($envelope['result']['rows'])->toContain(['x-tenant', 1]);
})->group('process');

it('describes the request view before anything was recorded, from the shipped catalogue', function () {
    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);

    expect($envelope['coverage']['state'])->toBe('absent')
        ->and($envelope['empty']['kind'])->toBe('no_store')
        ->and(array_column($envelope['result']['columns'], 'column'))->toHaveCount(48)
        ->and($envelope['result']['columns'][0]['column'])->toBe('id')
        ->and(array_filter(array_column($envelope['result']['columns'], 'examples')))->toBe([]);
});

it('states the blind spots of the requests it describes', function () {
    rawSqlRequests();

    $envelope = Envelope::assert(Describe::class, ['type' => 'request']);
    $structural = array_values(array_filter($envelope['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'structural'));

    expect($structural)->toBe(BlindSpots::for([RecordType::REQUEST]));
});
