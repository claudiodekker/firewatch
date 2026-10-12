<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Store\Instant;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * Turn test capture on and register the route that adds an item to an order, failing validation without a quantity, and the command that builds a report.
 */
function auditTestRoutes(): void
{
    setEnvironmentVariable(name: 'FIREWATCH_CAPTURE_TESTS', value: 'true');

    test()->refreshApplication();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::middleware('web')->post('/orders/{order}/items', function (Request $request) {
        DB::select('select ? as member', [1]);
        DB::select('select ? as member', [2]);

        $request->validate(['quantity' => 'required']);

        DB::select('select ? as member', [3]);
        DB::select('select ? as member', [4]);

        foreach (range(1, 3) as $check) {
            Cache::get('team:1:permissions');
        }

        return redirect('/orders');
    });
    Artisan::command('reports:build', function () {
        DB::select('select ? as country', ['NL']);
    });
}

/**
 * Run a feature test that looks up countries before, between and after its requests, adds two items to an order, the second failing validation, and builds a report, which looks up a country too.
 */
function auditTestSetupAndEndpoints(): void
{
    auditTestRoutes();

    foreach (['NL', 'BE', 'DE'] as $country) {
        DB::select('select ? as country', [$country]);
    }

    Cache::get('countries');
    Cache::get('countries');

    test()->post('/orders/1/items', ['quantity' => 2])->assertRedirect('/orders');

    foreach (range(1, 20) as $seed) {
        DB::select('select ? as country', ["C{$seed}"]);
    }

    test()->post('/orders/1/items')->assertStatus(302);
    test()->artisan('reports:build')->assertSuccessful();

    DB::select('select ? as country', ['FR']);
    DB::select('select ? as country', ['IT']);

    Nightwatch::digest();
}

/**
 * Run the same requests and command with no work of the test's own around them.
 */
function auditEndpointsOnly(): void
{
    auditTestRoutes();

    test()->post('/orders/1/items', ['quantity' => 2])->assertRedirect('/orders');
    test()->post('/orders/1/items')->assertStatus(302);
    test()->artisan('reports:build')->assertSuccessful();

    Nightwatch::digest();
}

/**
 * Count the rows of an occurrences answer by the source and name they carry.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, int>
 */
function auditRowsBySourceAndName(array $answer): array
{
    $counts = array_count_values(array_map(fn (array $row) => "{$row['source']} {$row['name']}", $answer['result']['rows']));

    ksort($counts);

    return $counts;
}

it('lists a test\'s setup queries apart from the queries its requests and command ran', function () {
    auditTestSetupAndEndpoints();

    $between = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'between', 'limit' => 100]);
    $executions = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'executions', 'limit' => 100]);
    $all = Answers::assert(Occurrences::class, ['type' => 'query', 'limit' => 100]);

    expect(auditRowsBySourceAndName($between))->toBe([
        'between select ? as country' => 25,
    ])->and(auditRowsBySourceAndName($executions))->toBe([
        'command select ? as country' => 1,
        'request select ? as member' => 6,
    ])->and(auditRowsBySourceAndName($all))->toBe([
        'between select ? as country' => 25,
        'command select ? as country' => 1,
        'request select ? as member' => 6,
    ])->and($between['window'])->toMatchArray(['since' => null, 'until' => null, 'scope' => 'between'])
        ->and($executions['window'])->toMatchArray(['scope' => 'executions'])
        ->and($all['window'])->not->toHaveKey('scope')
        ->and(array_column($between['result']['rows'], 'user_id'))->each->toBeNull()
        ->and(array_column($between['result']['rows'], 'stage'))->each->toBeNull();
});

