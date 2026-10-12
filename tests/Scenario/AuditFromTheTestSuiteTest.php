<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
