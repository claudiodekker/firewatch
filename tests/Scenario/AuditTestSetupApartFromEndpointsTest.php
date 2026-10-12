<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
