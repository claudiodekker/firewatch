<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\Route;

// More megabytes than any process that runs the tests peaks at.
const MEMORY_UNREACHED_MEGABYTES = 1_048_576;

/**
 * Serve one request. Its peak is the peak of the process that runs the tests, so a test passes the threshold that the peak does or does not reach.
 */
function memoryRequest(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::get('/report', fn () => 'ok');

    test()->get('/report');
}

it('finds the route whose request peaked at the threshold or more, and points at the request', function () {
    memoryRequest();

    $answer = Answers::assert(Detect::class, ['shape' => 'memory', 'threshold' => 1]);
    $finding = $answer['result']['findings'][0];

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($finding['name'])->toBe('/report')
        ->and($finding['evidence'])->toMatchArray(['executions_over' => 1, 'executions' => 1, 'p50_mb' => null])
        ->and($finding['evidence']['peak_mb'])->toBeGreaterThanOrEqual(1)
        ->and($finding['count'])->toBe($finding['evidence']['peak_mb'])
        ->and($finding['worst_execution_id'])->toBeString()->toBe($finding['latest_execution_id']);
});

it('finds nothing in a request that stayed under the threshold, and says it looked at the request', function () {
    memoryRequest();

    $answer = Answers::assert(Detect::class, ['shape' => 'memory', 'threshold' => MEMORY_UNREACHED_MEGABYTES]);

    expect($answer['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
});

it('finds the command that peaked at the threshold or more', function () {
    runArtisan(['command' => 'env']);

    $answer = Answers::assert(Detect::class, ['shape' => 'memory', 'threshold' => 1]);

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($answer['result']['findings'][0]['name'])->toBe('env');
});

it('opens what a finding points at', function () {
    memoryRequest();

    $answer = Answers::assert(Detect::class, ['shape' => 'memory', 'threshold' => 1]);

    expect(array_map(fn (array $call) => Answers::follow($call)['empty'], $answer['next']))->each->toBeNull();
});

it('examines the request in the overview, at the default threshold', function () {
    memoryRequest();

    $row = collect(Answers::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'memory');

    expect($row)->toMatchArray(['examined' => 1])
        ->and($row['verdict'])->toBeIn(['findings', 'clean']);
});

it('states that the peak is the whole process\'s and not attributable to code, on the real request', function () {
    memoryRequest();

    $answer = Answers::assert(Detect::class, ['shape' => 'memory', 'threshold' => 1]);
    $blindSpots = array_column($answer['blind_spots'], 'message', 'id');

    expect($answer['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_memory')])
        ->and($blindSpots)->toHaveKey('memory-is-process-peak')
        ->and($blindSpots['memory-is-process-peak'])->toBe(__('firewatch::messages.blind_spots.memory-is-process-peak'));
});
