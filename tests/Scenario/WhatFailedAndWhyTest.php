<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Nightwatch\Facades\Nightwatch;
use Monolog\Handler\NullHandler;
use Workbench\App\Jobs\ChargeCard;
use Workbench\App\Jobs\ShipOrder;

const WHAT_FAILED_SHAPES = ['failing-routes', 'failing-jobs', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http'];

/**
 * Let a worker run one attempt for each run, in an application whose queue is a table of its own.
 *
 * @param  list<ShouldQueue>  $jobs
 */
function whatFailedJobs(array $jobs, int $runs): void
{
    test()->refreshApplication();

    config()->set('queue.default', 'database');
    config()->set('queue.failed.driver', 'null');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    foreach ($jobs as $job) {
        dispatch($job);
    }

    Nightwatch::digest();

    foreach (range(1, $runs) as $run) {
        runArtisan(['command' => 'queue:work', '--once' => true]);
    }
}

/**
 * Put the tasks on the schedule and let the scheduler run them once.
 *
 * @param  list<string>  $tasks
 */
function whatFailedTasks(array $tasks): void
{
    test()->refreshApplication();

    $schedule = app(Schedule::class);

    foreach ($tasks as $task) {
        match ($task) {
            'send-digest' => $schedule->call(fn () => null)->name('send-digest')->everyMinute(),
            'prune-carts' => $schedule->call(fn () => throw new RuntimeException('The carts table is locked.'))->name('prune-carts')->everyMinute(),
        };
    }

    runArtisan(['command' => 'schedule:run']);
}

/**
 * Get the user who signs in.
 */
function whatFailedUser(): GenericUser
{
    return new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']);
}

/**
 * Serve one request in an application of its own, so that it is an execution of its own, to a guest or to the user.
 */
function whatFailedRequest(string $uri, ?Authenticatable $user = null): TestResponse
{
    forceRequests();

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('logging.channels.audit', ['driver' => 'monolog', 'handler' => NullHandler::class]);

    Http::fake([
        'https://stock.example.com/*' => Http::response('ok'),
        'https://mail.example.com/*' => Http::failedConnection(),
    ]);

    Route::get('/stock', function () {
        Log::warning('The stock is low.');
        Http::get('https://stock.example.com/levels?sku=7');

        return 'ok';
    });
    Route::get('/welcome', function () {
        Log::channel('audit')->error('The audit trail could not be written.');

        try {
            dispatch_sync(new ChargeCard);
        } catch (RuntimeException $declined) {
            //
        }

        try {
            Http::post('https://mail.example.com/send');
        } catch (ConnectionException $unanswered) {
            //
        }

        return isset($declined, $unanswered) ? 'failed' : 'ok';
    });

    if ($user !== null) {
        test()->actingAs($user);
    }

    return test()->get($uri);
}

/**
 * Let a job fail for good, a task throw, and requests of a guest and of a signed-in user throw and call a dependency that answers errors.
 */
function whatFailedTraffic(): void
{
    whatFailedJobs([new ChargeCard, new ShipOrder], runs: 3);
    whatFailedTasks(['send-digest', 'prune-carts']);

    whatFailedRequest('/');
    whatFailedRequest('/quotes');
    whatFailedRequest('/invoices/7');
    whatFailedRequest('/quotes', whatFailedUser());
    whatFailedRequest('/invoices/8', whatFailedUser());
    whatFailedRequest('/invoices/9', whatFailedUser());
}

/**
 * Assert that every call the answer offers, and every call those answers offer in turn, answers in the envelope, and get the answers.
 *
 * @param  array<string, mixed>  $envelope
 * @return list<array<string, mixed>>
 */
function whatFailedAssertOfferedCalls(array $envelope): array
{
    $answers = [];
    $offered = $envelope['next'];

    while ($offered !== []) {
        $call = array_shift($offered);
        $key = json_encode([$call['tool'], $call['arguments']]);

        if (isset($answers[$key])) {
            continue;
        }

        $answers[$key] = Envelope::follow($call);

        array_push($offered, ...$answers[$key]['next']);
    }

    return array_values($answers);
}

/**
 * Get the result of a shape without what the sensors make unstable: the group hashes, the instants and the given fields of each finding.
 *
 * @param  array<string, mixed>  $result
 * @param  list<string>  $unstable
 * @return array<string, mixed>
 */
function whatFailedResult(array $result, array $unstable = []): array
{
    $result['findings'] = array_map(fn (array $finding) => Arr::except($finding, ['group', 'first_seen_at', 'last_seen_at', ...$unstable]), $result['findings']);

    return $result;
}

it('flags the route, the job, the task and the dependency that failed, with the evidence of each and whom it reached', function () {
    whatFailedTraffic();
    [$invoice] = storeRows('SELECT execution_id FROM requests WHERE status_code = 500 ORDER BY started_at DESC LIMIT 1');
    [$attempt] = storeRows("SELECT execution_id FROM job_attempts WHERE status = 'failed'");
    [$task] = storeRows("SELECT execution_id FROM scheduled_tasks WHERE status = 'failed'");
    [$quote] = storeRows('SELECT execution_id FROM outgoing_requests ORDER BY started_at DESC LIMIT 1');

    $routes = Envelope::assert(Detect::class, ['shape' => 'failing-routes'])['result'];
    $jobs = Envelope::assert(Detect::class, ['shape' => 'failing-jobs'])['result'];
    $tasks = Envelope::assert(Detect::class, ['shape' => 'failing-tasks'])['result'];
    $http = Envelope::assert(Detect::class, ['shape' => 'failing-http'])['result'];

    expect(whatFailedResult($routes))->toBe([
        'detector' => 'failing-routes',
        'threshold' => ['name' => 'status', 'value' => 400, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => true],
        'verdict' => 'findings',
        'reason' => null,
        'examined' => 6,
        'total' => 1,
        'findings' => [[
            'name' => '/invoices/{invoice}',
            'count' => 3,
            'latest_execution_id' => $invoice['execution_id'],
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => ['failed' => 3, 'requests' => 3, 'failure_pct' => 100, 'server_errors' => 3, 'status_counts' => [500 => 3], 'with_exception' => 3, 'unmatched' => false, 'method' => 'GET'],
        ]],
        'saw' => [],
        'caveats' => [],
    ])
        ->and(whatFailedResult($jobs, unstable: ['evidence.last_exception.location']))->toBe([
            'detector' => 'failing-jobs',
            'threshold' => ['name' => 'attempts', 'value' => 1, 'default' => 1, 'unit' => 'attempts', 'range' => ['min' => 1, 'max' => null], 'is_default' => true],
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 3,
            'total' => 1,
            'findings' => [[
                'name' => ChargeCard::class,
                'count' => 2,
                'latest_execution_id' => $attempt['execution_id'],
                'reaches' => ['signed_in_actors' => 0, 'without_actor' => 2],
                'evidence' => [
                    'failed_attempts' => 1,
                    'retried_attempts' => 1,
                    'jobs_failed' => 1,
                    'jobs_recovered' => 0,
                    'jobs_retrying' => 0,
                    'jobs' => 1,
                    'max_attempt' => 2,
                    'last_exception' => ['class' => RuntimeException::class, 'message' => 'The card was declined.'],
                ],
            ]],
            'saw' => [],
            'caveats' => [],
        ])
        ->and($jobs['findings'][0]['evidence']['last_exception']['location'])->toContain(nativePath('workbench/app/Jobs/ChargeCard.php:'))
        ->and(whatFailedResult($tasks))->toBe([
            'detector' => 'failing-tasks',
            'threshold' => null,
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 2,
            'total' => 1,
            'findings' => [[
                'name' => 'prune-carts',
                'count' => 1,
                'latest_execution_id' => $task['execution_id'],
                'reaches' => ['signed_in_actors' => 0, 'without_actor' => 1],
                'evidence' => ['kind' => 'failed', 'failed' => 1, 'skipped' => 0, 'runs' => 1],
            ]],
            'saw' => [],
            'caveats' => [__('firewatch::messages.detect_caveat_skipped'), __('firewatch::messages.detect_caveat_not_fired')],
        ])
        ->and(whatFailedResult($http))->toBe([
            'detector' => 'failing-http',
            'threshold' => ['name' => 'status', 'value' => 400, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => true],
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 2,
            'total' => 1,
            'findings' => [[
                'name' => 'rates.example.com',
                'count' => 2,
                'latest_execution_id' => $quote['execution_id'],
                'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
                'evidence' => [
                    'host' => 'rates.example.com',
                    'calls' => 2,
                    'failures' => 2,
                    'failure_pct' => 100,
                    'status_counts' => [503 => 2],
                    'top_urls' => [['url' => 'https://rates.example.com/quotes', 'failures' => 2]],
                    'ran_in' => [['source' => 'request', 'label' => '/quotes', 'calls' => 2]],
                ],
            ]],
            'saw' => [],
            'caveats' => [__('firewatch::messages.detect_caveat_unanswered')],
        ]);
});

it('flags the exceptions behind those failures and the error lines the handler wrote for them, and whom each reached', function () {
    whatFailedTraffic();
    [$invoice] = storeRows('SELECT execution_id FROM requests WHERE status_code = 500 ORDER BY started_at DESC LIMIT 1');
    [$attempt] = storeRows("SELECT execution_id FROM job_attempts WHERE status = 'failed'");
    [$task] = storeRows("SELECT execution_id FROM scheduled_tasks WHERE status = 'failed'");

    $exceptions = Envelope::assert(Detect::class, ['shape' => 'exception-clusters'])['result'];
    $logs = Envelope::assert(Detect::class, ['shape' => 'error-logs'])['result'];
    $thrown = array_column($exceptions['findings'], 'evidence');

    expect(whatFailedResult($exceptions, unstable: ['evidence.file', 'evidence.line', 'evidence.app_frame']))->toBe([
        'detector' => 'exception-clusters',
        'threshold' => ['name' => 'occurrences', 'value' => 1, 'default' => 1, 'unit' => 'occurrences', 'range' => ['min' => 1, 'max' => null], 'is_default' => true],
        'verdict' => 'findings',
        'reason' => null,
        'examined' => 11,
        'total' => 3,
        'findings' => [
            [
                'name' => RuntimeException::class,
                'count' => 3,
                'latest_execution_id' => $invoice['execution_id'],
                'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
                'evidence' => [
                    'class' => RuntimeException::class,
                    'message' => 'Invoice [9] could not be rendered.',
                    'escaped' => 3,
                    'reported' => 0,
                    'fatal' => false,
                    'units' => [['source' => 'request', 'label' => '/invoices/{invoice}', 'occurrences' => 3]],
                ],
            ],
            [
                'name' => RuntimeException::class,
                'count' => 2,
                'latest_execution_id' => $attempt['execution_id'],
                'reaches' => ['signed_in_actors' => 0, 'without_actor' => 2],
                'evidence' => [
                    'class' => RuntimeException::class,
                    'message' => 'The card was declined.',
                    'escaped' => 2,
                    'reported' => 0,
                    'fatal' => false,
                    'units' => [['source' => 'job', 'label' => ChargeCard::class, 'occurrences' => 2]],
                ],
            ],
            [
                'name' => RuntimeException::class,
                'count' => 1,
                'latest_execution_id' => $task['execution_id'],
                'reaches' => ['signed_in_actors' => 0, 'without_actor' => 1],
                'evidence' => [
                    'class' => RuntimeException::class,
                    'message' => 'The carts table is locked.',
                    'escaped' => 1,
                    'reported' => 0,
                    'fatal' => false,
                    'units' => [['source' => 'schedule', 'label' => 'prune-carts', 'occurrences' => 1]],
                ],
            ],
        ],
        'saw' => [],
        'caveats' => [],
    ])
        ->and($thrown[0]['app_frame'])->toEndWith(nativePath("workbench/routes/web.php:{$thrown[0]['line']}"))
        ->and($thrown[1]['app_frame'])->toEndWith(nativePath("workbench/app/Jobs/ChargeCard.php:{$thrown[1]['line']}"))
        ->and($thrown[2]['app_frame'])->toEndWith(nativePath("tests/Scenario/WhatFailedAndWhyTest.php:{$thrown[2]['line']}"))
        ->and(array_column($logs['findings'], 'group'))->toBe([null, null])
        ->and(whatFailedResult($logs))->toBe([
            'detector' => 'error-logs',
            'threshold' => ['name' => 'occurrences', 'value' => 1, 'default' => 1, 'unit' => 'occurrences', 'range' => ['min' => 1, 'max' => null], 'is_default' => true],
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 5,
            'total' => 2,
            'findings' => [
                [
                    'name' => 'Invoice [<n>] could not be rendered.',
                    'count' => 3,
                    'latest_execution_id' => $invoice['execution_id'],
                    'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
                    'evidence' => ['level' => 'error', 'message' => 'Invoice [9] could not be rendered.', 'shape' => 'Invoice [<n>] could not be rendered.', 'fragment' => '] could not be rendered.', 'occurrences' => 3, 'in_executions_with_exception' => 3],
                ],
                [
                    'name' => 'The card was declined.',
                    'count' => 2,
                    'latest_execution_id' => $attempt['execution_id'],
                    'reaches' => ['signed_in_actors' => 0, 'without_actor' => 2],
                    'evidence' => ['level' => 'error', 'message' => 'The card was declined.', 'shape' => 'The card was declined.', 'fragment' => 'The card was declined.', 'occurrences' => 2, 'in_executions_with_exception' => 2],
                ],
            ],
            'saw' => [],
            'caveats' => [__('firewatch::messages.detect_caveat_log_capture')],
        ]);
});

it('narrows a shape to the group of a finding, and gets that finding alone', function (string $shape, int $groups) {
    whatFailedTraffic();

    $findings = Envelope::assert(Detect::class, ['shape' => $shape])['result']['findings'];
    $narrowed = array_map(fn (array $finding) => Envelope::assert(Detect::class, ['shape' => $shape, 'group' => $finding['group']])['result'], $findings);

    expect(array_unique(array_column($findings, 'group')))->toHaveCount($groups)
        ->and(array_column($narrowed, 'total'))->toBe(array_fill(0, $groups, 1))
        ->and(array_column($narrowed, 'findings'))->toBe(array_map(fn (array $finding) => [$finding], $findings));
})->with([
    'failing-routes' => ['failing-routes', 1],
    'failing-jobs' => ['failing-jobs', 1],
    'failing-tasks' => ['failing-tasks', 1],
    'exception-clusters' => ['exception-clusters', 3],
    'failing-http' => ['failing-http', 1],
]);

it('opens the execution a finding points at, and shows what it threw', function (string $shape, array $header, string $message) {
    whatFailedTraffic();

    $finding = Envelope::assert(Detect::class, ['shape' => $shape]);
    $call = $finding['next'][0];
    $opened = Envelope::assert(Execution::class, $call['arguments'])['result'];

    expect($call)->toBe(['tool' => 'execution', 'arguments' => ['execution_id' => $finding['result']['findings'][0]['latest_execution_id']], 'why' => __('firewatch::messages.detect_next_execution')])
        ->and($opened['header'])->toMatchArray([...$header, 'execution_id' => $call['arguments']['execution_id'], 'group' => $finding['result']['findings'][0]['group']])
        ->and(array_map(fn (array $exception) => Arr::only($exception, ['class', 'message', 'handled']), $opened['exceptions']))->toBe([['class' => RuntimeException::class, 'message' => $message, 'handled' => false]])
        ->and(array_column($opened['accounting']['counters'], 'state', 'counter'))->toMatchArray(['exceptions' => 'match', 'logs' => 'match']);
})->with([
    'the request' => ['failing-routes', ['type' => 'request', 'source' => 'request', 'label' => '/invoices/{invoice}', 'outcome' => 500, 'user_id' => '7'], 'Invoice [9] could not be rendered.'],
    'the job attempt' => ['failing-jobs', ['type' => 'job-attempt', 'source' => 'job', 'label' => ChargeCard::class, 'outcome' => 'failed', 'user_id' => null], 'The card was declined.'],
    'the scheduled task' => ['failing-tasks', ['type' => 'scheduled-task', 'source' => 'schedule', 'label' => 'prune-carts', 'outcome' => 'failed', 'user_id' => null], 'The carts table is locked.'],
]);

it('follows the failed attempt into its trace, which holds the dispatch and both attempts of the job', function () {
    whatFailedTraffic();
    $attempts = array_column(storeRows('SELECT execution_id FROM job_attempts ORDER BY started_at'), 'execution_id');

    $finding = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);
    $opened = Envelope::assert(Execution::class, $finding['next'][0]['arguments']);
    $call = collect($opened['next'])->firstWhere('tool', 'trace');
    $trace = Envelope::assert(Trace::class, $call['arguments']);
    $jobs = collect($trace['result']['jobs'])->keyBy('name');

    expect($call)->toBe(['tool' => 'trace', 'arguments' => ['trace_id' => $opened['result']['header']['trace_id']], 'why' => __('firewatch::messages.execution_next_trace')])
        ->and($trace['result']['trace_id'])->toBe($call['arguments']['trace_id'])
        ->and(array_map(fn (array $execution) => Arr::only($execution, ['execution_id', 'source', 'label', 'outcome']), $trace['result']['executions']))->toBe([
            ['execution_id' => $attempts[0], 'source' => 'job', 'label' => ChargeCard::class, 'outcome' => 'released'],
            ['execution_id' => $attempts[1], 'source' => 'job', 'label' => ShipOrder::class, 'outcome' => 'processed'],
            ['execution_id' => $attempts[2], 'source' => 'job', 'label' => ChargeCard::class, 'outcome' => 'failed'],
        ])
        ->and($jobs->keys()->all())->toBe([ChargeCard::class, ShipOrder::class])
        ->and($jobs[ChargeCard::class])->toMatchArray(['lineage' => 'complete', 'outcome' => 'failed'])
        ->and($jobs[ChargeCard::class]['dispatch'])->toMatchArray(['connection' => 'database', 'queue' => 'default'])
        ->and(array_map(fn (array $attempt) => Arr::only($attempt, ['attempt', 'execution_id', 'status']), $jobs[ChargeCard::class]['attempts']))->toBe([
            ['attempt' => 1, 'execution_id' => $attempts[0], 'status' => 'released'],
            ['attempt' => 2, 'execution_id' => $attempts[2], 'status' => 'failed'],
        ])
        ->and($jobs[ShipOrder::class])->toMatchArray(['lineage' => 'complete', 'outcome' => 'processed'])
        ->and($trace['notes'])->toBe([])
        ->and(array_slice($trace['next'], 0, 2))->toBe([
            ['tool' => 'execution', 'arguments' => ['execution_id' => $attempts[0]], 'why' => __('firewatch::messages.trace_next_failed')],
            ['tool' => 'execution', 'arguments' => ['execution_id' => $attempts[2]], 'why' => __('firewatch::messages.trace_next_failed')],
        ]);
});

