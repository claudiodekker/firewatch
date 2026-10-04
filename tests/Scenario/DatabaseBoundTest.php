<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

const DATABASE_BOUND_QUERY = 'with recursive counter(n) as (select 1 union all select n + 1 from counter where n < 200000) select count(*) as total from counter';

/**
 * Serve one request that spends a good part of its time in one query. A process records one execution, so one request is all a test can serve.
 */
function databaseBoundRequest(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/report', function () {
        DB::select(DATABASE_BOUND_QUERY);

        return 'ok';
    });

    test()->get('/report');
}

it('finds the route whose request spent its time in a query, and names the query', function () {
    databaseBoundRequest();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'database-bound', 'threshold' => 1]);
    $finding = $envelope['result']['findings'][0];

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($finding['name'])->toBe('/report')
        ->and($finding['evidence'])->toMatchArray(['requests' => 1, 'basis' => 'aggregate'])
        ->and($finding['evidence']['share_pct'])->toBeGreaterThan(1)
        ->and($finding['evidence']['top_queries'][0])->toMatchArray(['sql' => DATABASE_BOUND_QUERY, 'calls' => 1]);
});

it('opens what a finding points at', function () {
    databaseBoundRequest();

    $envelope = Envelope::assert(Detect::class, ['shape' => 'database-bound', 'threshold' => 1]);

    foreach ($envelope['next'] as $call) {
        $tool = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class][$call['tool']];

        expect(Envelope::assert($tool, $call['arguments'])['empty'])->toBeNull();
    }
});

it('examines the request in the overview, at the default threshold', function () {
    databaseBoundRequest();

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'database-bound');

    expect($row)->toMatchArray(['examined' => 1])
        ->and($row['verdict'])->toBeIn(['findings', 'clean']);
});
