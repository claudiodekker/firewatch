<?php

use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Nightwatch\Console\Sample as TaskSample;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Http\Middleware\Sample;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Mail as MailRecord;
use Laravel\Nightwatch\Records\Notification as NotificationRecord;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Query;
use Laravel\Nightwatch\Records\QueuedJob;
use Workbench\App\Notifications\OrderDelayed;
use Workbench\App\Notifications\OrderShipped;

/**
 * @return list<array<string, mixed>>
 */
function capturedRows(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

/**
 * @param  array<string, string>  $variables
 */
function configureNightwatchWith(array $variables): void
{
    foreach ($variables as $name => $value) {
        setEnvironmentVariable($name, $value);
    }

    test()->refreshApplication();
}

function requestTo(string $uri, ?Closure $route = null): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    if ($route !== null) {
        $route();
    }

    test()->get($uri);
}

it('captures every execution Nightwatch\'s sample rates would drop', function (Closure $traffic, string $view) {
    configureNightwatchWith([
        'NIGHTWATCH_REQUEST_SAMPLE_RATE' => '0',
        'NIGHTWATCH_COMMAND_SAMPLE_RATE' => '0',
        'NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE' => '0',
        'NIGHTWATCH_EXCEPTION_SAMPLE_RATE' => '0',
    ]);

    $traffic();
    Nightwatch::digest();

    expect(capturedRows("SELECT count(*) AS captured FROM {$view}"))->toBe([['captured' => 1]]);
})->with([
    'a request' => ['traffic' => fn () => requestTo('/'), 'view' => 'requests'],
    'a command' => ['traffic' => fn () => runArtisan(['command' => 'env']), 'view' => 'commands'],
    'a scheduled task' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => null)->everyMinute();

        runArtisan(['command' => 'schedule:run']);
    }, 'view' => 'scheduled_tasks'],
    'an exception in an unsampled execution' => ['traffic' => function () {
        Nightwatch::dontSample();

        Nightwatch::report(new RuntimeException('The payment failed.'));
    }, 'view' => 'exceptions'],
]);