it('runs every call the findings of a shape offer, and every call those answers offer in turn', function (string $shape, array $tools) {
    whatFailedTraffic();

    $answers = whatFailedAssertOfferedCalls(Envelope::assert(Detect::class, ['shape' => $shape]));

    expect(array_values(array_unique(array_column($answers, 'tool'))))->toEqualCanonicalizing($tools)
        ->and(array_column($answers, 'empty'))->each->toBeNull();
})->with([
    'failing-routes' => ['failing-routes', ['execution', 'occurrences', 'rank', 'trace']],
    'failing-jobs' => ['failing-jobs', ['execution', 'occurrences', 'rank', 'trace']],
    'failing-tasks' => ['failing-tasks', ['execution', 'occurrences', 'rank', 'trace']],
    'exception-clusters' => ['exception-clusters', ['execution', 'occurrences', 'rank', 'trace']],
    'error-logs' => ['error-logs', ['execution', 'occurrences', 'rank', 'trace']],
    'failing-http' => ['failing-http', ['execution', 'occurrences', 'rank', 'trace']],
]);

it('finds nothing in work that succeeded, and says how much of it each shape examined', function () {
    whatFailedJobs([new ShipOrder], runs: 1);
    whatFailedTasks(['send-digest']);
    whatFailedRequest('/stock');
    whatFailedRequest('/');
    whatFailedRequest('/stock', whatFailedUser());

    $answers = array_map(fn (string $shape) => Envelope::assert(Detect::class, ['shape' => $shape]), array_combine(WHAT_FAILED_SHAPES, WHAT_FAILED_SHAPES));
    $clean = fn (string $shape, int $examined, array $caveats = []) => ['detector' => $shape, 'verdict' => 'clean', 'reason' => null, 'examined' => $examined, 'total' => 0, 'findings' => [], 'saw' => [], 'caveats' => $caveats];

    expect(array_map(fn (array $answer) => Arr::except($answer['result'], 'threshold'), $answers))->toBe([
        'failing-routes' => $clean('failing-routes', examined: 3),
        'failing-jobs' => $clean('failing-jobs', examined: 1),
        'failing-tasks' => $clean('failing-tasks', examined: 1, caveats: [__('firewatch::messages.detect_caveat_skipped'), __('firewatch::messages.detect_caveat_not_fired')]),
        'exception-clusters' => $clean('exception-clusters', examined: 5),
        'error-logs' => $clean('error-logs', examined: 2, caveats: [__('firewatch::messages.detect_caveat_log_capture')]),
        'failing-http' => $clean('failing-http', examined: 2, caveats: [__('firewatch::messages.detect_caveat_unanswered')]),
    ])
        ->and(array_column($answers, 'empty'))->each->toBeNull()
        ->and(array_column($answers, 'next'))->each->toBe([]);
});

