<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\TestRun;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * An application's feature tests, which a process test runs through PHPUnit's own teardown of each test's application.
 */
class TrailingRecordsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FirewatchServiceProvider::class];
    }

    public function test_a_query_after_a_request(): void
    {
        Route::get('/orders', fn () => DB::select('select 1 as orders'));

        $this->get('/orders')->assertOk();

        DB::select('select 1 as after_request');
    }

    public function test_a_query_after_a_command(): void
    {
        Artisan::command('orders:prune', function () {
            DB::select('select 2 as pruned');
        });

        $this->artisan('orders:prune')->assertSuccessful();

        DB::select('select 2 as after_command');
    }

    public function test_only_queries(): void
    {
        DB::select('select 3 as alone');
        DB::select('select 4 as alone');

        $this->assertTrue(true);
    }
}