it('lists the same records under either scope that keeps a store\'s executions when the test ran nothing of its own', function () {
    auditEndpointsOnly();

    $executions = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'executions']);
    $all = Answers::assert(Occurrences::class, ['type' => 'query']);
    $between = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'between']);

    expect($executions['result'])->toBe($all['result'])
        ->and(auditRowsBySourceAndName($all))->toBe([
            'command select ? as country' => 1,
            'request select ? as member' => 6,
        ])->and($between['result'])->toBe([])
        ->and($between['empty'])->toBe([
            'kind' => 'no_match',
            'population' => 13,
            'message' => __('firewatch::messages.no_match', ['population' => 13, 'filters' => 'type: query, scope: between']),
        ]);
});

it('refuses to list requests between a test\'s executions, where none can be', function () {
    auditTestSetupAndEndpoints();

    $response = FirewatchServer::tool(Occurrences::class, ['type' => 'request', 'scope' => 'between']);

    $response->assertHasErrors([__('firewatch::messages.conflicting_arguments', [
        'argument' => 'scope',
        'with' => 'request',
        'accepted' => __('firewatch::messages.argument_values.scope_holding'),
        'example' => 'occurrences(type: "query", scope: "between")',
    ])]);
});

/**
 * Get the rows of an overview's slowest list by type, label and side, with their occurrences, in a fixed order.
 *
 * @param  array<string, mixed>  $answer
 * @return list<string>
 */
function auditSlowest(array $answer): array
{
    $rows = array_map(fn (array $row) => "{$row['type']} {$row['label']} ".($row['side'] ?? '-')." {$row['occurrences']}", $answer['result']['slowest_by_total_time']);

    sort($rows);

    return $rows;
}

it('totals a test\'s setup apart from its endpoints and labels each side of a group in the slowest list', function () {
    auditTestSetupAndEndpoints();

    $overview = Answers::assert(Overview::class);
    $sides = $overview['result']['sides'];

    expect(array_keys($overview['result']))->toBe(['error_rate', 'sides', 'slowest_by_total_time', 'records', 'records_by_type', 'user_directory', 'actors', 'budgets', 'detectors'])
        ->and(array_map(fn (array $side) => [$side['records'], $side['queries']], $sides))->toBe([
            'executions' => [13, 7],
            'between' => [27, 25],
        ])->and($sides['executions']['query_ms'])->toBeFloat()->toBeGreaterThan(0)
        ->and($sides['between']['query_ms'])->toBeFloat()->toBeGreaterThan(0)
        ->and(auditSlowest($overview))->toBe([
            'cache-event countries between 2',
            'cache-event team:1:permissions executions 3',
            'command reports:build executions 1',
            'query select ? as country between 25',
            'query select ? as country executions 1',
            'query select ? as member executions 6',
            'request /orders/{order}/items executions 2',
        ])->and($overview['result']['records'])->toBe(40);
});

it('labels each side of a group in the block of a deploy too, and neither side when the overview is scoped', function () {
    auditTestSetupAndEndpoints();

    $blocks = Answers::assert(Overview::class, ['by_deploy' => true])['result']['slowest_by_total_time'];
    $scoped = Answers::assert(Overview::class, ['by_deploy' => true, 'scope' => 'between'])['result']['slowest_by_total_time'];

    expect($blocks)->toHaveCount(1)
        ->and(auditSlowest(['result' => ['slowest_by_total_time' => $blocks[0]['groups']]]))->toBe([
            'cache-event countries between 2',
            'cache-event team:1:permissions executions 3',
            'command reports:build executions 1',
            'query select ? as country between 25',
            'query select ? as country executions 1',
            'query select ? as member executions 6',
            'request /orders/{order}/items executions 2',
        ])->and(array_keys($blocks[0]['groups'][0]))->toBe(['type', 'group', 'label', 'side', 'occurrences', 'total_ms', 'avg_ms'])
        ->and($scoped)->toHaveCount(1)
        ->and(auditSlowest(['result' => ['slowest_by_total_time' => $scoped[0]['groups']]]))->toBe([
            'cache-event countries - 2',
            'query select ? as country - 25',
        ]);
});

