<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\TestRun;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * An application's feature tests that sign a member in, which a process test runs once per worker of a parallel run: each worker has a database of its own, so id 7 is another member in each.
 */
class MembersTest extends TestCase
{
    /**
     * The member each worker's database holds under id 7.
     */
    protected const MEMBER_SEVEN_BY_WORKER = [
        '1' => ['name' => 'Taylor Otwell', 'email' => 'taylor@example.com'],
        '2' => ['name' => 'Taylor Swift', 'email' => 'swift@example.com'],
    ];

    /**
     * The worker that runs the suite.
     */
    protected string $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->worker = $_SERVER['TEST_TOKEN'] ?? '';

        $this->assertArrayHasKey($this->worker, static::MEMBER_SEVEN_BY_WORKER, 'This suite runs as worker 1 or 2 of a parallel run.');
    }

    protected function getPackageProviders($app): array
    {
        return [FirewatchServiceProvider::class];
    }

    public function test_shows_a_member(): void
    {
        Route::get('/members/{member}', function (string $member) {
            Auth::setUser($this->member($member));

            return DB::select('select ? as member', [$member]);
        });

        $this->get('/members/7')->assertOk();
    }

    public function test_fails_to_invoice_a_member(): void
    {
        Route::get('/members/{member}/invoice', function (string $member) {
            Auth::setUser($this->member($member));

            throw new RuntimeException('The invoice could not be rendered.');
        });

        $this->get('/members/7/invoice')->assertServerError();
    }

    protected function member(string $id): GenericUser
    {
        return new GenericUser(['id' => $id, ...static::MEMBER_SEVEN_BY_WORKER[$this->worker]]);
    }
}
