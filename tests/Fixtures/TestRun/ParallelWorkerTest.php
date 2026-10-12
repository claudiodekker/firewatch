<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\TestRun;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An application's feature tests as one worker of a parallel run executes them, with Sleep faked as a suite's setup often does.
 */
class ParallelWorkerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FirewatchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    /**
     * @return list<array{int}>
     */
    public static function orders(): array
    {
        return array_map(fn (int $order) => [$order], range(1, 40));
    }

    #[DataProvider('orders')]
    public function test_an_order(int $order): void
    {
        Route::get('/orders/{order}', fn () => DB::select('select ? as orders', [$order]));

        $this->get("/orders/{$order}")->assertOk();

        DB::select('select ? as after', [$order]);
    }
}
