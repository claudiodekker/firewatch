<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Facades\Nightwatch;
use Monolog\Handler\NullHandler;

/**
 * @return list<array<string, mixed>>
 */
function capturedLogs(): array
{
    return storeRows('SELECT level, message FROM logs ORDER BY id');
}

/**
 * @param  array<string, array<string, mixed>>  $channels
 * @param  array<string, mixed>  $firewatch
 */
function registerFirewatchWithLogging(string $default, array $channels = [], array $firewatch = []): void
{
    config()->set('logging.default', $default);

    foreach ($channels as $name => $channel) {
        config()->set("logging.channels.{$name}", $channel);
    }

    foreach ($firewatch as $key => $value) {
        config()->set("firewatch.{$key}", $value);
    }

    registerFirewatch();
}

it('captures a log at every level on the default channel', function (string $level) {
    Log::log($level, 'The payment is slow.');
    Nightwatch::digest();

    expect(capturedLogs())->toBe([['level' => $level, 'message' => 'The payment is slow.']]);
})->with([
    'debug' => 'debug',
    'info' => 'info',
    'notice' => 'notice',
    'warning' => 'warning',
    'error' => 'error',
    'critical' => 'critical',
    'alert' => 'alert',
    'emergency' => 'emergency',
]);

it('keeps the default channel logging as the application configured it', function () {
    $path = storage_path('logs/firewatch-audit-'.getmypid().'.log');
    test()->beforeApplicationDestroyed(fn () => File::delete($path));
    registerFirewatchWithLogging('audit', channels: ['audit' => ['driver' => 'single', 'path' => $path]]);

    Log::info('The payment is slow.');
    Nightwatch::digest();

    expect(File::get($path))->toContain('The payment is slow.')
        ->and(capturedLogs())->toBe([['level' => 'info', 'message' => 'The payment is slow.']]);
});

it('captures a log even when the default channel stops the handlers after it', function () {
    // Monolog's NullHandler reports every record as handled, so no later handler in a stack sees it.
    registerFirewatchWithLogging('null');

    Log::info('The payment is slow.');
    Nightwatch::digest();

    expect(capturedLogs())->toBe([['level' => 'info', 'message' => 'The payment is slow.']]);
});

it('captures a log once when the default channel already includes Nightwatch\'s', function (array $channels) {
    registerFirewatchWithLogging('outer', channels: $channels);

    Log::info('The payment is slow.');
    Nightwatch::digest();

    expect(capturedLogs())->toBe([['level' => 'info', 'message' => 'The payment is slow.']]);
})->with([
    'in the default stack' => [['outer' => ['driver' => 'stack', 'channels' => ['nightwatch', 'null']]]],
    'in a stack listed as a string' => [['outer' => ['driver' => 'stack', 'channels' => 'nightwatch,null']]],
    'in a stack inside the default stack' => [[
        'outer' => ['driver' => 'stack', 'channels' => ['inner', 'null']],
        'inner' => ['driver' => 'stack', 'channels' => ['nightwatch']],
    ]],
]);

it('wraps a default stack that refers back to itself without looping', function () {
    registerFirewatchWithLogging('outer', channels: [
        'outer' => ['driver' => 'stack', 'channels' => ['inner']],
        'inner' => ['driver' => 'stack', 'channels' => ['outer', 'null']],
    ]);

    expect(config('logging.default'))->toBe('firewatch');
});

it('leaves logging untouched when capturing logs is turned off', function () {
    registerFirewatchWithLogging('null', firewatch: ['capture.logs' => false]);

    // A cache event creates the store the assertion reads.
    Cache::get('warm-up');
    Log::info('The payment is slow.');
    Nightwatch::digest();

    expect(config('logging.default'))->toBe('null')
        ->and(capturedLogs())->toBe([]);
});

it('leaves logging untouched when Off or stepped aside', function (array $firewatch) {
    registerFirewatchWithLogging('null', firewatch: $firewatch);

    expect(config('logging.default'))->toBe('null');
})->with([
    'Off' => [['enabled' => false]],
    'stepped aside' => [['environments' => 'production']],
]);

it('wraps nothing when Nightwatch defined no log channel', function () {
    registerFirewatchWithLogging('null', channels: ['nightwatch' => null]);

    expect(config('logging.default'))->toBe('null');
});

it('leaves an application channel named firewatch alone', function () {
    $channel = ['driver' => 'monolog', 'handler' => NullHandler::class];

    registerFirewatchWithLogging('null', channels: ['firewatch' => $channel]);

    expect(config('logging.default'))->toBe('null')
        ->and(config('logging.channels.firewatch'))->toBe($channel);
});

it('does not capture a log sent to a named channel', function () {
    $path = storage_path('logs/firewatch-named-'.getmypid().'.log');
    test()->beforeApplicationDestroyed(fn () => File::delete($path));
    config()->set('logging.channels.audit', ['driver' => 'single', 'path' => $path]);

    // A cache event creates the store the assertion reads.
    Cache::get('warm-up');
    Log::channel('audit')->info('The payment is slow.');
    Nightwatch::digest();

    expect(File::get($path))->toContain('The payment is slow.')
        ->and(capturedLogs())->toBe([]);
});
