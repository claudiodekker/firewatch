<?php

use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * @return list<array<string, mixed>>
 */
function capturedLogs(): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) {
        $result = $connection->query('SELECT level, message FROM logs ORDER BY id');
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
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
})->with(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);

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

it('leaves logging untouched when Off', function () {
    registerFirewatchWithLogging('null', firewatch: ['enabled' => false]);

    expect(config('logging.default'))->toBe('null');
});

it('does not capture a log sent to a named channel', function () {
    Cache::get('warm-up');
    Log::channel('null')->info('The payment is slow.');
    Nightwatch::digest();

    expect(capturedLogs())->toBe([]);
});
