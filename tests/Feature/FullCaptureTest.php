<?php

use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Console\Sample as TaskSample;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Http\Middleware\Sample;
use Laravel\Nightwatch\Records\CacheEvent;
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
    'a request' => ['traffic' => function () {
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        test()->get('/');
    }, 'view' => 'requests'],
    'a command' => ['traffic' => fn () => runArtisan(['command' => 'env']), 'view' => 'commands'],
    'a scheduled task' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => null)->everyMinute();

        runArtisan(['command' => 'schedule:run']);
    }, 'view' => 'scheduled_tasks'],
]);

it('captures the events Nightwatch\'s filtering would ignore', function (Closure $traffic, string $view) {
    configureNightwatchWith([
        'NIGHTWATCH_IGNORE_CACHE_EVENTS' => 'true',
        'NIGHTWATCH_IGNORE_QUERIES' => 'true',
        'NIGHTWATCH_LOG_LEVEL' => 'emergency',
    ]);

    $traffic();
    Nightwatch::digest();

    expect(capturedRows("SELECT count(*) AS captured FROM {$view}"))->toBe([['captured' => 1]]);
})->with([
    'a cache event' => ['traffic' => fn () => Cache::get('orders'), 'view' => 'cache_events'],
    'a query' => ['traffic' => fn () => DB::select('select 1'), 'view' => 'queries'],
    'a debug log' => ['traffic' => fn () => Log::channel('nightwatch')->debug('The payment is slow.'), 'view' => 'logs'],
]);

function requestTo(string $uri, Closure $route): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $route();

    test()->get($uri);
}

it('drops what an in-code opt-out targets', function (Closure $traffic, string $dropped) {
    Cache::get('warm-up');
    Nightwatch::digest();

    $traffic();
    Nightwatch::digest();

    expect(capturedRows("SELECT count(*) AS dropped FROM {$dropped}"))->toBe([['dropped' => 0]]);
})->with([
    'ignore' => ['traffic' => fn () => Nightwatch::ignore(fn () => Cache::get('orders')), 'dropped' => "cache_events WHERE key = 'orders'"],
    'pause' => ['traffic' => function () {
        Nightwatch::pause();
        Cache::get('orders');
        Nightwatch::resume();
    }, 'dropped' => "cache_events WHERE key = 'orders'"],
    'dontSample' => ['traffic' => fn () => requestTo('/quiet', fn () => Route::get('/quiet', fn () => Nightwatch::dontSample())), 'dropped' => 'requests'],
    'Sample::never on a route' => ['traffic' => fn () => requestTo('/never', fn () => Route::get('/never', fn () => 'ok')->middleware(Sample::never())), 'dropped' => 'requests'],
    'a Sample rate of zero on a route' => ['traffic' => fn () => requestTo('/rare', fn () => Route::get('/rare', fn () => 'ok')->middleware(Sample::rate(0.0))), 'dropped' => 'requests'],
    'Sample::never on a scheduled task' => ['traffic' => function () {
        app(Schedule::class)->call(fn () => null)->everyMinute()->tap(TaskSample::never());

        runArtisan(['command' => 'schedule:run']);
    }, 'dropped' => 'scheduled_tasks'],
    'rejectCacheEvents' => ['traffic' => function () {
        Nightwatch::rejectCacheEvents(fn (CacheEvent $event) => $event->key === 'orders');

        Cache::get('orders');
    }, 'dropped' => "cache_events WHERE key = 'orders'"],
    'rejectCacheKeys' => ['traffic' => function () {
        Nightwatch::rejectCacheKeys(['orders']);

        Cache::get('orders');
    }, 'dropped' => "cache_events WHERE key = 'orders'"],
    'rejectQueries' => ['traffic' => function () {
        Nightwatch::rejectQueries(fn () => true);

        DB::select('select 1');
    }, 'dropped' => 'queries'],
    'rejectMail' => ['traffic' => function () {
        config()->set('mail.default', 'array');
        Nightwatch::rejectMail(fn () => true);

        Mail::raw('Your order shipped.', fn ($message) => $message->to('taylor@example.com'));
    }, 'dropped' => 'mail'],
    'rejectNotifications' => ['traffic' => function () {
        Nightwatch::rejectNotifications(fn () => true);

        (new AnonymousNotifiable)->notifyNow(new OrderShipped);
    }, 'dropped' => 'notifications'],
    'rejectOutgoingRequests' => ['traffic' => function () {
        Http::fake(['https://example.com/ping' => Http::response('pong')]);
        Nightwatch::rejectOutgoingRequests(fn () => true);

        Http::get('https://example.com/ping');
    }, 'dropped' => 'outgoing_requests'],
    'rejectQueuedJobs' => ['traffic' => function () {
        config()->set('queue.default', 'database');
        Nightwatch::rejectQueuedJobs(fn () => true);

        dispatch(fn () => null);
    }, 'dropped' => 'queued_jobs'],
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