it('does not evaluate the shapes whose failures left no record, names the blind spot behind each, and is clean only over the request it examined', function () {
    whatFailedRequest('/welcome')->assertOk()->assertContent('failed');

    $answers = array_map(fn (string $shape) => Envelope::assert(Detect::class, ['shape' => $shape]), array_combine(WHAT_FAILED_SHAPES, WHAT_FAILED_SHAPES));
    $blindSpots = array_map(fn (array $answer) => array_column($answer['blind_spots'], 'message', 'id'), $answers);

    expect(array_map(fn (array $answer) => Arr::only($answer['result'], ['verdict', 'reason', 'examined', 'total', 'findings']), $answers))->toBe([
        'failing-routes' => ['verdict' => 'clean', 'reason' => null, 'examined' => 1, 'total' => 0, 'findings' => []],
        'failing-jobs' => ['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []],
        'failing-tasks' => ['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []],
        'exception-clusters' => ['verdict' => 'clean', 'reason' => null, 'examined' => 1, 'total' => 0, 'findings' => []],
        'error-logs' => ['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []],
        'failing-http' => ['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []],
    ])
        ->and(array_column($answers, 'empty'))->each->toBeNull()
        ->and(array_column($answers, 'next'))->each->toBe([])
        ->and(array_keys($blindSpots['failing-jobs']))->toBe(['dead-counters', 'sync-jobs-unrecorded', 'memory-is-process-peak', 'uninstrumented-dispatcher', 'actor-partial'])
        ->and($blindSpots['failing-jobs']['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
        ->and($blindSpots['exception-clusters']['exceptions-unreported'])->toBe(__('firewatch::messages.blind_spots.exceptions-unreported'))
        ->and(array_keys($blindSpots['error-logs']))->toBe(['named-log-channels', 'actor-partial'])
        ->and($blindSpots['error-logs']['named-log-channels'])->toBe(__('firewatch::messages.blind_spots.named-log-channels'))
        ->and(array_keys($blindSpots['failing-http']))->toBe(['unanswered-outgoing-requests', 'actor-partial'])
        ->and($blindSpots['failing-http']['unanswered-outgoing-requests'])->toBe(__('firewatch::messages.blind_spots.unanswered-outgoing-requests'));
});