it('queues a job dispatched by a request Nightwatch\'s sample rate would drop as sampled, so its attempt is captured', function () {
    configureNightwatchWith(['NIGHTWATCH_REQUEST_SAMPLE_RATE' => '0']);
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('queue.default', 'database');
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Route::get('/ship', function () {
        dispatch(fn () => null);

        return 'ok';
    });

    test()->get('/ship');

    // A job attempt follows the sampling decision its payload carries from the dispatching execution.
    $payload = json_decode(DB::table('jobs')->value('payload'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($payload['illuminate:log:context']['hidden']['nightwatch_should_sample'])->toBe(serialize(true));
});

it('captures the events Nightwatch\'s filtering would ignore', function (Closure $traffic, string $view) {
    configureNightwatchWith([
        'NIGHTWATCH_IGNORE_CACHE_EVENTS' => 'true',
        'NIGHTWATCH_IGNORE_MAIL' => 'true',
        'NIGHTWATCH_IGNORE_NOTIFICATIONS' => 'true',
        'NIGHTWATCH_IGNORE_OUTGOING_REQUESTS' => 'true',
        'NIGHTWATCH_IGNORE_QUERIES' => 'true',
        'NIGHTWATCH_LOG_LEVEL' => 'emergency',
    ]);

    $traffic();
    Nightwatch::digest();

    expect(capturedRows("SELECT count(*) AS captured FROM {$view}"))->toBe([['captured' => 1]]);
})->with([
    'a cache event' => ['traffic' => fn () => Cache::get('orders'), 'view' => 'cache_events'],
    'a mail' => ['traffic' => fn () => Mail::raw('Your order shipped.', fn ($message) => $message->to('taylor@example.com')), 'view' => 'mail'],
    'a notification' => ['traffic' => fn () => (new AnonymousNotifiable)->notifyNow(new OrderShipped), 'view' => 'notifications'],
    'an outgoing request' => ['traffic' => function () {
        Http::fake(['https://example.com/ping' => Http::response('pong')]);

        Http::get('https://example.com/ping');
    }, 'view' => 'outgoing_requests'],
    'a query' => ['traffic' => fn () => DB::select('select 1'), 'view' => 'queries'],
    'a debug log' => ['traffic' => fn () => Log::channel('nightwatch')->debug('The payment is slow.'), 'view' => 'logs'],
]);

it('drops what an in-code opt-out targets and keeps the rest', function (Closure $traffic, string $query, array $kept) {
    $traffic();
    Nightwatch::digest();

    expect(capturedRows($query))->toBe($kept);
})->with([
    'ignore' => ['traffic' => function () {
        Nightwatch::ignore(fn () => Cache::get('orders'));
        Cache::get('invoices');
    }, 'query' => 'SELECT key FROM cache_events', 'kept' => [['key' => 'invoices']]],
    'pause' => ['traffic' => function () {
        Nightwatch::pause();
        Cache::get('orders');
        Nightwatch::resume();
        Cache::get('invoices');
    }, 'query' => 'SELECT key FROM cache_events', 'kept' => [['key' => 'invoices']]],
    'dontSample' => ['traffic' => function () {
        requestTo('/quiet', fn () => Route::get('/quiet', fn () => Nightwatch::dontSample()));
        requestTo('/');
    }, 'query' => 'SELECT route_path FROM requests', 'kept' => [['route_path' => '/']]],
    'Sample::never on a route' => ['traffic' => function () {
        requestTo('/never', fn () => Route::get('/never', fn () => 'ok')->middleware(Sample::never()));
        requestTo('/');
    }, 'query' => 'SELECT route_path FROM requests', 'kept' => [['route_path' => '/']]],
    'a Sample rate of zero on a route' => ['traffic' => function () {
        requestTo('/rare', fn () => Route::get('/rare', fn () => 'ok')->middleware(Sample::rate(0.0)));
        requestTo('/');
    }, 'query' => 'SELECT route_path FROM requests', 'kept' => [['route_path' => '/']]],
    'Sample::never on a scheduled task' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => null)->name('prune-orders')->everyMinute()->tap(TaskSample::never());
        app(Schedule::class)->call(fn () => null)->name('send-invoices')->everyMinute();

        runArtisan(['command' => 'schedule:run']);
    }, 'query' => 'SELECT name FROM scheduled_tasks', 'kept' => [['name' => 'send-invoices']]],
    'rejectCacheEvents' => ['traffic' => function () {
        Nightwatch::rejectCacheEvents(fn (CacheEvent $event) => $event->key === 'orders');

        Cache::get('orders');
        Cache::get('invoices');
    }, 'query' => 'SELECT key FROM cache_events', 'kept' => [['key' => 'invoices']]],
    'rejectCacheKeys' => ['traffic' => function () {
        Nightwatch::rejectCacheKeys(['orders']);

        Cache::get('orders');
        Cache::get('invoices');
    }, 'query' => 'SELECT key FROM cache_events', 'kept' => [['key' => 'invoices']]],
    'rejectQueries' => ['traffic' => function () {
        Nightwatch::rejectQueries(fn (Query $query) => $query->sql === 'select 1');

        DB::select('select 1');
        DB::select('select 2');
    }, 'query' => "SELECT sql FROM queries WHERE sql LIKE 'select _'", 'kept' => [['sql' => 'select 2']]],
    'rejectMail' => ['traffic' => function () {
        Nightwatch::rejectMail(fn (MailRecord $mail) => $mail->subject === 'Shipped');

        Mail::raw('Your order shipped.', fn ($message) => $message->to('taylor@example.com')->subject('Shipped'));
        Mail::raw('Your order is late.', fn ($message) => $message->to('taylor@example.com')->subject('Delayed'));
    }, 'query' => 'SELECT subject FROM mail', 'kept' => [['subject' => 'Delayed']]],
    'rejectNotifications' => ['traffic' => function () {
        Nightwatch::rejectNotifications(fn (NotificationRecord $notification) => $notification->class === OrderShipped::class);

        (new AnonymousNotifiable)->notifyNow(new OrderShipped);
        (new AnonymousNotifiable)->notifyNow(new OrderDelayed);
    }, 'query' => 'SELECT class FROM notifications', 'kept' => [['class' => OrderDelayed::class]]],
    'rejectOutgoingRequests' => ['traffic' => function () {
        Http::fake([
            'https://example.com/ping' => Http::response('pong'),
            'https://example.com/status' => Http::response('up'),
        ]);
        Nightwatch::rejectOutgoingRequests(fn (OutgoingRequest $request) => $request->url === 'https://example.com/ping');

        Http::get('https://example.com/ping');
        Http::get('https://example.com/status');
    }, 'query' => 'SELECT url FROM outgoing_requests', 'kept' => [['url' => 'https://example.com/status']]],
    'rejectQueuedJobs' => ['traffic' => function () {
        config()->set('queue.default', 'database');
        Nightwatch::rejectQueuedJobs(fn (QueuedJob $job) => $job->queue === 'reports');

        dispatch(fn () => null)->onQueue('reports');
        dispatch(fn () => null)->onQueue('orders');
    }, 'query' => 'SELECT queue FROM queued_jobs', 'kept' => [['queue' => 'orders']]],
]);

it('captures the source lines of an exception\'s frames even when Nightwatch is configured not to', function () {
    configureNightwatchWith(['NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE' => 'false']);
    app(Core::class)->sensor->location->setBasePath(dirname(__DIR__, 2));

    Nightwatch::report(new RuntimeException('The payment failed.'));

    [$exception] = capturedRows("SELECT trace -> '$[0].code' AS code FROM exceptions");

    expect($exception['code'])->toContain('The payment failed.');
});

it('writes the deploy setting to Nightwatch, or leaves Nightwatch\'s own when it is unset', function (array $variables, string $deploy) {
    configureNightwatchWith(['NIGHTWATCH_DEPLOY' => 'nightwatch-deploy', ...$variables]);

    Cache::get('orders');
    Nightwatch::digest();

    expect(capturedRows('SELECT deploy FROM cache_events'))->toBe([['deploy' => $deploy]]);
})->with([
    'set' => ['variables' => ['FIREWATCH_DEPLOY' => 'firewatch-deploy'], 'deploy' => 'firewatch-deploy'],
    'unset' => ['variables' => [], 'deploy' => 'nightwatch-deploy'],
]);
