<?php

use ClaudioDekker\Firewatch\Tests\Fixtures\Jobs\DeletesItselfAndThrows;
use ClaudioDekker\Firewatch\Tests\Fixtures\Jobs\FailsItself;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Process\Process;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\RestockProducts;
use Workbench\App\Members;

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

it('keeps the logs and exceptions of a request and a command inside their executions', function () {
    captureTests();
    Route::get('/orders', function () {
        Log::warning('The orders are slow.');

        throw new RuntimeException('The orders could not be listed.');
    });
    Artisan::command('orders:prune', function () {
        Log::warning('The prune is slow.');

        report(new RuntimeException('The orders could not be pruned.'));
    });

    $this->get('/orders')->assertServerError();
    $this->artisan('orders:prune')->assertSuccessful();
    Nightwatch::digest();

    $executions = 'SELECT route_path AS execution, execution_id FROM requests UNION ALL SELECT name, execution_id FROM commands';

    expect(storeRows("SELECT x.execution, l.message FROM ({$executions}) x JOIN logs l USING (execution_id) ORDER BY l.started_at"))->toBe([
        ['execution' => '/orders', 'message' => 'The orders are slow.'],
        ['execution' => '/orders', 'message' => 'The orders could not be listed.'],
        ['execution' => 'orders:prune', 'message' => 'The prune is slow.'],
        ['execution' => 'orders:prune', 'message' => 'The orders could not be pruned.'],
    ])->and(storeRows("SELECT x.execution, e.message FROM ({$executions}) x JOIN exceptions e USING (execution_id) ORDER BY e.started_at"))->toBe([
        ['execution' => '/orders', 'message' => 'The orders could not be listed.'],
        ['execution' => 'orders:prune', 'message' => 'The orders could not be pruned.'],
    ])->and(storeRows('SELECT status_code FROM requests'))->toBe([['status_code' => 500]]);
});

it('leaves out the migrations and seeders a test harness runs, even inside a request', function () {
    captureTests();
    Route::get('/orders', function () {
        Artisan::call('migrate:fresh');

        return DB::select('select 1 as orders');
    });

    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->get('/orders')->assertOk();

    expect(executionQueries())->toBe([
        '/orders' => ['select 1 as orders'],
    ])->and(storeRows('SELECT queries FROM requests'))->toBe([['queries' => 1]])
        ->and(storeRows('SELECT count(*) AS queries FROM queries'))->toBe([['queries' => 1]]);
});

it('records no peak memory for a test\'s executions, which only the whole test process has', function () {
    captureTests();
    Route::get('/orders', fn () => 'ok');
    Artisan::command('orders:prune', fn () => null);

    $this->get('/orders')->assertOk();
    $this->artisan('orders:prune')->assertSuccessful();
    Nightwatch::digest();

    expect(storeRows('SELECT peak_memory_usage FROM requests UNION ALL SELECT peak_memory_usage FROM commands'))->toBe([
        ['peak_memory_usage' => null],
        ['peak_memory_usage' => null],
    ]);
});

it('marks what a test runs outside its requests as between, with no stage or user', function () {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));

    DB::select('select 0 as before');
    $this->actingAs(Members::find('7'))->get('/orders')->assertOk();
    DB::select('select 2 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT sql, execution_source, execution_stage, user_id FROM queries ORDER BY started_at'))->toBe([
        ['sql' => 'select 0 as before', 'execution_source' => 'between', 'execution_stage' => null, 'user_id' => null],
        ['sql' => 'select 1 as orders', 'execution_source' => 'request', 'execution_stage' => 'action', 'user_id' => '7'],
        ['sql' => 'select 2 as after', 'execution_source' => 'between', 'execution_stage' => null, 'user_id' => null],
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

it('writes a notice when it can\'t record a command, and keeps the failure out of the store', function () {
    captureTests();
    Route::get('/orders', fn () => 'orders');
    Artisan::command('orders:prune', function () {
        DB::select('select 1 as pruned');
    });
    Nightwatch::redactCommands(fn () => throw new RuntimeException('The command could not be redacted.'));

    $this->artisan('orders:prune')->assertSuccessful();
    $this->get('/orders')->assertOk();

    expect(notices())->toBe(['Firewatch could not follow a test\'s executions: The command could not be redacted.'])
        ->and(storeRows('SELECT route_path FROM requests'))->toBe([['route_path' => '/orders']])
        ->and(storeRows('SELECT message FROM exceptions'))->toBe([]);
});

it('starts the next request afresh after one threw out of the kernel', function () {
    captureTests();
    Route::get('/orders', function () {
        DB::select('select 1 as orders');

        throw new RuntimeException('The orders could not be listed.');
    });
    Route::get('/invoices', fn () => DB::select('select 2 as invoices'));

    expect(fn () => $this->withoutExceptionHandling()->get('/orders'))->toThrow(RuntimeException::class);
    $this->get('/invoices')->assertOk();

    expect(executionQueries())->toBe([
        '/invoices' => ['select 2 as invoices'],
    ]);
});

it('keeps what global middleware runs inside the request', function () {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));
    app(HttpKernel::class)->pushMiddleware(function (Request $request, Closure $next) {
        DB::select('select 0 as middleware');

        return $next($request);
    });

    $this->get('/orders')->assertOk();

    expect(executionQueries())->toBe([
        '/orders' => ['select 0 as middleware', 'select 1 as orders'],
    ])->and(storeRows('SELECT route_path, queries FROM requests'))->toBe([
        ['route_path' => '/orders', 'queries' => 2],
    ]);
});

