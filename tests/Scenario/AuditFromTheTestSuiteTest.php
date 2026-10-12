<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\RestockProducts;
use Workbench\App\Jobs\ShipOrder;

function captureTestSuite(): void
{
    setEnvironmentVariable(name: 'FIREWATCH_CAPTURE_TESTS', value: 'true');

    test()->refreshApplication();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
}

it('finds the N+1 a feature test drove on a route, among the other requests of the test', function () {
    captureTestSuite();
    Route::get('/orders', fn () => [DB::select('select ? as orders', [1]), DB::select('select ? as orders', [2])]);

    $this->get('/orders')->assertOk();
    $this->get('/products')->assertOk();

    $answer = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);
    $finding = $answer['result']['findings'][0];

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
        ->and($finding['name'])->toBe('/products: select ? as product')
        ->and($finding['evidence'])->toMatchArray(['worst_runs' => 25, 'executions_affected' => 1, 'total_runs' => 25, 'distinct_bindings' => 25, 'unit' => ['source' => 'request', 'label' => '/products']]);
});

it('finds nothing in the requests of a feature test that ran each query twice', function () {
    captureTestSuite();
    Route::get('/orders', fn () => [DB::select('select ? as orders', [1]), DB::select('select ? as orders', [2])]);

    $this->get('/orders')->assertOk();
    $this->get('/orders')->assertOk();

    expect(Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0]);
});

it('leaves out an N+1 the test ran itself, outside the requests it sent', function () {
    captureTestSuite();
    Route::get('/orders', fn () => [DB::select('select ? as orders', [1]), DB::select('select ? as orders', [2])]);

    foreach (range(1, 25) as $product) {
        DB::select('select ? as product', [$product]);
    }

    $this->get('/orders')->assertOk();

    expect(Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0])
        ->and(storeRows("SELECT count(*) AS runs FROM queries WHERE sql = 'select ? as product'"))->toBe([['runs' => 25]]);
});

it('finds the N+1 and the failure in the jobs a test dispatched itself on the sync queue', function () {
    captureTestSuite();

    RestockProducts::dispatch();
    ShipOrder::dispatch();
    expect(fn () => ChargeCard::dispatch())->toThrow(RuntimeException::class);

    $loop = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);
    $failure = Answers::assert(Detect::class, ['shape' => 'failing-jobs']);
    $ranking = Answers::assert(Rank::class, ['type' => 'job-attempt']);

    expect($loop['result'])->toMatchArray(['verdict' => 'findings', 'total' => 1])
        ->and($loop['result']['findings'][0]['name'])->toBe(RestockProducts::class.': select ? as stock')
        ->and($loop['result']['findings'][0]['evidence'])->toMatchArray(['worst_runs' => 25, 'executions_affected' => 1, 'unit' => ['source' => 'job', 'label' => RestockProducts::class]])
        ->and(array_column($ranking['result']['groups'], 'label'))->toEqualCanonicalizing([RestockProducts::class, ShipOrder::class, ChargeCard::class])
        ->and($failure['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 1])
        ->and($failure['result']['findings'][0]['name'])->toBe(ChargeCard::class)
        ->and($failure['result']['findings'][0]['evidence'])->toMatchArray(['failed_attempts' => 1, 'jobs_failed' => 1, 'jobs' => 1])
        ->and($failure['result']['findings'][0]['evidence']['last_exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'The card was declined.']);
});

it('finds nothing in a job a test dispatched itself that ran no query twice and did not fail', function () {
    captureTestSuite();

    ShipOrder::dispatch();

    expect(Answers::assert(Detect::class, ['shape' => 'failing-jobs'])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
});

it('has nothing to judge in a job a test called directly, and says a sync job inside an execution has no attempt', function () {
    captureTestSuite();
    Route::get('/restock', fn () => RestockProducts::dispatch() ? 'ok' : 'ok');

    (new RestockProducts)->handle();
    $this->get('/restock')->assertOk();

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-jobs']);
    $blindSpots = array_column($answer['blind_spots'], 'message', 'id');

    expect($answer['result'])->toMatchArray(['verdict' => 'not_evaluated', 'examined' => 0])
        ->and($blindSpots['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
        ->and(storeRows('SELECT execution_source, count(*) AS runs FROM queries GROUP BY execution_source ORDER BY min(started_at)'))->toBe([
            ['execution_source' => 'between', 'runs' => 25],
            ['execution_source' => 'request', 'runs' => 25],
        ]);
});
