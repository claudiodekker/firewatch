<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;

function captureTests(): void
{
    setEnvironmentVariable(name: 'FIREWATCH_CAPTURE_TESTS', value: 'true');

    test()->refreshApplication();
}

/**
 * Get each request's route and each command's name with the SQL of the queries it ran.
 *
 * @return array<string, list<string>>
 */
function executionQueries(): array
{
    Nightwatch::digest();

    $executions = storeRows('SELECT route_path AS name, execution_id, started_at FROM requests UNION ALL SELECT name, execution_id, started_at FROM commands ORDER BY started_at');

    return collect($executions)->mapWithKeys(fn (array $execution) => [
        $execution['name'] => array_column(storeRows("SELECT sql FROM queries WHERE execution_id = '{$execution['execution_id']}' ORDER BY started_at"), 'sql'),
    ])->all();
}

it('records each request and command a test runs as an execution of its own', function () {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));
    Route::get('/invoices', fn () => [DB::select('select 2 as invoices'), DB::select('select 3 as invoices')]);
    Artisan::command('orders:prune', function () {
        DB::select('select 4 as pruned');
    });

    DB::select('select 0 as before');
    $this->get('/orders')->assertOk();
    $this->get('/invoices')->assertOk();
    $this->artisan('orders:prune')->assertSuccessful();
    DB::select('select 5 as after');

    expect(executionQueries())->toBe([
        '/orders' => ['select 1 as orders'],
        '/invoices' => ['select 2 as invoices', 'select 3 as invoices'],
        'orders:prune' => ['select 4 as pruned'],
    ])->and(storeRows('SELECT route_path, queries FROM requests ORDER BY started_at'))->toBe([
        ['route_path' => '/orders', 'queries' => 1],
        ['route_path' => '/invoices', 'queries' => 2],
    ])->and(storeRows("SELECT count(DISTINCT execution_id) AS executions FROM queries WHERE sql IN ('select 0 as before', 'select 5 as after')"))->toBe([
        ['executions' => 2],
    ])->and(storeRows("SELECT count(*) AS inside FROM queries WHERE sql IN ('select 0 as before', 'select 5 as after') AND execution_id IN (SELECT execution_id FROM requests UNION SELECT execution_id FROM commands)"))->toBe([
        ['inside' => 0],
    ]);
});

it('keeps a command a request starts inside the request', function () {
    captureTests();
    Artisan::command('orders:prune', function () {
        DB::select('select 4 as pruned');
    });
    Route::get('/orders', fn () => Artisan::call('orders:prune'));

    $this->get('/orders')->assertOk();

    expect(executionQueries())->toBe([
        '/orders' => ['select 4 as pruned'],
    ]);
});

it('keeps a command another command calls inside the outer command', function () {
    captureTests();
    Artisan::command('orders:archive', function () {
        DB::select('select 4 as archived');
    });
    Artisan::command('orders:prune', function () {
        DB::select('select 5 as pruned');
        Artisan::call('orders:archive');
    });

    $this->artisan('orders:prune')->assertSuccessful();

    expect(executionQueries())->toBe([
        'orders:prune' => ['select 5 as pruned', 'select 4 as archived'],
    ]);
});

it('records no request or command when test capture is off', function () {
    Route::get('/orders', fn () => DB::select('select 1 as orders'));
    Artisan::command('orders:prune', function () {
        DB::select('select 4 as pruned');
    });

    $this->get('/orders')->assertOk();
    $this->artisan('orders:prune')->assertSuccessful();

    expect(executionQueries())->toBe([])
        ->and(storeRows("SELECT count(DISTINCT execution_id) AS executions FROM queries WHERE sql IN ('select 1 as orders', 'select 4 as pruned')"))->toBe([
            ['executions' => 1],
        ]);
});

it('records none of the commands Nightwatch leaves to its workers and schedulers', function () {
    captureTests();
    Artisan::command('inspire', function () {
        DB::select('select 6 as quote');
    });

    $this->artisan('inspire')->assertSuccessful();

    expect(executionQueries())->toBe([]);
});

it('leaves the application without the switch that forces Nightwatch into request mode', function () {
    captureTests();

    expect(env('NIGHTWATCH_FORCE_REQUEST'))->toBeNull();
});