it('keeps a request another request dispatches inside the outer request', function () {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 2 as orders'));
    Route::get('/dashboard', function () {
        DB::select('select 1 as before');
        $orders = app(HttpKernel::class)->handle(Request::create('/orders'));
        DB::select('select 3 as after');

        return $orders->getContent();
    });

    $this->get('/dashboard')->assertOk();
    DB::select('select 4 as assertion');

    expect(executionQueries())->toBe([
        '/dashboard' => ['select 1 as before', 'select 2 as orders', 'select 3 as after'],
    ])->and(storeRows('SELECT route_path, queries FROM requests'))->toBe([
        ['route_path' => '/dashboard', 'queries' => 3],
    ])->and(storeRows('SELECT sql, execution_source FROM queries ORDER BY started_at'))->toBe([
        ['sql' => 'select 1 as before', 'execution_source' => 'request'],
        ['sql' => 'select 2 as orders', 'execution_source' => 'request'],
        ['sql' => 'select 3 as after', 'execution_source' => 'request'],
        ['sql' => 'select 4 as assertion', 'execution_source' => 'between'],
    ]);
});

it('records a job the test runs on the sync queue as a job attempt of its own', function (Closure $dispatch) {
    captureTests();

    DB::select('select 0 as before');
    $dispatch();
    DB::select('select 1 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT name, connection, status, attempt, queries, peak_memory_usage FROM job_attempts'))->toBe([
        ['name' => RestockProducts::class, 'connection' => 'sync', 'status' => 'processed', 'attempt' => 1, 'queries' => 25, 'peak_memory_usage' => null],
    ])->and(storeRows('SELECT q.sql, q.execution_source, count(*) AS runs, count(DISTINCT q.execution_id) AS executions, max(q.execution_id = j.execution_id) AS in_attempt FROM queries q, job_attempts j GROUP BY q.sql ORDER BY min(q.started_at)'))->toBe([
        ['sql' => 'select 0 as before', 'execution_source' => 'between', 'runs' => 1, 'executions' => 1, 'in_attempt' => 0],
        ['sql' => 'select ? as stock', 'execution_source' => 'job', 'runs' => 25, 'executions' => 1, 'in_attempt' => 1],
        ['sql' => 'select 1 as after', 'execution_source' => 'between', 'runs' => 1, 'executions' => 1, 'in_attempt' => 0],
    ]);
})->with([
    'dispatch' => [fn () => RestockProducts::dispatch()],
    'dispatchSync' => [fn () => RestockProducts::dispatchSync()],
]);

it('records a job that fails on the sync queue as a failed attempt with its exception', function () {
    captureTests();

    expect(fn () => ChargeCard::dispatch())->toThrow(RuntimeException::class, 'The card was declined.');
    DB::select('select 1 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT name, status, exceptions FROM job_attempts'))->toBe([
        ['name' => ChargeCard::class, 'status' => 'failed', 'exceptions' => 1],
    ])->and(storeRows('SELECT e.message, e.execution_source, e.execution_id = j.execution_id AS in_attempt FROM exceptions e, job_attempts j'))->toBe([
        ['message' => 'The card was declined.', 'execution_source' => 'job', 'in_attempt' => 1],
    ])->and(storeRows('SELECT sql, execution_source FROM queries'))->toBe([
        ['sql' => 'select 1 as after', 'execution_source' => 'between'],
    ]);
});

