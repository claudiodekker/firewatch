<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\TestRun;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An application's feature tests, one with a data provider, which a process test runs as a test run of several tests.
 */
class OrdersTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FirewatchServiceProvider::class];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statuses(): array
    {
        return [
            'open' => ['open'],
            'paid' => ['paid'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_lists_orders(string $status): void
    {
        Route::get('/orders', function () use ($status) {
            DB::select('select ? as status', [$status]);

            return DB::select('select ? as customer', [1]);
        });

        DB::select('select ? as seeded', [$status]);

        $this->get('/orders')->assertOk();
    }

    public function test_prunes_orders(): void
    {
        Artisan::command('orders:prune', function () {
            DB::select('select ? as status', ['stale']);
        });

        $this->artisan('orders:prune')->assertSuccessful();

        DB::select('select ? as remaining', [0]);
    }
}