it('reads only a test\'s setup, or only its endpoints, when the overview is scoped', function () {
    auditTestSetupAndEndpoints();

    $between = Answers::assert(Overview::class, ['scope' => 'between']);
    $executions = Answers::assert(Overview::class, ['scope' => 'executions']);

    expect($between['result'])->not->toHaveKey('sides')
        ->and($between['result']['records'])->toBe(27)
        ->and(array_column($between['result']['records_by_type'], 'records', 'type'))->toMatchArray(['request' => 0, 'command' => 0, 'query' => 25, 'cache-event' => 2])
        ->and($between['result']['error_rate']['requests'])->toBe(0)
        ->and($between['result']['actors'])->toBe(['executions' => 0, 'signed_in_actors' => 0, 'without_actor' => 0])
        ->and(auditSlowest($between))->toBe([
            'cache-event countries - 2',
            'query select ? as country - 25',
        ])->and($executions['result'])->not->toHaveKey('sides')
        ->and($executions['result']['records'])->toBe(13)
        ->and(auditSlowest($executions))->toBe([
            'cache-event team:1:permissions - 3',
            'command reports:build - 1',
            'query select ? as country - 1',
            'query select ? as member - 6',
            'request /orders/{order}/items - 2',
        ]);
});

it('answers an overview without sides when the test ran nothing of its own', function () {
    auditEndpointsOnly();

    $overview = Answers::assert(Overview::class);
    $executions = Answers::assert(Overview::class, ['scope' => 'executions']);
    $between = Answers::assert(Overview::class, ['scope' => 'between']);

    expect(array_keys($overview['result']))->toBe(['error_rate', 'slowest_by_total_time', 'records', 'records_by_type', 'user_directory', 'actors', 'budgets', 'detectors'])
        ->and(auditSlowest($overview))->toBe([
            'cache-event team:1:permissions - 3',
            'command reports:build - 1',
            'query select ? as country - 1',
            'query select ? as member - 6',
            'request /orders/{order}/items - 2',
        ])->and($executions['result'])->toBe($overview['result'])
        ->and($between['result'])->toBe([])
        ->and($between['empty'])->toBe([
            'kind' => 'no_match',
            'population' => 13,
            'message' => __('firewatch::messages.no_match', ['population' => 13, 'filters' => 'scope: between']),
        ]);
});

/**
 * Get the verdict of each shape of a detect answer, with its reason when it has one.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, string>
 */
function auditVerdicts(array $answer): array
{
    return array_column(array_map(fn (array $judgement) => [
        'detector' => $judgement['detector'],
        'verdict' => trim("{$judgement['verdict']} ".($judgement['reason'] ?? '')),
    ], $answer['result']['detectors']), 'verdict', 'detector');
}

