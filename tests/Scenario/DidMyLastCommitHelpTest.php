<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use ClaudioDekker\Firewatch\Tests\Support\GitRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;

// A request's boot on the Windows runner can stall for over 300 ms, and the slowed route must still dominate it.
const LAST_COMMIT_SLOWDOWN_MS = 500;

/**
 * Serve each URI in an application of its own whose git lookup starts at a directory, with the order list taking at least the given time.
 *
 * @param  list<string>  $uris
 */
function lastCommitServe(string $start, int $ordersMs, array $uris): void
{
    test()->gitStart = $start;

    $started = $_SERVER['REQUEST_TIME_FLOAT'];
    test()->beforeApplicationDestroyed(fn () => $_SERVER['REQUEST_TIME_FLOAT'] = $started);

    foreach ($uris as $uri) {
        // Nightwatch reads a request's start once, when its provider registers.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Route::get('/orders', function () use ($ordersMs) {
            takeAtLeast($ordersMs);

            return 'ok';
        });
        Route::get('/up', fn () => 'ok');

        test()->get($uri);
    }
}

/**
 * Serve the application at its first commit, then at a second commit that slows the order list, and get both commits.
 *
 * @return array{string, string}
 */
function lastCommitServeBothCommits(): array
{
    $repository = GitRepository::create();
    test()->beforeApplicationDestroyed(fn () => $repository->delete());

    $first = $repository->head();
    lastCommitServe($repository->path, ordersMs: 0, uris: ['/up', '/orders', '/orders', '/orders']);

    $repository->commit();
    $second = $repository->head();
    lastCommitServe($repository->path, ordersMs: LAST_COMMIT_SLOWDOWN_MS, uris: ['/orders', '/orders', '/orders']);

    return [$first, $second];
}

it('finds the order list slower under the commit that slowed it, with no deploy set by hand', function () {
    [$first, $second] = lastCommitServeBothCommits();

    $answer = Answers::assert(Compare::class, ['type' => 'request', 'deploy_before' => $first, 'deploy_after' => $second]);
    $rows = collect($answer['result']['groups'])->keyBy('label');

    expect($first)->not->toBe($second)->toMatch('/^[0-9a-f]{40}$/')
        ->and($second)->toMatch('/^[0-9a-f]{40}$/')
        ->and($answer['result']['before'])->toMatchArray(['deploy' => $first, 'records' => 3, 'earlier_records' => 1])
        ->and($answer['result']['after'])->toMatchArray(['deploy' => $second, 'records' => 3])
        ->and(array_keys($rows->all()))->toBe(['/orders'])
        ->and($rows['/orders'])->toMatchArray(['before_records' => 3, 'after_records' => 3, 'measured_on' => 'p50', 'change' => 'slower', 'reason' => null])
        ->and($rows['/orders']['after_ms'])->toBeGreaterThan($rows['/orders']['before_ms']);
});

it('stamps every record with the full commit it was served under', function () {
    [$first, $second] = lastCommitServeBothCommits();

    $deploys = storeRows('SELECT deploy, COUNT(*) AS records FROM records GROUP BY deploy ORDER BY MIN(started_at)');

    expect($deploys)->toBe([
        ['deploy' => $first, 'records' => 4],
        ['deploy' => $second, 'records' => 3],
    ]);
});

it('labels nothing when the application is not in a repository', function () {
    $directory = GitRepository::temporaryPath();
    (new Filesystem)->ensureDirectoryExists($directory);
    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));

    lastCommitServe($directory, ordersMs: 0, uris: ['/up', '/orders']);

    expect(storeRows('SELECT DISTINCT deploy FROM records'))->toBe([['deploy' => '']]);
});
