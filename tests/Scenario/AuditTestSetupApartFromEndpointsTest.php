<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * Turn test capture on and register the route that adds an item to an order, failing validation without a quantity, and the command that builds a report.
 */
function auditTestRoutes(): void
{
    setEnvironmentVariable(name: 'FIREWATCH_CAPTURE_TESTS', value: 'true');

    test()->refreshApplication();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::middleware('web')->post('/orders/{order}/items', function (Request $request) {
        DB::select('select ? as member', [1]);
        DB::select('select ? as member', [2]);

        $request->validate(['quantity' => 'required']);

        DB::select('select ? as member', [3]);
        DB::select('select ? as member', [4]);

        foreach (range(1, 3) as $check) {
            Cache::get('team:1:permissions');
        }

        return redirect('/orders');
    });
    Artisan::command('reports:build', function () {
        DB::select('select ? as report', [1]);
    });
}

/**
 * Run a feature test that looks up countries before and after its requests, adds two items to an order, the second failing validation, with products set up between them, and builds a report.
 */
function auditTestSetupAndEndpoints(): void
{
    auditTestRoutes();

    foreach (['NL', 'BE', 'DE'] as $country) {
        DB::select('select ? as country', [$country]);
    }

    Cache::get('countries');
    Cache::get('countries');

    test()->post('/orders/1/items', ['quantity' => 2])->assertRedirect('/orders');

    foreach (range(1, 20) as $product) {
        DB::select('select ? as product', [$product]);
    }

    test()->post('/orders/1/items')->assertStatus(302);
    test()->artisan('reports:build')->assertSuccessful();

    DB::select('select ? as country', ['FR']);
    DB::select('select ? as country', ['IT']);

    Nightwatch::digest();
}

/**
 * Run the same requests and command with no work of the test's own around them.
 */
function auditEndpointsOnly(): void
{
    auditTestRoutes();

    test()->post('/orders/1/items', ['quantity' => 2])->assertRedirect('/orders');
    test()->post('/orders/1/items')->assertStatus(302);
    test()->artisan('reports:build')->assertSuccessful();

    Nightwatch::digest();
}

/**
 * Count the rows of an occurrences answer by the source and name they carry.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, int>
 */
function auditRowsBySourceAndName(array $answer): array
{
    $counts = array_count_values(array_map(fn (array $row) => "{$row['source']} {$row['name']}", $answer['result']['rows']));

    ksort($counts);

    return $counts;
}

it('lists a test\'s setup queries apart from the queries its requests and command ran', function () {
    auditTestSetupAndEndpoints();

    $between = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'between', 'limit' => 100]);
    $executions = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'executions', 'limit' => 100]);
    $all = Answers::assert(Occurrences::class, ['type' => 'query', 'limit' => 100]);

    expect(auditRowsBySourceAndName($between))->toBe([
        'between select ? as country' => 5,
        'between select ? as product' => 20,
    ])->and(auditRowsBySourceAndName($executions))->toBe([
        'command select ? as report' => 1,
        'request select ? as member' => 6,
    ])->and(auditRowsBySourceAndName($all))->toBe([
        'between select ? as country' => 5,
        'between select ? as product' => 20,
        'command select ? as report' => 1,
        'request select ? as member' => 6,
    ])->and($between['window'])->toMatchArray(['since' => null, 'until' => null, 'scope' => 'between'])
        ->and($executions['window'])->toMatchArray(['scope' => 'executions'])
        ->and($all['window'])->not->toHaveKey('scope')
        ->and(array_column($between['result']['rows'], 'user_id'))->each->toBeNull()
        ->and(array_column($between['result']['rows'], 'stage'))->each->toBeNull();
});

it('lists the same records under either scope that keeps a store\'s executions when the test ran nothing of its own', function () {
    auditEndpointsOnly();

    $executions = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'executions']);
    $all = Answers::assert(Occurrences::class, ['type' => 'query']);
    $between = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'between']);

    expect($executions['result'])->toBe($all['result'])
        ->and(auditRowsBySourceAndName($all))->toBe([
            'command select ? as report' => 1,
            'request select ? as member' => 6,
        ])->and($between['result'])->toBe([])
        ->and($between['empty'])->toBe([
            'kind' => 'no_match',
            'population' => 13,
            'message' => __('firewatch::messages.no_match', ['population' => 13, 'filters' => 'type: query, scope: between']),
        ]);
});

it('refuses to list requests between a test\'s executions, where none can be', function () {
    auditTestSetupAndEndpoints();

    $response = FirewatchServer::tool(Occurrences::class, ['type' => 'request', 'scope' => 'between']);

    $response->assertHasErrors([__('firewatch::messages.conflicting_arguments', [
        'argument' => 'scope',
        'with' => 'request',
        'accepted' => __('firewatch::messages.argument_values.scope_holding'),
        'example' => 'occurrences(type: "query", scope: "between")',
    ])]);
});
