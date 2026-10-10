<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Artisan;

/**
 * Get the arguments each tool needs to be called at all, by tool name.
 *
 * @return array<string, array<string, mixed>>
 */
function nothingCapturedArguments(): array
{
    return [
        'rank' => ['type' => 'request'],
        'detect' => ['shape' => 'failing-routes'],
        'occurrences' => ['type' => 'request'],
        'trace' => ['trace_id' => 'abc'],
        'actor' => ['who' => 'taylor'],
        'compare' => ['type' => 'request', 'split_at' => '-1h'],
        'trend' => ['type' => 'request'],
        'query' => ['sql' => 'select 1'],
        'fingerprint' => ['type' => 'request', 'methods' => ['GET'], 'path' => '/orders'],
    ];
}

/**
 * Call every tool the server registers on a fresh install, as an assistant sweeping the ladder would.
 *
 * @return array<string, array<string, mixed>>
 */
function nothingCapturedAnswers(): array
{
    $answers = [];

    foreach (FirewatchServer::TOOLS as $tool) {
        $name = app($tool)->name();
        $answers[$name] = Envelope::assert($tool, nothingCapturedArguments()[$name] ?? []);
    }

    return $answers;
}

it('says plainly that no store has been written yet, in every tool', function () {
    $answers = nothingCapturedAnswers();
    $path = app(Configuration::class)->database;

    expect($answers)->toHaveCount(count(FirewatchServer::TOOLS));

    foreach ($answers as $name => $answer) {
        expect($answer['empty'])->toMatchArray(['kind' => 'no_store', 'population' => null, 'message' => __('firewatch::messages.no_store', ['path' => $path])], $name)
            ->and($answer['coverage']['state'])->toBe('absent', $name)
            ->and($answer['next'])->toBe([], $name);
    }

    // The tools state the blind spots of the record types they read; the SQL tool reads none until a statement runs.
    foreach (array_diff_key($answers, ['query' => true]) as $name => $answer) {
        expect($answer['blind_spots'])->not->toBeEmpty($name);
    }
});

it('reports no record and no verdict, only that nothing was examined', function () {
    $answers = nothingCapturedAnswers();

    foreach (array_diff_key($answers, array_flip(['describe', 'detect', 'fingerprint'])) as $name => $answer) {
        expect($answer['result'])->toBe([], $name)
            ->and($answer['summary'])->toBe(__('firewatch::messages.empty_summary.no_store'), $name);
    }

    expect($answers['detect']['result'])->toMatchArray(['verdict' => 'not_evaluated', 'examined' => 0, 'total' => 0, 'findings' => []])
        ->and($answers['detect']['summary'])->not->toContain('clean')
        ->and($answers['describe']['summary'])->toBe(__('firewatch::messages.describe_summary_absent'))
        ->and($answers['fingerprint']['result']['held'])->toBe([])
        ->and($answers['fingerprint']['result']['recipe_check'][0]['check'])->toBe('not_evaluated');
});

it('gives every detector shape the verdict not evaluated, never clean', function () {
    foreach (['failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'error-logs', 'failing-http', 'cache'] as $shape) {
        $answer = Envelope::assert(Detect::class, ['shape' => $shape]);

        expect($answer['result']['verdict'])->toBe('not_evaluated', $shape)
            ->and($answer['result']['examined'])->toBe(0, $shape);
    }
});

it('creates no store, and no directory for one, however many tools are called', function () {
    nothingCapturedAnswers();
    nothingCapturedAnswers();

    expect(file_exists(app(Configuration::class)->database))->toBeFalse()
        ->and(is_dir($this->storeDirectory))->toBeFalse();
});

it('has the doctor say there is no store yet, not that the store is healthy', function () {
    $exit = Artisan::call('firewatch:doctor', ['--json' => true]);
    $checks = collect(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('id');

    expect($exit)->toBe(0);

    foreach (['store-integrity', 'store-activity', 'store-drift'] as $id) {
        expect($checks[$id])->toMatchArray(['status' => 'info', 'message' => __('firewatch::messages.doctor.store.absent'), 'fix' => null], $id);
    }

    expect($checks['store-identity']['status'])->toBe('info')
        ->and($checks['store-identity']['message'])->toBe(__('firewatch::messages.doctor.store-identity.absent', ['path' => app(Configuration::class)->database]))
        ->and($checks['store-gitignore']['status'])->toBe('info')
        ->and(file_exists(app(Configuration::class)->database))->toBeFalse();
});
