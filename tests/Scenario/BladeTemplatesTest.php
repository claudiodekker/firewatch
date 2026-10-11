<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Support\Facades\Artisan;
use Laravel\Nightwatch\Core;

/**
 * Serve one request to a workbench route. A process records one execution, so one request is all a test can serve.
 *
 * Nightwatch reads the testbench skeleton as the application, so this checkout's own vendor frames would be the first application frame of every query, and the skeleton's compiled views would sit under this checkout's vendor. Unless asked to keep the skeleton's layout, Nightwatch is pointed at the checkout and the compiled views at the test's own directory, as an application outside a package has them.
 */
function bladeRequest(string $uri, bool $skeleton = false): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    if (! $skeleton) {
        $compiled = dirname((string) getenv('FIREWATCH_DATABASE')).'/views';
        mkdir($compiled, recursive: true);
        config()->set('view.compiled', realpath($compiled));
        app(Core::class)->sensor->location->setBasePath(dirname(__DIR__, 2));
    }

    test()->get($uri);
}

/**
 * Get the absolute path of a workbench template, as the view finder names it.
 */
function bladeTemplate(string $name): string
{
    return (string) realpath(dirname(__DIR__, 2)."/workbench/resources/views/{$name}.blade.php");
}

/**
 * Get the ids of the blind spots an answer states.
 *
 * @param  array<string, mixed>  $answer
 * @return list<string>
 */
function bladeBlindSpots(array $answer): array
{
    return array_column($answer['blind_spots'], 'id');
}

/**
 * Get the query rows of the n+1 the posts template runs: its call site, its occurrences and their call sites, and its execution's timeline.
 *
 * @return array{site: array<string, mixed>, occurrences: list<array<string, mixed>>, call_sites: list<array<string, mixed>>, timeline: list<array<string, mixed>>, answers: list<array<string, mixed>>}
 */
function bladePostsQueries(): array
{
    $detect = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);
    $execution = $detect['result']['findings'][0]['worst_execution_id'];

    $occurrences = Answers::assert(Occurrences::class, ['execution_id' => $execution, 'type' => 'query']);
    $group = Answers::assert(Occurrences::class, ['group' => $occurrences['result']['rows'][0]['group']]);
    $timeline = Answers::assert(Execution::class, ['execution_id' => $execution]);

    return [
        'site' => $detect['result']['findings'][0]['evidence']['call_sites'][0],
        'occurrences' => $occurrences['result']['rows'],
        'call_sites' => $group['result']['call_sites'],
        'timeline' => array_values(array_filter($timeline['result']['timeline'], fn (array $row) => $row['type'] === 'query')),
        'answers' => [$detect, $occurrences, $group, $timeline],
    ];
}

it('names the template behind the compiled view an n+1 runs from, wherever the query is shown', function () {
    bladeRequest('/posts');

    $queries = bladePostsQueries();
    $site = $queries['site'];
    $location = "{$site['file']}:{$site['line']}";

    expect($site)->toBe(['file' => $site['file'], 'line' => $site['line'], 'template' => bladeTemplate('posts'), 'runs' => 5])
        ->and($site['file'])->toStartWith(config('view.compiled').DIRECTORY_SEPARATOR)
        ->and($queries['call_sites'])->toBe([['location' => $location, 'template' => bladeTemplate('posts'), 'count' => 5]])
        ->and($queries['occurrences'])->toHaveCount(5)
        ->and($queries['timeline'])->not->toBe([]);

    foreach ([...$queries['occurrences'], ...$queries['timeline']] as $row) {
        expect($row['location'])->toBe($location)
            ->and($row['template'])->toBe(bladeTemplate('posts'));
    }

    foreach ($queries['answers'] as $answer) {
        expect(bladeBlindSpots($answer))->toContain('compiled-view-templates');
    }
});

it('keeps the compiled location and says the template could not be resolved once the compiled views are cleared', function () {
    bladeRequest('/posts');
    $compiled = bladePostsQueries()['site'];
    $location = "{$compiled['file']}:{$compiled['line']}";

    Artisan::call('view:clear');

    $queries = bladePostsQueries();

    expect($queries['site'])->toBe([...$compiled, 'template' => null])
        ->and($queries['call_sites'])->toBe([['location' => $location, 'template' => null, 'count' => 5]]);

    foreach ([...$queries['occurrences'], ...$queries['timeline']] as $row) {
        expect($row['location'])->toBe($location)
            ->and($row)->toHaveKey('template', null);
    }

    foreach ($queries['answers'] as $answer) {
        expect(bladeBlindSpots($answer))->toContain('compiled-view-templates');
    }
});

it('shows the template on the frame of an exception thrown inside it', function () {
    bladeRequest('/receipts/7');

    $answer = Answers::assert(Execution::class);
    $exception = $answer['result']['exceptions'][0];
    $frame = $exception['frames'][0];

    expect($exception)->toMatchArray(['class' => RuntimeException::class, 'message' => 'Receipt [7] has no total.'])
        ->and($exception['location'])->toStartWith(nativePath('workbench/resources/views/receipt.blade.php'))
        ->and($exception)->not->toHaveKey('template')
        ->and($frame['file'])->toStartWith(config('view.compiled').DIRECTORY_SEPARATOR)
        ->and($frame['template'])->toBe(bladeTemplate('receipt'))
        ->and(bladeBlindSpots($answer))->toContain('compiled-view-templates');
});

it('resolves a compiled view Nightwatch stored relative to the base path', function () {
    bladeRequest('/receipts/8', skeleton: true);

    $frame = Answers::assert(Execution::class)['result']['exceptions'][0]['frames'][0];

    expect($frame['file'])->toStartWith(nativePath('storage/framework/views/'))
        ->and($frame['template'])->toBe(bladeTemplate('receipt'));
});

it('leaves a location outside the compiled views as it is, without the blind spot', function () {
    bladeRequest('/products');

    $answer = Answers::assert(Detect::class, ['shape' => 'n-plus-one']);
    $site = $answer['result']['findings'][0]['evidence']['call_sites'][0];

    expect($site['file'])->toEndWith(nativePath('workbench/routes/web.php'))
        ->and($site)->not->toHaveKey('template')
        ->and(bladeBlindSpots($answer))->not->toContain('compiled-view-templates');
});