it('keeps a job a request or a command runs on the sync queue inside that execution', function () {
    captureTests();
    Route::get('/restock', fn () => RestockProducts::dispatch() ? 'ok' : 'ok');
    Artisan::command('products:restock', function () {
        RestockProducts::dispatch();
    });

    $this->get('/restock')->assertOk();
    $this->artisan('products:restock')->assertSuccessful();
    Nightwatch::digest();

    expect(storeRows('SELECT count(*) AS attempts FROM job_attempts'))->toBe([['attempts' => 0]])
        ->and(storeRows('SELECT route_path AS name, queries FROM requests UNION ALL SELECT name, queries FROM commands'))->toBe([
            ['name' => '/restock', 'queries' => 25],
            ['name' => 'products:restock', 'queries' => 25],
        ])->and(storeRows('SELECT execution_source, count(*) AS queries FROM queries GROUP BY execution_source ORDER BY min(started_at)'))->toBe([
            ['execution_source' => 'request', 'queries' => 25],
            ['execution_source' => 'command', 'queries' => 25],
        ]);
});

it('keeps a job another job runs on the sync queue inside the outer attempt', function () {
    captureTests();

    dispatch(function () {
        DB::select('select 1 as outer_job');
        RestockProducts::dispatch();
        DB::select('select 2 as outer_job');
    });
    DB::select('select 3 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT queries FROM job_attempts'))->toBe([['queries' => 27]])
        ->and(storeRows('SELECT execution_source, count(*) AS queries FROM queries GROUP BY execution_source ORDER BY min(started_at)'))->toBe([
            ['execution_source' => 'job', 'queries' => 27],
            ['execution_source' => 'between', 'queries' => 1],
        ]);
});

it('leaves a job the test calls directly between its executions, which no queue event announces', function () {
    captureTests();

    (new RestockProducts)->handle();
    Nightwatch::digest();

    expect(storeRows('SELECT count(*) AS attempts FROM job_attempts'))->toBe([['attempts' => 0]])
        ->and(storeRows('SELECT execution_source, count(*) AS queries FROM queries GROUP BY execution_source'))->toBe([
            ['execution_source' => 'between', 'queries' => 25],
        ]);
});

it('records a failing job on a connection of the sync driver that has another name', function () {
    captureTests();
    config()->set('queue.connections.inline', ['driver' => 'sync']);
    config()->set('queue.default', 'inline');

    expect(fn () => ChargeCard::dispatch())->toThrow(RuntimeException::class);
    Nightwatch::digest();

    expect(storeRows('SELECT name, connection, status, exceptions FROM job_attempts'))->toBe([
        ['name' => ChargeCard::class, 'connection' => 'inline', 'status' => 'failed', 'exceptions' => 1],
    ]);
});

it('keeps what a job runs after it failed itself inside the attempt', function () {
    captureTests();

    FailsItself::dispatch();
    DB::select('select 2 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT name, status, queries, exceptions FROM job_attempts'))->toBe([
        ['name' => FailsItself::class, 'status' => 'failed', 'queries' => 1, 'exceptions' => 0],
    ])->and(storeRows('SELECT sql, execution_source FROM queries ORDER BY started_at'))->toBe([
        ['sql' => 'select 1 as after_failing', 'execution_source' => 'job'],
        ['sql' => 'select 2 as after', 'execution_source' => 'between'],
    ]);
});

it('ends the attempt of a job the sync queue raises no failed event for', function () {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));

    expect(fn () => DeletesItselfAndThrows::dispatch())->toThrow(RuntimeException::class);
    $this->get('/orders')->assertOk();

    expect(storeRows('SELECT name, status, exceptions FROM job_attempts'))->toBe([
        ['name' => DeletesItselfAndThrows::class, 'status' => 'failed', 'exceptions' => 1],
    ])->and(executionQueries())->toBe([
        '/orders' => ['select 1 as orders'],
    ]);
});

it('leaves a job between the executions when the test fakes an event that ends its attempt', function (Closure $fake, Closure $dispatch, int $jobQueries) {
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));
    DB::select('select 0 as before');

    $fake();
    $dispatch();
    DB::select('select 2 as after');
    $this->get('/orders')->assertOk();

    expect(storeRows('SELECT count(*) AS attempts FROM job_attempts'))->toBe([['attempts' => 0]])
        ->and(storeRows('SELECT execution_source, count(*) AS queries FROM queries GROUP BY execution_source ORDER BY min(started_at)'))->toBe([
            ['execution_source' => 'between', 'queries' => 1 + $jobQueries + 1],
            ['execution_source' => 'request', 'queries' => 1],
        ])->and(executionQueries())->toBe([
            '/orders' => ['select 1 as orders'],
        ])->and(notices())->toBe([]);
})->with([
    'the processed event' => [fn () => Event::fake([JobProcessed::class]), fn () => RestockProducts::dispatch(), 25],
    'the exception event' => [fn () => Event::fake([JobExceptionOccurred::class]), fn () => expect(fn () => ChargeCard::dispatch())->toThrow(RuntimeException::class), 0],
    'every event but the one that starts a job' => [fn () => Event::fakeExcept([JobProcessing::class]), fn () => RestockProducts::dispatch(), 25],
]);

