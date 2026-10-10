<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Serve the requests in a fresh application: an order list that works, a report that throws, a checkout whose payment dependency answers 503 and an import that logs an error.
 *
 * @param  list<string>  $uris
 */
function whatsWrongRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Http::fake(['https://payments.example.com/*' => Http::response('unavailable', 503)]);

    Route::get('/orders', function () {
        DB::select('select 1');

        return 'ok';
    });
    Route::get('/reports', fn () => abort(500));
    Route::get('/checkout', function () {
        Http::post('https://payments.example.com/charges');

        return 'ok';
    });
    Route::get('/imports', function () {
        Log::error('The import of orders.csv stopped at row 12.');

        return 'ok';
    });

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

it('says what is wrong now: the error rate, the slowest groups, every count and the actors, then the shapes with findings first', function () {
    whatsWrongRequests(['/orders', '/reports', '/checkout', '/imports', '/nowhere']);
    test()->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']))->get('/orders');

    $envelope = Envelope::assert(Overview::class);
    $result = $envelope['result'];
    $counts = array_column($result['records_by_type'], 'records', 'type');
    $verdicts = array_column($result['detectors'], 'verdict', 'detector');
    $withFindings = array_keys($verdicts, 'findings', true);

    expect(array_keys($result))->toBe(['error_rate', 'slowest_by_total_time', 'records', 'records_by_type', 'user_directory', 'actors', 'budgets', 'detectors'])
        ->and($result['error_rate'])->toBe(['requests' => 6, 'with_status' => 6, 'server_errors' => 1, 'server_error_pct' => 16.7, 'client_errors' => 1, 'client_error_pct' => 16.7])
        ->and(array_keys($counts))->toBe(['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'])
        ->and($counts)->toMatchArray(['request' => 6, 'command' => 0, 'job-attempt' => 0, 'scheduled-task' => 0, 'query' => 2, 'log' => 1, 'mail' => 0, 'notification' => 0, 'outgoing-request' => 1, 'queued-job' => 0])
        ->and($result['records'])->toBe(array_sum($counts))
        ->and($result['user_directory'])->toBe(1)
        ->and($result['actors'])->toBe(['executions' => 6, 'signed_in_actors' => 1, 'without_actor' => 5])
        ->and($verdicts)->toMatchArray(['failing-routes' => 'findings', 'failing-http' => 'findings', 'error-logs' => 'findings', 'failing-jobs' => 'not_evaluated'])
        ->and(array_slice(array_keys($verdicts), 0, count($withFindings)))->toBe($withFindings)
        ->and($envelope['summary'])->toStartWith(explode(':shapes', __('firewatch::messages.overview_detectors_findings'))[0])
        ->and($envelope['summary'])->toEndWith(__('firewatch::messages.overview_summary', ['records' => $result['records'], 'with_status' => 6, 'server_errors' => 1, 'client_errors' => 1]))
        ->and($envelope['summary'])->not->toContain(explode(':', __('firewatch::messages.overview_detectors_clean'))[0])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial')
        ->and($envelope['notes'])->toBe([__('firewatch::messages.overview_budgets_unevaluated', ['reason' => 'no_budget_configured', 'ignored' => ''])]);
});

it('lists the groups that took the most time with their type, at most three of a type, and none without a duration', function () {
    whatsWrongRequests(['/orders', '/orders', '/reports', '/checkout', '/imports', '/nowhere']);

    $slowest = Envelope::assert(Overview::class)['result']['slowest_by_total_time'];
    $perType = array_count_values(array_column($slowest, 'type'));
    $orders = collect($slowest)->firstWhere('label', '/orders');

    expect($perType)->toMatchArray(['request' => 3, 'query' => 1, 'outgoing-request' => 1])
        ->and(array_keys($perType))->not->toContain('exception', 'log')
        ->and(count($slowest))->toBeLessThanOrEqual(10)
        ->and($orders)->toMatchArray(['type' => 'request', 'occurrences' => 2])
        ->and(collect($slowest)->firstWhere('type', 'query'))->toMatchArray(['label' => 'select 1', 'occurrences' => 2])
        ->and(collect($slowest)->firstWhere('type', 'outgoing-request'))->toMatchArray(['label' => 'payments.example.com', 'occurrences' => 1])
        ->and(array_column($slowest, 'total_ms'))->each->toBeNumeric()
        ->and(array_column($slowest, 'total_ms'))->toBe(collect($slowest)->sortByDesc('total_ms')->pluck('total_ms')->all());
});

it('offers a call for each shape with findings first, and every call it offers runs', function () {
    whatsWrongRequests(['/orders', '/reports', '/checkout', '/imports']);

    $envelope = Envelope::assert(Overview::class);
    $withFindings = array_keys(array_column($envelope['result']['detectors'], 'verdict', 'detector'), 'findings', true);
    $shapes = array_column(array_column(array_filter($envelope['next'], fn (array $call) => $call['tool'] === 'detect'), 'arguments'), 'shape');

    expect($shapes)->toBe(array_slice($withFindings, 0, 5))
        ->and(count($shapes))->toBeGreaterThanOrEqual(3)
        ->and(array_slice(array_column($envelope['next'], 'tool'), count($shapes)))->toBe(array_slice(['rank', 'execution'], 0, 5 - count($shapes)));

    foreach ($envelope['next'] as $call) {
        $answer = Envelope::follow($call);

        expect($answer['empty'])->toBeNull()
            ->and($answer['result']['verdict'] ?? 'findings')->toBe('findings');
    }
});

