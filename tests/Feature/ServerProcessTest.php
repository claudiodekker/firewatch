<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @param  list<array<string, mixed>>  $messages
 * @param  array<string, string>  $env
 * @param  list<string>  $ini
 */
function runServer(string $storeDirectory, array $messages, array $env = [], array $ini = [], string $command = 'firewatch:server'): Process
{
    $input = implode('', array_map(fn (array $message) => json_encode($message, JSON_THROW_ON_ERROR)."\n", $messages));

    // A manifest the in-process tests wrote lacks this package, which only the testbench CLI discovers.
    $packagesCache = sys_get_temp_dir().'/firewatch-packages-'.bin2hex(random_bytes(8)).'.php';

    $process = new Process(
        [PHP_BINARY, ...$ini, 'vendor/bin/testbench', $command],
        cwd: dirname(__DIR__, 2),
        env: [...$env, 'FIREWATCH_DATABASE' => $storeDirectory.'/firewatch.sqlite', 'APP_PACKAGES_CACHE' => $packagesCache],
        input: $input,
        timeout: 30,
    );

    $process->run();

    (new Filesystem)->delete($packagesCache);

    return $process;
}

/**
 * @return array<int|string, array<string, mixed>>
 */
function serverReplies(Process $process): array
{
    $lines = array_filter(explode("\n", $process->getOutput()), fn (string $line) => $line !== '');

    expect(array_values(array_filter($lines, fn (string $line) => ! json_validate($line))))->toBe([]);

    $replies = array_map(fn (string $line) => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);

    expect(array_column($replies, 'jsonrpc'))->toBe(array_fill(0, count($replies), '2.0'));

    return array_column($replies, null, 'id');
}

/**
 * @return list<array<string, mixed>>
 */
function serverSession(): array
{
    return [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'overview', 'arguments' => (object) []]],
    ];
}

it('serves a session over stdio and exits at the end of its input', function () {
    $process = runServer($this->storeDirectory, serverSession());

    $replies = serverReplies($process);

    expect($process->getExitCode())->toBe(0)
        ->and(array_keys($replies))->toBe([1, 2, 3])
        ->and($replies[1]['result']['serverInfo']['name'])->toBe('firewatch')
        ->and($replies[1]['result']['capabilities'])->toBe(['tools' => ['listChanged' => false]])
        ->and($replies[1]['result']['instructions'])->toBe(__('firewatch::messages.instructions'))
        ->and(array_column($replies[2]['result']['tools'], 'name'))->toBe(['overview', 'rank', 'occurrences', 'execution', 'trace', 'detect', 'actor', 'compare', 'trend', 'query', 'describe', 'fingerprint'])
        ->and($replies[3]['result']['isError'])->toBeFalse()
        ->and($replies[3]['result']['content'][0]['text'])->toStartWith("## overview\n");
})->group('process');

it('hands a tool its arguments over stdio, answering in JSON beside the same JSON as text', function () {
    $session = serverSession();
    $session[3]['params']['arguments'] = ['format' => 'json'];

    $process = runServer($this->storeDirectory, $session);
    $replies = serverReplies($process);
    $result = $replies[3]['result'];

    // Testbench's CLI discovers Nightwatch beside Firewatch, which composer.json keeps real installs from doing.
    $errors = array_filter(
        explode("\n", $process->getErrorOutput()),
        fn (string $line) => $line !== '' && ! str_starts_with($line, "Nightwatch's provider was registered before Firewatch's"),
    );

    expect($errors)->toBe([])
        ->and($result['isError'])->toBeFalse()
        ->and(array_keys($result['structuredContent']))->toBe(['tool', 'now', 'window', 'summary', 'empty', 'result', 'coverage', 'blind_spots', 'notes', 'truncated', 'next'])
        ->and(json_decode($result['content'][0]['text'], associative: true))->toEqual($result['structuredContent']);
})->group('process');

it('boots and answers without creating anything where the store would be', function () {
    runServer($this->storeDirectory, serverSession());

    expect($this->storeDirectory)->not->toBeDirectory();
})->group('process');

it('keeps stdout to protocol messages when a provider echoes at boot', function () {
    $process = runServer($this->storeDirectory, serverSession(), env: ['WORKBENCH_ECHO' => 'Stray output from a provider']);

    $replies = serverReplies($process);

    expect(array_keys($replies))->toBe([1, 2, 3])
        ->and($process->getErrorOutput())->toContain('Stray output from a provider');
})->group('process');

it('keeps stdout to protocol messages when a notice fires inside a tool', function () {
    $process = runServer($this->storeDirectory, serverSession(), env: ['WORKBENCH_NOTICE' => 'tool']);

    $replies = serverReplies($process);

    expect(array_keys($replies))->toBe([1, 2, 3])
        ->and($replies[3]['result']['isError'])->toBeTrue();
})->group('process');

it('keeps serving when a request fails with app.debug on', function () {
    $process = runServer($this->storeDirectory, [...serverSession(), ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']], env: ['APP_DEBUG' => 'true', 'WORKBENCH_NOTICE' => 'listing']);

    $replies = serverReplies($process);

    expect($process->getExitCode())->toBe(0)
        ->and($replies[2]['error']['code'])->toBe(-32603)
        ->and($replies[4]['result'])->toHaveKey('resultType');
})->group('process');

it('prints floats shortest round-trip under a hostile precision setting', function () {
    $process = runServer($this->storeDirectory, serverSession(), ini: ['-d', 'precision=5', '-d', 'serialize_precision=5']);

    $replies = serverReplies($process);

    expect($replies[3]['result']['content'][0]['text'])->toMatch('/\(epoch \d{10}(\.\d{1,6})?\)/');
})->group('process');

it('leaves stray output on stdout when stepped aside', function () {
    $process = runServer($this->storeDirectory, serverSession(), env: ['FIREWATCH_ENVIRONMENTS' => 'local', 'WORKBENCH_ECHO' => 'Stray output from a provider']);

    expect($process->getOutput())->toStartWith("Stray output from a provider\n");
})->group('process');

it('leaves stray output on stdout in another firewatch: command', function () {
    $process = runServer($this->storeDirectory, [], env: ['WORKBENCH_ECHO' => 'Stray output from a provider'], command: 'firewatch:doctor');

    expect($process->getOutput())->toStartWith("Stray output from a provider\n");
})->group('process');

it('answers query as unavailable when proc_open is disabled, while every other tool works', function () {
    $session = [...serverSession(), ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'query', 'arguments' => ['sql' => 'SELECT 1']]]];

    $replies = serverReplies(runServer($this->storeDirectory, $session, ini: ['-d', 'disable_functions=proc_open']));

    expect($replies[3]['result']['isError'])->toBeFalse()
        ->and($replies[4]['result']['isError'])->toBeTrue()
        ->and($replies[4]['result']['content'][0]['text'])->toBe(__('firewatch::messages.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.proc_open_missing')]));
})->group('process');
