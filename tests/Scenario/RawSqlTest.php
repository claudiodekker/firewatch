<?php

use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

it('learns the recipe from describe, then finds the route it read in source by its group id and reads its occurrences', function () {
    rawSqlRequests();

    $described = Envelope::assert(Describe::class, ['type' => 'request']);
    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => ['GET', 'HEAD'], 'path' => 'checkout']);
    $held = $envelope['result']['held'];
    $occurrences = Envelope::assert(Occurrences::class, $envelope['next'][0]['arguments']);

    expect($described['result']['group_recipe'])->not->toBeNull()
        ->and($envelope['result']['candidates'])->toHaveCount(1)
        ->and($envelope['result']['candidates'][0]['input'])->toBe('GET|HEAD,,/checkout')
        ->and($held)->toHaveCount(1)
        ->and($held[0])->toMatchArray(['group' => $envelope['result']['candidates'][0]['group'], 'type' => 'request', 'records' => 2, 'label' => '/checkout'])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'request', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['empty'])->toBeNull()
        ->and($occurrences['result']['rows'])->toHaveCount(2)
        ->and(array_unique(array_column($occurrences['result']['rows'], 'group')))->toBe([$held[0]['group']]);
});

it('answers a route that is registered but never ran with a miss the recipe check lets the assistant trust', function () {
    rawSqlRequests();

    Route::post('/refund', fn () => 'refunded');

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => ['POST'], 'path' => '/refund']);

    expect($envelope['result']['held'])->toBe([])
        ->and($envelope['result']['recipe_check'])->toBe([['type' => 'request', 'check' => 'agrees', 'detail' => null]])
        ->and($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['summary'])->toBe(__('firewatch::messages.fingerprint_summary_missed.agrees', ['group' => $envelope['result']['candidates'][0]['group'], 'types' => 'request']))
        ->and($envelope['next'])->toBe([]);
});

it('reads a list query both ways when the driver is unknown, and names the reading the store holds', function () {
    forceRequests();

    Route::get('/orders', fn () => DB::select('select 1 where 1 in (?, ?)', [1, 2]));

    test()->get('/orders');

    $connection = storeRows('SELECT connection FROM queries')[0]['connection'];
    $sql = 'select 1 where 1 in (?, ?)';
    $arguments = ['type' => 'query', 'connection' => $connection, 'sql' => $sql];

    $unknown = Envelope::assert(Fingerprint::class, $arguments);
    $known = Envelope::assert(Fingerprint::class, [...$arguments, 'driver' => 'sqlite']);

    expect($unknown['result']['candidates'])->toHaveCount(2)
        ->and(array_column($unknown['result']['held'], 'group'))->toBe([$unknown['result']['candidates'][0]['group']])
        ->and($unknown['result']['recipe_check'])->toBe([['type' => 'query', 'check' => 'agrees', 'detail' => __('firewatch::messages.fingerprint_assumption_normalised')]])
        ->and($known['result']['candidates'])->toHaveCount(1)
        ->and($known['result']['candidates'][0]['group'])->toBe($unknown['result']['candidates'][0]['group'])
        ->and($known['result']['held'])->toBe($unknown['result']['held']);
});

it('states the blind spots of the type it fingerprints', function () {
    rawSqlRequests();

    $envelope = Envelope::assert(Fingerprint::class, ['type' => 'request', 'methods' => ['GET'], 'path' => '/checkout']);
    $structural = array_values(array_filter($envelope['blind_spots'], fn (array $blindSpot) => $blindSpot['kind'] === 'structural'));

    expect($structural)->toBe(BlindSpots::for([RecordType::REQUEST]));
});