it('offers calls that read the same window when they run later, and every one of them runs', function () {
    whatsWrongRequests(['/orders', '/reports', '/nowhere']);
    $span = storeRows('SELECT min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];
    $window = ['since' => floor($span['oldest']), 'until' => ceil($span['newest']) + 1];

    $envelope = Envelope::assert(Overview::class, $window);
    $this->travel(2)->hours();
    $labels = array_column(array_filter($envelope['result']['slowest_by_total_time'], fn (array $row) => $row['type'] === 'request'), 'label');
    $tools = array_column($envelope['next'], 'tool');

    expect($envelope['result']['records'])->toBe($envelope['coverage']['records'])
        ->and($labels)->toEqualCanonicalizing(['/orders', '/reports', __('firewatch::messages.rank_no_route')])
        ->and($tools)->toContain('detect', 'rank', 'execution')
        ->and($envelope['notes'])->toBe([__('firewatch::messages.overview_budgets_unevaluated', ['reason' => 'no_budget_configured', 'ignored' => '']), __('firewatch::messages.overview_directory_unwindowed')]);

    foreach ($envelope['next'] as $call) {
        $answer = Envelope::follow($call);

        expect($answer['empty'])->toBeNull();

        if ($call['tool'] !== 'execution') {
            expect($call['arguments'])->toMatchArray($window)
                ->and($answer['window'])->toMatchArray($window);
        }
    }

    $shape = Envelope::follow($envelope['next'][array_search('detect', $tools, true)]);
    $ranked = Envelope::follow($envelope['next'][array_search('rank', $tools, true)]);
    $opened = Envelope::follow($envelope['next'][array_search('execution', $tools, true)]);

    expect($shape['result']['verdict'])->toBe('findings')
        ->and(array_sum(array_column($ranked['result']['groups'], 'occurrences')))->toBeGreaterThanOrEqual(1)
        ->and($opened['result']['header']['execution_id'])->toBe($envelope['next'][array_search('execution', $tools, true)]['arguments']['execution_id']);
});

it('is clean only over what a shape examined, and never says no findings while a shape was not evaluated', function () {
    whatsWrongRequests(['/orders', '/orders']);

    $envelope = Envelope::assert(Overview::class);
    $rows = collect($envelope['result']['detectors'])->keyBy('detector');
    $notEvaluated = $rows->where('verdict', 'not_evaluated')->keys()->all();
    $routes = Envelope::assert(Detect::class, ['shape' => 'failing-routes']);
    $jobs = Envelope::assert(Detect::class, ['shape' => 'failing-jobs']);

    expect($rows['failing-routes'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0, 'worst' => null])
        ->and($rows['failing-jobs'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
        ->and($notEvaluated)->toContain('failing-jobs', 'queue-latency', 'failing-tasks', 'error-logs', 'failing-http', 'cache')
        ->and($envelope['summary'])->toContain(__('firewatch::messages.overview_detectors_not_evaluated', ['shapes' => implode(', ', $notEvaluated)]))
        ->and($envelope['summary'])->not->toContain(explode(':', __('firewatch::messages.overview_detectors_clean'))[0])
        ->and($envelope['result']['error_rate'])->toBe(['requests' => 2, 'with_status' => 2, 'server_errors' => 0, 'server_error_pct' => 0, 'client_errors' => 0, 'client_error_pct' => 0])
        ->and($routes['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0])
        ->and($jobs['result'])->toMatchArray(['verdict' => 'not_evaluated', 'examined' => 0]);

    foreach ($envelope['next'] as $call) {
        expect(Envelope::follow($call)['empty'])->toBeNull();
    }

    expect(array_slice(array_column($envelope['next'], 'tool'), -2))->toBe(['rank', 'execution']);
});

it('answers nothing for a window that holds no record, with why and what it cannot see', function () {
    whatsWrongRequests(['/orders', '/reports']);
    $this->travel(2)->hours();

    $envelope = Envelope::assert(Overview::class, ['since' => '-30m']);
    $shape = Envelope::assert(Detect::class, ['shape' => 'failing-routes', 'since' => '-30m']);

    expect($envelope['empty'])->toMatchArray(['kind' => 'window_empty', 'message' => __('firewatch::messages.window_empty', ['population' => $envelope['empty']['population']])])
        ->and($envelope['empty']['population'])->toBe($envelope['coverage']['records'])->toBeGreaterThanOrEqual(2)
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.window_empty'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial', 'console-requests', 'values-truncated')
        ->and($shape['empty']['kind'])->toBe('window_empty')
        ->and($shape['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0]);
});
