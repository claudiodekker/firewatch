<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\TestRun;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use SQLite3;

/**
 * An application's feature tests, run while another writer holds the store from the end of the first test until the second one runs.
 */
class DroppedBatchTest extends TestCase
{
    /**
     * The connection of the other writer, which holds the store's write lock.
     */
    protected static ?SQLite3 $lockHolder = null;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [FirewatchServiceProvider::class];
    }

    public function test_a_query_the_store_is_too_busy_to_keep(): void
    {
        Route::get('/orders', fn () => DB::select('select 1 as orders'));

        $this->get('/orders')->assertOk();

        DB::select('select 1 as dropped');

        static::$lockHolder = new SQLite3((string) getenv('FIREWATCH_DATABASE'));
        static::$lockHolder->exec('BEGIN IMMEDIATE');
    }

    public function test_a_query_after_the_store_is_free_again(): void
    {
        static::$lockHolder?->exec('ROLLBACK');
        static::$lockHolder?->close();

        DB::select('select 2 as kept');

        $this->assertTrue(true);
    }
}