it('records a job as an attempt when the test fakes other events', function () {
    captureTests();

    Event::fake([JobQueued::class]);
    RestockProducts::dispatch();
    DB::select('select 1 as after');
    Nightwatch::digest();

    expect(storeRows('SELECT name, status, queries FROM job_attempts'))->toBe([
        ['name' => RestockProducts::class, 'status' => 'processed', 'queries' => 25],
    ])->and(storeRows('SELECT sql, execution_source FROM queries WHERE sql = \'select 1 as after\''))->toBe([
        ['sql' => 'select 1 as after', 'execution_source' => 'between'],
    ]);
});

it('leaves a job a worker runs in a test as it was, with no attempt', function () {
    captureTests();
    test()->loadLaravelMigrations();
    config()->set('queue.default', 'database');
    Route::get('/orders', fn () => DB::select('select 1 as orders'));

    RestockProducts::dispatch();
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    $this->get('/orders')->assertOk();

    expect(storeRows('SELECT count(*) AS attempts FROM job_attempts'))->toBe([['attempts' => 0]])
        ->and(storeRows("SELECT execution_source, count(*) AS queries FROM queries WHERE sql = 'select ? as stock' GROUP BY execution_source"))->toBe([
            ['execution_source' => 'between', 'queries' => 25],
        ])->and(executionQueries())->toBe([
            '/orders' => ['select 1 as orders'],
        ]);
});

it('records no job attempt on the sync queue when test capture is off', function () {
    RestockProducts::dispatch();
    Nightwatch::digest();

    expect(storeRows('SELECT count(*) AS attempts FROM job_attempts'))->toBe([['attempts' => 0]])
        ->and(storeRows("SELECT count(DISTINCT execution_id) AS executions, count(*) AS queries FROM queries WHERE sql = 'select ? as stock'"))->toBe([
            ['executions' => 1, 'queries' => 25],
        ]);
});

it('records the command of a process that runs as testing, which Laravel raises no command events for', function () {
    // A manifest the in-process tests wrote lacks this package, which only the testbench CLI discovers.
    $packagesCache = 'bootstrap/cache/firewatch-packages-'.bin2hex(random_bytes(8)).'.php';

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'members:audit', '7', '--env=testing'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite', 'APP_PACKAGES_CACHE' => $packagesCache],
        timeout: 30,
    );

    $process->run();

    (new Filesystem)->delete(base_path($packagesCache));

    expect($process->getExitCode())->toBe(0)
        ->and(storeRows('SELECT name, queries FROM commands'))->toBe([['name' => 'members:audit', 'queries' => 1]]);
})->group('process');

it('stores what each test runs after its last execution, once PHPUnit has torn its application down', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Fixtures/TestRun/TrailingRecordsTest.php'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite'],
        timeout: 30,
    );

    $process->run();

    $executions = 'SELECT execution_id FROM requests UNION SELECT execution_id FROM commands';

    expect($process->getExitCode())->toBe(0)
        ->and(executionQueries())->toBe([
            '/orders' => ['select 1 as orders'],
            'orders:prune' => ['select 2 as pruned'],
        ])->and(storeRows("SELECT sql, execution_id IN ({$executions}) AS in_execution, execution_source, user_id FROM queries WHERE sql LIKE '%after%' OR sql LIKE '%alone%' ORDER BY started_at"))->toBe([
            ['sql' => 'select 1 as after_request', 'in_execution' => 0, 'execution_source' => 'between', 'user_id' => null],
            ['sql' => 'select 2 as after_command', 'in_execution' => 0, 'execution_source' => 'between', 'user_id' => null],
            ['sql' => 'select 3 as alone', 'in_execution' => 0, 'execution_source' => 'between', 'user_id' => null],
            ['sql' => 'select 4 as alone', 'in_execution' => 0, 'execution_source' => 'between', 'user_id' => null],
        ])->and(storeRows("SELECT count(DISTINCT execution_id) AS fragments FROM queries WHERE sql LIKE '%after%' OR sql LIKE '%alone%'"))->toBe([
            ['fragments' => 3],
        ]);
})->group('process');

