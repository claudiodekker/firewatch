<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Handler\NullHandler;

/**
 * Serve the requests in a fresh application, so an exception a route throws reaches the real handler, which logs it.
 *
 * @param  list<string>  $uris
 */
function errorLogsRequests(array $uris): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('logging.channels.audit', ['driver' => 'monolog', 'handler' => NullHandler::class]);

    Route::get('/orders', fn () => 'ok');
    Route::get('/stock', function () {
        Log::warning('The stock is low.');

        return 'ok';
    });
    Route::get('/payments/{payment}', function (string $payment) {
        Log::error("Payment {$payment} was declined by gateway ".Str::uuid().'.');

        return 'ok';
    });
    Route::get('/refunds', function () {
        Log::critical('The refund could not be booked.');

        return 'ok';
    });
    Route::get('/audit', function () {
        Log::channel('audit')->error('The audit trail could not be written.');

        return 'ok';
    });

    foreach ($uris as $uri) {
        test()->get($uri);
    }
}

it('flags the error lines by their shape, the one written most often first, and not the warning', function () {
    errorLogsRequests(['/payments/7', '/refunds', '/payments/12', '/stock', '/payments/431', '/orders']);
    [$request] = storeRows('SELECT DISTINCT execution_id FROM requests');

    $envelope = Envelope::assert(Detect::class, ['shape' => 'error-logs']);
    $findings = $envelope['result']['findings'];
    $evidence = array_column($findings, 'evidence');

    expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 5, 'total' => 2, 'saw' => []])
        ->and(array_column($findings, 'group'))->toBe([null, null])
        ->and(array_column($findings, 'name'))->toBe(['Payment <n> was declined by gateway <id>.', 'The refund could not be booked.'])
        ->and(array_column($findings, 'count'))->toBe([3, 1])
        ->and(array_column($findings, 'latest_execution_id'))->toBe([$request['execution_id'], $request['execution_id']])
        ->and(array_column($evidence, 'level'))->toBe(['error', 'critical'])
        ->and(array_column($evidence, 'shape'))->toBe(array_column($findings, 'name'))
        ->and(array_column($evidence, 'fragment'))->toBe(['was declined by gateway', 'The refund could not be booked.'])
        ->and(array_column($evidence, 'in_executions_with_exception'))->toBe([0, 0])
        ->and($evidence[0]['message'])->toStartWith('Payment 431 was declined by gateway ')
        ->and($findings[0]['reaches'])->toBe(['signed_in_actors' => 0, 'without_actor' => 3]);
});

it('flags the line the handler writes for an exception, and keeps the exception', function () {
    errorLogsRequests(['/invoices/7', '/invoices/8']);

    $findings = Envelope::assert(Detect::class, ['shape' => 'error-logs'])['result']['findings'];
    $exceptions = Envelope::assert(Detect::class, ['shape' => 'exception-clusters'])['result'];

    expect(array_column($findings, 'name'))->toBe(['Invoice [<n>] could not be rendered.'])
        ->and($findings[0]['count'])->toBe(2)
        ->and($findings[0]['evidence'])->toMatchArray(['level' => 'error', 'message' => 'Invoice [8] could not be rendered.', 'fragment' => '] could not be rendered.', 'in_executions_with_exception' => 2])
        ->and($exceptions)->toMatchArray(['verdict' => 'findings', 'total' => 1])
        ->and($exceptions['findings'][0]['count'])->toBe(2);
});

it('finds nothing in requests that logged no error, and says how many lines it examined', function () {
    errorLogsRequests(['/stock', '/orders', '/stock']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'error-logs']);

    expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []]);
});

it('does not see an error written to a named channel, and says that it cannot', function () {
    errorLogsRequests(['/audit']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'error-logs']);
    $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

    expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
        ->and($blindSpots)->toHaveKey('named-log-channels')
        ->and($blindSpots['named-log-channels'])->toBe(__('firewatch::messages.blind_spots.named-log-channels'));
});

it('says that logs exist only while log capture is on, whatever the verdict', function (array $uris, string $verdict) {
    errorLogsRequests($uris);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'error-logs']);

    expect($envelope['result']['verdict'])->toBe($verdict)
        ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_log_capture')]);
})->with([
    'with findings' => [['/payments/7'], 'findings'],
    'when clean' => [['/stock'], 'clean'],
    'with nothing examined' => [['/orders'], 'not_evaluated'],
]);

it('opens what a finding points at, and lists the lines it counted', function () {
    errorLogsRequests(['/payments/7', '/refunds', '/payments/12', '/stock', '/payments/431']);

    $envelope = Envelope::assert(Detect::class, ['shape' => 'error-logs']);

    expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'execution'])
        ->and($envelope['next'][1]['arguments'])->toBe(['type' => 'log', 'level' => 'error', 'matching' => 'was declined by gateway']);

    expect(array_map(fn (array $call) => Envelope::follow($call)['empty'], $envelope['next']))->each->toBeNull();

    $lines = Envelope::follow($envelope['next'][1])['result']['rows'];

    expect($lines)->toHaveCount(3);
});

it('puts the verdict in the overview next to the other shapes', function () {
    errorLogsRequests(['/payments/7', '/refunds', '/payments/12']);

    $row = collect(Envelope::assert(Overview::class)['result']['detectors'])->firstWhere('detector', 'error-logs');

    expect($row)->toBe([
        'detector' => 'error-logs',
        'verdict' => 'findings',
        'reason' => null,
        'examined' => 3,
        'total' => 2,
        'worst' => ['name' => 'Payment <n> was declined by gateway <id>.', 'group' => null],
    ]);
});