it('judges a test\'s setup only with the shapes that read records wherever they ran', function () {
    auditTestSetupAndEndpoints();

    $between = Answers::assert(Detect::class, ['scope' => 'between']);
    $cache = Answers::assert(Detect::class, ['shape' => 'cache', 'scope' => 'between']);
    $nPlusOne = Answers::assert(Detect::class, ['shape' => 'n-plus-one', 'scope' => 'between']);

    expect(auditVerdicts($between))->toBe([
        'n-plus-one' => 'not_evaluated no_executions_in_scope',
        'database-bound' => 'not_evaluated no_executions_in_scope',
        'failing-routes' => 'not_evaluated no_executions_in_scope',
        'failing-jobs' => 'not_evaluated no_executions_in_scope',
        'queue-latency' => 'not_evaluated no_executions_in_scope',
        'failing-tasks' => 'not_evaluated no_executions_in_scope',
        'exception-clusters' => 'not_evaluated no_executions_in_scope',
        'error-logs' => 'not_evaluated no_records',
        'failing-http' => 'not_evaluated no_records',
        'cache' => 'clean',
        'memory' => 'not_evaluated no_executions_in_scope',
    ])->and($cache['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0])
        ->and($nPlusOne['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_executions_in_scope', 'examined' => 0, 'total' => 0, 'findings' => []])
        ->and($nPlusOne['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', [
            'detector' => 'n-plus-one',
            'total' => 0,
            'examined' => 0,
            'input' => __('firewatch::messages.detect_input.n-plus-one'),
            'reason' => 'no_executions_in_scope',
        ]));
});

it('judges a test\'s endpoints without its setup', function () {
    auditTestSetupAndEndpoints();

    $cache = Answers::assert(Detect::class, ['shape' => 'cache', 'scope' => 'executions']);
    $everywhere = Answers::assert(Detect::class, ['shape' => 'cache']);
    $nPlusOne = Answers::assert(Detect::class, ['shape' => 'n-plus-one', 'scope' => 'executions']);

    expect($cache['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 1])
        ->and($cache['result']['findings'][0]['name'])->toBe('team:1:permissions')
        ->and($everywhere['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 5, 'total' => 1])
        ->and($nPlusOne['result'])->toBe(Answers::assert(Detect::class, ['shape' => 'n-plus-one'])['result'])
        ->and(array_column($cache['next'], 'arguments'))->toContain(['group' => $cache['result']['findings'][0]['group'], 'scope' => 'executions']);
});

/**
 * Get the group id of the only group of an execution type.
 */
function auditExecutionGroup(string $type): string
{
    return Answers::assert(Rank::class, ['type' => $type])['result']['groups'][0]['group'];
}

/**
 * Get the rows of a ranking by label, keeping the fields a ranking within an execution group adds.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, array<string, mixed>>
 */
function auditWithinRows(array $answer): array
{
    $rows = [];

    foreach ($answer['result']['groups'] as $row) {
        $rows[$row['label']] = array_intersect_key($row, array_flip(['occurrences', 'per_execution', 'executions_affected', 'call_sites']));
    }

    return $rows;
}

it('ranks the queries and cache events one route ran, per request', function () {
    auditTestSetupAndEndpoints();

    $route = auditExecutionGroup('request');
    $location = Answers::assert(Occurrences::class, ['type' => 'query', 'scope' => 'executions'])['result']['rows'][0]['location'];

    $queries = Answers::assert(Rank::class, ['type' => 'query', 'within' => $route, 'by' => 'occurrences']);
    $cache = Answers::assert(Rank::class, ['type' => 'cache-event', 'within' => $route]);

    expect($queries['result']['within'])->toBe(['group' => $route, 'type' => 'request', 'label' => '/orders/{order}/items', 'executions' => 2])
        ->and($queries['result']['records'])->toBe(6)
        ->and(auditWithinRows($queries))->toEqual([
            'select ? as member' => [
                'occurrences' => 6,
                'per_execution' => ['avg' => 3, 'max' => 4],
                'executions_affected' => 2,
                'call_sites' => [['location' => $location, 'count' => 6]],
            ],
        ])->and($cache['result']['within'])->toBe(['group' => $route, 'type' => 'request', 'label' => '/orders/{order}/items', 'executions' => 2])
        ->and(auditWithinRows($cache))->toEqual([
            'team:1:permissions' => [
                'occurrences' => 3,
                'per_execution' => ['avg' => 1.5, 'max' => 3],
                'executions_affected' => 1,
            ],
        ]);
});

it('lists the three call sites that ran a query most inside a route, most frequent first', function () {
    auditTestRoutes();

    // Nightwatch reads the testbench skeleton as the application, so every call site would be a vendor line.
    app(Core::class)->sensor->location->setBasePath(dirname(__DIR__, 2));
    Route::get('/reports', function () {
        DB::select('select ? as report', [1]);

        foreach (range(1, 2) as $run) {
            DB::select('select ? as report', [2]);
        }

        foreach (range(1, 3) as $run) {
            DB::select('select ? as report', [3]);
        }

        foreach (range(1, 4) as $run) {
            DB::select('select ? as report', [4]);
        }
    });

    test()->get('/reports')->assertOk();
    Nightwatch::digest();

    $query = Answers::assert(Rank::class, ['type' => 'query', 'within' => auditExecutionGroup('request')])['result']['groups'][0];
    $lines = array_map(fn (array $site) => (int) Str::afterLast($site['location'], ':'), $query['call_sites']);

    expect($query['occurrences'])->toBe(10)
        ->and(array_column($query['call_sites'], 'count'))->toBe([4, 3, 2])
        ->and($lines)->toBe([$lines[0], $lines[0] - 4, $lines[0] - 8]);
});

it('ranks the queries a command ran apart from the same query in the test\'s setup', function () {
    auditTestSetupAndEndpoints();

    $command = auditExecutionGroup('command');

    $within = Answers::assert(Rank::class, ['type' => 'query', 'within' => $command]);
    $between = Answers::assert(Rank::class, ['type' => 'query', 'scope' => 'between']);
    $all = Answers::assert(Rank::class, ['type' => 'query', 'by' => 'occurrences']);

    expect($within['result']['within'])->toBe(['group' => $command, 'type' => 'command', 'label' => 'reports:build', 'executions' => 1])
        ->and(auditWithinRows($within))->toEqual([
            'select ? as country' => [
                'occurrences' => 1,
                'per_execution' => ['avg' => 1, 'max' => 1],
                'executions_affected' => 1,
                'call_sites' => $within['result']['groups'][0]['call_sites'],
            ],
        ])->and(array_column($between['result']['groups'], 'occurrences', 'label'))->toBe(['select ? as country' => 25])
        ->and(array_column($all['result']['groups'], 'occurrences', 'label'))->toBe(['select ? as country' => 26, 'select ? as member' => 6])
        ->and($all['result'])->not->toHaveKey('within');
});

it('answers that a route ran nothing in a window before its requests', function () {
    auditTestSetupAndEndpoints();

    $route = auditExecutionGroup('request');
    $first = min(array_column(Answers::assert(Occurrences::class, ['type' => 'request'])['result']['rows'], 'started_at'));

    $answer = Answers::assert(Rank::class, ['type' => 'query', 'within' => $route, 'until' => Instant::epoch($first)]);

    expect($answer['result'])->toBe([])
        ->and($answer['empty']['kind'])->toBe('no_match')
        ->and($answer['empty']['message'])->toBe(__('firewatch::messages.no_match', [
            'population' => $answer['empty']['population'],
            'filters' => "type: query, within: {$route}",
        ]))->and($answer['empty']['population'])->toBe(5);
});

it('refuses to rank within a group that is no execution\'s, or alongside what does not fit', function () {
    auditTestSetupAndEndpoints();

    $route = auditExecutionGroup('request');
    $query = Answers::assert(Rank::class, ['type' => 'query'])['result']['groups'][0]['group'];
    $executionGroup = __('firewatch::messages.argument_values.execution_group');
    $example = 'rank(type: "query", within: "<group id>")';

    FirewatchServer::tool(Rank::class, ['type' => 'query', 'within' => $query])->assertHasErrors([
        __('firewatch::messages.invalid_argument', ['argument' => 'within', 'expected' => $executionGroup, 'value' => json_encode($query), 'accepted' => $executionGroup, 'example' => $example]),
    ]);
    FirewatchServer::tool(Rank::class, ['type' => 'request', 'within' => $route])->assertHasErrors([
        __('firewatch::messages.conflicting_arguments', ['argument' => 'within', 'with' => 'request', 'accepted' => __('firewatch::messages.argument_values.within_type'), 'example' => $example]),
    ]);
    FirewatchServer::tool(Rank::class, ['type' => 'query', 'within' => $route, 'scope' => 'between'])->assertHasErrors([
        __('firewatch::messages.conflicting_arguments', ['argument' => 'scope', 'with' => 'within', 'accepted' => __('firewatch::messages.argument_values.within_scope'), 'example' => $example]),
    ]);
    FirewatchServer::tool(Rank::class, ['group' => $route, 'within' => $route])->assertHasErrors([
        __('firewatch::messages.conflicting_arguments', ['argument' => 'within', 'with' => 'group', 'accepted' => 'a call with `type` and `within`', 'example' => 'rank(group: "<group id>")']),
    ]);
    FirewatchServer::tool(Rank::class, ['type' => 'request', 'scope' => 'between'])->assertHasErrors([
        __('firewatch::messages.conflicting_arguments', ['argument' => 'scope', 'with' => 'request', 'accepted' => __('firewatch::messages.argument_values.scope_holding'), 'example' => 'rank(type: "query", scope: "between")']),
    ]);
});

/**
 * Get the rows of a run as its source, label, outcome, queries and other records, leaving out what the clock decides.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function auditRun(array $rows): array
{
    return array_map(fn (array $row) => trim("{$row['source']} {$row['label']} {$row['outcome']}")." | {$row['queries']} queries, {$row['other']} other", $rows);
}

it('lists a test run in order, the work between its executions included', function () {
    auditTestSetupAndEndpoints();

    $run = Answers::assert(Occurrences::class, ['order' => 'run']);
    $rows = $run['result']['rows'];

    expect($run['result']['order'])->toBe('run')
        ->and(auditRun($rows))->toBe([
            'between | 3 queries, 2 other',
            'request /orders/{order}/items 302 | 4 queries, 3 other',
            'between | 20 queries, 0 other',
            'request /orders/{order}/items 302 | 2 queries, 0 other',
            'command reports:build 0 | 1 queries, 0 other',
            'between | 2 queries, 0 other',
        ])->and(array_keys($rows[0]))->toBe(['started_at', 'source', 'type', 'execution_id', 'label', 'outcome', 'duration_ms', 'queries', 'query_ms', 'other'])
        ->and(array_column($rows, 'type'))->toBe([null, 'request', null, 'request', 'command', null])
        ->and(array_map(fn (array $row) => $row['duration_ms'] === null, $rows))->toBe([true, false, true, false, false, true])
        ->and(array_unique(array_column($rows, 'execution_id')))->toHaveCount(6)
        ->and(array_column($rows, 'started_at'))->toBe(collect($rows)->pluck('started_at')->sort()->values()->all())
        ->and($run['summary'])->toBe(trans_choice('firewatch::messages.occurrences_run_summary', 6, ['count' => 6]))
        ->and($run['next'])->toBe([
            ['tool' => 'execution', 'arguments' => ['execution_id' => $rows[1]['execution_id']], 'why' => __('firewatch::messages.occurrences_next_run_execution')],
            ['tool' => 'occurrences', 'arguments' => ['execution_id' => $rows[0]['execution_id']], 'why' => __('firewatch::messages.occurrences_next_run_between')],
        ])->and(auditRowsBySourceAndName(Answers::follow($run['next'][1])))->toBe([
            'between countries' => 2,
            'between select ? as country' => 3,
        ]);
});

it('windows, scopes and pages a test run', function () {
    auditTestSetupAndEndpoints();

    $rows = Answers::assert(Occurrences::class, ['order' => 'run'])['result']['rows'];

    $since = Answers::assert(Occurrences::class, ['order' => 'run', 'since' => Instant::epoch($rows[3]['started_at'])]);
    $until = Answers::assert(Occurrences::class, ['order' => 'run', 'until' => Instant::epoch($rows[3]['started_at'])]);
    $between = Answers::assert(Occurrences::class, ['order' => 'run', 'scope' => 'between']);
    $executions = Answers::assert(Occurrences::class, ['order' => 'run', 'scope' => 'executions']);
    $first = Answers::assert(Occurrences::class, ['order' => 'run', 'limit' => 4]);

    preg_match('/cursor: "([^"]+)"/', $first['truncated'][0]['how'], $cursor);

    $second = Answers::assert(Occurrences::class, ['order' => 'run', 'limit' => 4, 'cursor' => $cursor[1]]);

    expect($since['result']['rows'])->toBe(array_slice($rows, 3))
        ->and($until['result']['rows'])->toBe(array_slice($rows, 0, 3))
        ->and($between['result']['rows'])->toBe([$rows[0], $rows[2], $rows[5]])
        ->and($executions['result']['rows'])->toBe([$rows[1], $rows[3], $rows[4]])
        ->and($first['result']['rows'])->toBe(array_slice($rows, 0, 4))
        ->and($first['truncated'])->toHaveCount(1)
        ->and($first['truncated'][0])->toMatchArray(['section' => 'rows', 'shown' => 4, 'reason' => 'limit'])
        ->and($second['result']['rows'])->toBe(array_slice($rows, 4))
        ->and($second['truncated'])->toBe([]);
});

it('lists only executions in the run of a test that ran nothing of its own, and nothing between them', function () {
    auditEndpointsOnly();

    $run = Answers::assert(Occurrences::class, ['order' => 'run']);
    $between = Answers::assert(Occurrences::class, ['order' => 'run', 'scope' => 'between']);
    $before = Answers::assert(Occurrences::class, ['order' => 'run', 'until' => Instant::epoch($run['result']['rows'][0]['started_at'])]);

    expect(auditRun($run['result']['rows']))->toBe([
        'request /orders/{order}/items 302 | 4 queries, 3 other',
        'request /orders/{order}/items 302 | 2 queries, 0 other',
        'command reports:build 0 | 1 queries, 0 other',
    ])->and($between['result'])->toBe([])
        ->and($between['empty'])->toBe([
            'kind' => 'no_match',
            'population' => 13,
            'message' => __('firewatch::messages.no_match', ['population' => 13, 'filters' => 'order: run, scope: between']),
        ])->and($before['empty'])->toBe([
            'kind' => 'window_empty',
            'population' => 13,
            'message' => __('firewatch::messages.window_empty', ['population' => 13]),
        ]);
});

it('refuses a selector or a filter on a run', function (array $arguments, string $argument) {
    FirewatchServer::tool(Occurrences::class, ['order' => 'run', ...$arguments])->assertHasErrors([__('firewatch::messages.conflicting_arguments', [
        'argument' => $argument,
        'with' => 'order',
        'accepted' => __('firewatch::messages.occurrences_run_accepted'),
        'example' => 'occurrences(order: "run")',
    ])]);
})->with([
    'a type' => [['type' => 'query'], 'type'],
    'an execution id' => [['execution_id' => 'abc'], 'execution_id'],
    'a filter' => [['matching' => 'orders'], 'matching'],
    'a deploy' => [['deploy' => 'v1'], 'deploy'],
]);

it('opens an execution of the run by the start of its id, and says a between section is none', function () {
    auditTestSetupAndEndpoints();

    $rows = Answers::assert(Occurrences::class, ['order' => 'run'])['result']['rows'];
    $request = $rows[1]['execution_id'];
    $section = $rows[0]['execution_id'];

    $full = Answers::assert(Execution::class, ['execution_id' => $request]);
    $prefixed = Answers::assert(Execution::class, ['execution_id' => substr($request, 0, 8)]);

    $refusal = fn (string $id) => __('firewatch::messages.execution_between_section', [
        'id' => $id,
        'example' => 'execution(execution_id: "<execution id>")',
    ]);

    expect($prefixed['result'])->toBe($full['result'])
        ->and($prefixed['notes'])->toBe([
            ...$full['notes'],
            __('firewatch::messages.execution_prefix_matched', ['prefix' => substr($request, 0, 8), 'id' => $request]),
        ]);

    FirewatchServer::tool(Execution::class, ['execution_id' => $section])->assertHasErrors([$refusal($section)]);
    FirewatchServer::tool(Execution::class, ['execution_id' => substr($section, 0, 8)])->assertHasErrors([$refusal($section)]);

    expect(Answers::assert(Occurrences::class, ['execution_id' => $section])['result']['rows'])->toHaveCount(5);
});