it('stores the test on every record it writes, inside a request, inside a command and between them', function () {
    runAsWorker(null);
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));
    Artisan::command('orders:prune', function () {
        DB::select('select 2 as pruned');
    });

    DB::select('select 0 as before');
    $this->get('/orders')->assertOk();
    $this->artisan('orders:prune')->assertSuccessful();
    DB::select('select 3 as after');
    Nightwatch::digest();

    $test = 'Tests\\Feature\\TestCaptureTest::it stores the test on every record it writes, inside a request, inside a command and between them';

    expect(storeRows('SELECT type, source, test, worker FROM records ORDER BY started_at, id'))->toBe([
        ['type' => 'query', 'source' => 'between', 'test' => $test, 'worker' => null],
        ['type' => 'request', 'source' => 'request', 'test' => $test, 'worker' => null],
        ['type' => 'query', 'source' => 'request', 'test' => $test, 'worker' => null],
        ['type' => 'command', 'source' => 'command', 'test' => $test, 'worker' => null],
        ['type' => 'query', 'source' => 'command', 'test' => $test, 'worker' => null],
        ['type' => 'query', 'source' => 'between', 'test' => $test, 'worker' => null],
    ])->and(storeRows('SELECT DISTINCT test, worker FROM queries UNION SELECT DISTINCT test, worker FROM requests UNION SELECT DISTINCT test, worker FROM commands'))->toBe([
        ['test' => $test, 'worker' => null],
    ]);
});

it('keeps the data set in the test it stores', function (string $status) {
    captureTests();
    Route::get('/orders', fn () => DB::select('select ? as status', [$status]));

    $this->get('/orders')->assertOk();
    Nightwatch::digest();

    expect(storeRows('SELECT DISTINCT test FROM records'))->toBe([
        ['test' => "Tests\\Feature\\TestCaptureTest::it keeps the data set in the test it stores with dataset \"{$status} orders\""],
    ]);
})->with([
    'open orders' => ['open'],
    'paid orders' => ['paid'],
]);

it('stores the worker of a parallel run on every record a test writes', function () {
    runAsWorker('3');
    captureTests();
    Route::get('/orders', fn () => DB::select('select 1 as orders'));

    DB::select('select 0 as before');
    $this->get('/orders')->assertOk();
    Nightwatch::digest();

    expect(storeRows('SELECT type, source, worker FROM records ORDER BY started_at, id'))->toBe([
        ['type' => 'query', 'source' => 'between', 'worker' => '3'],
        ['type' => 'request', 'source' => 'request', 'worker' => '3'],
        ['type' => 'query', 'source' => 'request', 'worker' => '3'],
    ]);
});

it('stores no test and no worker outside a test run', function () {
    // A manifest the in-process tests wrote lacks this package, which only the testbench CLI discovers.
    $packagesCache = 'bootstrap/cache/firewatch-packages-'.bin2hex(random_bytes(8)).'.php';

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'members:audit', '7', '--env=testing'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite', 'APP_PACKAGES_CACHE' => $packagesCache, 'TEST_TOKEN' => false],
        timeout: 30,
    );

    $process->run();

    (new Filesystem)->delete(base_path($packagesCache));

    expect($process->getExitCode())->toBe(0)
        ->and(storeRows('SELECT type, test, worker FROM records ORDER BY started_at, id'))->toBe([
            ['type' => 'command', 'test' => null, 'worker' => null],
            ['type' => 'query', 'test' => null, 'worker' => null],
        ]);
})->group('process');

it('stores a PHPUnit test by its class and method, with its data set, and the worker of its process', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Fixtures/TestRun/OrdersTest.php'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $this->storeDirectory.'/firewatch.sqlite', 'TEST_TOKEN' => '2'],
        timeout: 30,
    );

    $process->run();

    $class = 'ClaudioDekker\\Firewatch\\Tests\\Fixtures\\TestRun\\OrdersTest';

    expect($process->getExitCode())->toBe(0)
        ->and(storeRows('SELECT test, worker, count(*) AS records, count(DISTINCT source) AS sources FROM records GROUP BY test, worker ORDER BY min(started_at)'))->toBe([
            ['test' => "{$class}::test_lists_orders with data set \"open\"", 'worker' => '2', 'records' => 4, 'sources' => 2],
            ['test' => "{$class}::test_lists_orders with data set \"paid\"", 'worker' => '2', 'records' => 4, 'sources' => 2],
            ['test' => "{$class}::test_prunes_orders", 'worker' => '2', 'records' => 3, 'sources' => 2],
        ]);
})->group('process');

it('stores the test on a record it cannot read, written during a test', function () {
    captureTests();

    ingest([['cache-event', 1]]);

    expect(storeRows('SELECT type, test FROM records'))->toBe([
        ['type' => null, 'test' => 'Tests\Feature\TestCaptureTest::it stores the test on a record it cannot read, written during a test'],
    ]);
});
