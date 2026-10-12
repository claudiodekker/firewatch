<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Process\Process;

const ORDERS_TEST = 'ClaudioDekker\Firewatch\Tests\Fixtures\TestRun\OrdersTest';

/**
 * Run an application's three feature tests as worker 2 of a parallel run: two list orders, one per data set, and one prunes them.
 */
function whichTestRun(): void
{
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', 'tests/Fixtures/TestRun/OrdersTest.php'],
        cwd: dirname(__DIR__, 2),
        env: ['FIREWATCH_CAPTURE_TESTS' => 'true', 'FIREWATCH_DATABASE' => $_SERVER['FIREWATCH_DATABASE'], 'TEST_TOKEN' => '2'],
        timeout: 30,
    );

    $process->mustRun();
}

/**
 * Serve a request outside any test run.
 */
function whichTestBrowsing(): void
{
    forceRequests();

    Route::get('/orders', fn () => DB::select('select ? as status', ['open']));

    test()->get('/orders')->assertOk();

    Nightwatch::digest();
}

/**
 * Get the test of the fixture run with the name.
 */
function whichTest(string $name): string
{
    return ORDERS_TEST.'::'.$name;
}

/**
 * Get the rows of a run as their source, label and test, leaving out what the clock decides.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function whichTestRows(array $rows): array
{
    return array_map(fn (array $row) => trim("{$row['source']} {$row['label']}").' | '.str_replace(ORDERS_TEST.'::', '', $row['test'] ?? '-')." | {$row['queries']} queries", $rows);
}

it('names the test and the worker of every execution and between section of a run', function () {
    whichTestRun();

    $run = Answers::assert(Occurrences::class, ['order' => 'run']);
    $rows = $run['result']['rows'];

    expect(whichTestRows($rows))->toBe([
        'between | test_lists_orders with data set "open" | 1 queries',
        'request /orders | test_lists_orders with data set "open" | 2 queries',
        'between | test_lists_orders with data set "paid" | 1 queries',
        'request /orders | test_lists_orders with data set "paid" | 2 queries',
        'command orders:prune | test_prunes_orders | 1 queries',
        'between | test_prunes_orders | 1 queries',
    ])->and(array_keys($rows[0]))->toBe(['started_at', 'source', 'type', 'execution_id', 'label', 'outcome', 'duration_ms', 'queries', 'query_ms', 'other', 'test', 'worker'])
        ->and(array_column($rows, 'worker'))->toBe(['2', '2', '2', '2', '2', '2'])
        ->and($rows[0]['test'])->toBe(whichTest('test_lists_orders with data set "open"'))
        ->and($run['window'])->not->toHaveKey('test');
})->group('process');

it('lists the run of one test, on one side and page by page', function () {
    whichTestRun();

    $test = whichTest('test_lists_orders with data set "paid"');
    $rows = Answers::assert(Occurrences::class, ['order' => 'run'])['result']['rows'];

    $one = Answers::assert(Occurrences::class, ['order' => 'run', 'test' => $test]);
    $between = Answers::assert(Occurrences::class, ['order' => 'run', 'test' => $test, 'scope' => 'between']);
    $first = Answers::assert(Occurrences::class, ['order' => 'run', 'test' => $test, 'limit' => 1]);

    preg_match('/cursor: "([^"]+)"/', $first['truncated'][0]['how'], $cursor);

    $second = Answers::assert(Occurrences::class, ['order' => 'run', 'test' => $test, 'limit' => 1, 'cursor' => $cursor[1]]);

    expect($one['result']['rows'])->toBe([$rows[2], $rows[3]])
        ->and($one['window'])->toMatchArray(['since' => null, 'until' => null, 'test' => $test])
        ->and($one['summary'])->toBe(trans_choice('firewatch::messages.occurrences_run_summary', 2, ['count' => 2]))
        ->and($between['result']['rows'])->toBe([$rows[2]])
        ->and($between['window'])->toMatchArray(['scope' => 'between', 'test' => $test])
        ->and($first['result']['rows'])->toBe([$rows[2]])
        ->and($first['truncated'][0]['how'])->toContain('test: '.json_encode($test, JSON_UNESCAPED_SLASHES))
        ->and($second['result']['rows'])->toBe([$rows[3]])
        ->and($second['truncated'])->toBe([]);
})->group('process');

it('lists and ranks only the records of one test', function () {
    whichTestRun();

    $open = whichTest('test_lists_orders with data set "open"');
    $prunes = whichTest('test_prunes_orders');

    $listed = Answers::assert(Occurrences::class, ['type' => 'query', 'test' => $open]);
    $everywhere = Answers::assert(Rank::class, ['type' => 'query', 'by' => 'occurrences']);
    $ranked = Answers::assert(Rank::class, ['type' => 'query', 'by' => 'occurrences', 'test' => $prunes]);
    $between = Answers::assert(Rank::class, ['type' => 'query', 'by' => 'occurrences', 'test' => $prunes, 'scope' => 'between']);

    $names = array_column($listed['result']['rows'], 'name');

    sort($names);

    expect($names)->toBe(['select ? as customer', 'select ? as seeded', 'select ? as status'])
        ->and($listed['window'])->toMatchArray(['test' => $open])
        ->and($listed['next'])->toBe([
            ['tool' => 'rank', 'arguments' => ['group' => $listed['result']['rows'][0]['group'], 'test' => $open], 'why' => __('firewatch::messages.occurrences_next_group')],
        ])->and(array_column($everywhere['result']['groups'], 'occurrences', 'label'))->toBe(['select ? as status' => 3, 'select ? as customer' => 2, 'select ? as seeded' => 2, 'select ? as remaining' => 1])
        ->and($everywhere['window'])->not->toHaveKey('test')
        ->and($ranked['result']['records'])->toBe(2)
        ->and(array_column($ranked['result']['groups'], 'occurrences', 'label'))->toEqualCanonicalizing(['select ? as status' => 1, 'select ? as remaining' => 1])
        ->and($ranked['window'])->toMatchArray(['test' => $prunes])
        ->and($ranked['next'][0]['arguments'])->toBe(['group' => $ranked['result']['groups'][0]['group'], 'test' => $prunes])
        ->and(array_column($between['result']['groups'], 'occurrences', 'label'))->toBe(['select ? as remaining' => 1])
        ->and($between['next'][0]['arguments'])->toBe(['group' => $between['result']['groups'][0]['group'], 'scope' => 'between', 'test' => $prunes]);
})->group('process');

it('ranks what a route ran inside one test', function () {
    whichTestRun();

    $paid = whichTest('test_lists_orders with data set "paid"');
    $route = Answers::assert(Rank::class, ['type' => 'request'])['result']['groups'][0]['group'];

    $everywhere = Answers::assert(Rank::class, ['type' => 'query', 'within' => $route, 'by' => 'occurrences']);
    $within = Answers::assert(Rank::class, ['type' => 'query', 'within' => $route, 'by' => 'occurrences', 'test' => $paid]);
    $requests = Answers::assert(Rank::class, ['type' => 'request', 'test' => $paid]);

    expect($everywhere['result']['within'])->toBe(['group' => $route, 'type' => 'request', 'label' => '/orders', 'executions' => 2])
        ->and(array_column($everywhere['result']['groups'], 'occurrences', 'label'))->toEqualCanonicalizing(['select ? as status' => 2, 'select ? as customer' => 2])
        ->and($within['result']['within'])->toBe(['group' => $route, 'type' => 'request', 'label' => '/orders', 'executions' => 1])
        ->and($within['result']['records'])->toBe(2)
        ->and(array_column($within['result']['groups'], 'occurrences', 'label'))->toEqualCanonicalizing(['select ? as status' => 1, 'select ? as customer' => 1])
        ->and(array_column($requests['result']['groups'], 'occurrences', 'label'))->toBe(['/orders' => 1]);
})->group('process');

it('names the test and the worker of the execution it opens', function () {
    whichTestRun();

    $rows = Answers::assert(Occurrences::class, ['order' => 'run'])['result']['rows'];

    $request = Answers::assert(Execution::class, ['execution_id' => $rows[3]['execution_id']]);
    $command = Answers::assert(Execution::class, ['execution_id' => $rows[4]['execution_id']]);

    expect(array_keys($request['result']['header']))->toBe(['type', 'execution_id', 'trace_id', 'source', 'group', 'label', 'outcome', 'started_at', 'duration_ms', 'stages', 'peak_memory_mb', 'user_id', 'deploy', 'server', 'test', 'worker', 'budget'])
        ->and($request['result']['header'])->toMatchArray(['label' => '/orders', 'test' => whichTest('test_lists_orders with data set "paid"'), 'worker' => '2'])
        ->and($command['result']['header'])->toMatchArray(['label' => 'orders:prune', 'test' => whichTest('test_prunes_orders'), 'worker' => '2']);
})->group('process');

it('answers that nothing matched a test that never ran, and still states what it cannot see', function () {
    whichTestRun();

    $listed = Answers::assert(Occurrences::class, ['type' => 'query', 'test' => 'OrdersTest::test_ships_orders']);
    $ranked = Answers::assert(Rank::class, ['type' => 'query', 'test' => 'OrdersTest::test_ships_orders']);
    $run = Answers::assert(Occurrences::class, ['order' => 'run', 'test' => 'OrdersTest::test_ships_orders', 'scope' => 'executions']);
    $requests = Answers::assert(Rank::class, ['type' => 'request', 'test' => whichTest('test_prunes_orders')]);

    $noMatch = fn (string $filters) => [
        'kind' => 'no_match',
        'population' => 11,
        'message' => __('firewatch::messages.no_match', ['population' => 11, 'filters' => $filters]),
    ];

    expect($listed['result'])->toBe([])
        ->and($listed['empty'])->toBe($noMatch('type: query, test: OrdersTest::test_ships_orders'))
        ->and($ranked['empty'])->toBe($noMatch('type: query, test: OrdersTest::test_ships_orders'))
        ->and($run['empty'])->toBe($noMatch('order: run, scope: executions, test: OrdersTest::test_ships_orders'))
        ->and($requests['empty'])->toBe($noMatch('type: request, test: '.whichTest('test_prunes_orders')))
        ->and(array_column($listed['blind_spots'], 'message', 'id'))->toMatchArray([
            'query-bindings-unpaired' => __('firewatch::messages.blind_spots.query-bindings-unpaired'),
        ])->and(array_column($requests['blind_spots'], 'message', 'id'))->toMatchArray([
            'console-requests' => __('firewatch::messages.blind_spots.console-requests'),
            'memory-is-process-peak' => __('firewatch::messages.blind_spots.memory-is-process-peak'),
        ]);
})->group('process');

it('names no test and no worker in a store that holds no test run, and answers as before', function () {
    whichTestBrowsing();

    $run = Answers::assert(Occurrences::class, ['order' => 'run']);
    $rows = $run['result']['rows'];
    $listed = Answers::assert(Occurrences::class, ['type' => 'query']);
    $ranked = Answers::assert(Rank::class, ['type' => 'query']);
    $execution = Answers::assert(Execution::class, ['execution_id' => $rows[0]['execution_id']]);
    $limited = Answers::assert(Occurrences::class, ['type' => 'query', 'test' => whichTest('test_prunes_orders')]);

    expect(array_map(fn (array $row) => [$row['source'], $row['label'], $row['queries'], $row['test'], $row['worker']], $rows))->toBe([
        ['request', '/orders', 1, null, null],
    ])->and($run['window'])->toBe([
        'windowed' => true,
        'basis' => 'started_at',
        'since' => null,
        'until' => null,
        'timezone' => 'UTC',
        'description' => __('firewatch::messages.window_description'),
    ])->and($listed['window'])->toBe($run['window'])
        ->and($ranked['window'])->toBe($run['window'])
        ->and($listed['result']['rows'])->toHaveCount(1)
        ->and($listed['next'])->toBe([
            ['tool' => 'rank', 'arguments' => ['group' => $listed['result']['rows'][0]['group']], 'why' => __('firewatch::messages.occurrences_next_group')],
        ])->and(array_column($ranked['result']['groups'], 'occurrences', 'label'))->toBe(['select ? as status' => 1])
        ->and($ranked['next'][0]['arguments'])->toBe(['group' => $ranked['result']['groups'][0]['group']])
        ->and($execution['result']['header'])->toMatchArray(['label' => '/orders', 'test' => null, 'worker' => null])
        ->and($limited['empty'])->toBe([
            'kind' => 'no_match',
            'population' => 2,
            'message' => __('firewatch::messages.no_match', ['population' => 2, 'filters' => 'type: query, test: '.whichTest('test_prunes_orders')]),
        ]);
});

it('refuses a test that is empty or no string', function (string $tool, array $arguments, mixed $test) {
    $name = __('firewatch::messages.argument_values.test');

    FirewatchServer::tool($tool, [...$arguments, 'test' => $test])->assertHasErrors([__('firewatch::messages.invalid_argument', [
        'argument' => 'test',
        'expected' => $name,
        'value' => json_encode($test),
        'accepted' => $name,
        'example' => app($tool)->name().'(type: "query", test: "Tests\\\\Feature\\\\OrdersTest::it lists orders")',
    ])]);
})->with([
    'an empty one to occurrences' => [Occurrences::class, ['type' => 'query'], ''],
    'a number to occurrences' => [Occurrences::class, ['type' => 'query'], 7],
    'an empty one to a run' => [Occurrences::class, ['order' => 'run'], ''],
    'an empty one to rank' => [Rank::class, ['type' => 'query'], ''],
    'a list to rank' => [Rank::class, ['type' => 'query'], ['OrdersTest']],
]);

it('refuses a test on the tools that read no single test', function () {
    FirewatchServer::tool(Execution::class, ['test' => whichTest('test_prunes_orders')])->assertHasErrors([__('firewatch::messages.inapplicable_argument', [
        'argument' => 'test',
        'tool' => 'execution',
        'accepted' => 'execution_id, type, limit, format',
        'example' => 'execution(format: "json")',
    ])]);
});
