<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function failedValidationRoutes(): void
{
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    Route::post('/signup', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);

        return redirect('/welcome');
    });

    Route::post('/newsletter', fn () => back()->withErrors(['email' => 'The email is taken.']))->middleware('web');
}

function failedValidationTraffic(): void
{
    failedValidationRoutes();

    test()->post('/signup', ['email' => 'not-an-address']);
    test()->post('/signup');
    test()->postJson('/signup', ['email' => 'not-an-address']);
    test()->post('/signup', ['email' => 'taylor@example.com']);
    test()->post('/newsletter', ['email' => 'taylor@example.com']);
}

it('flags the route whose requests failed validation, the redirected ones with the one answered 422', function () {
    failedValidationTraffic();

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes']);

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 5, 'total' => 1])
        ->and($answer['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_validation')])
        ->and($answer['result']['findings'][0]['name'])->toBe('/signup')
        ->and($answer['result']['findings'][0]['evidence'])->toMatchArray([
            'failed' => 3,
            'requests' => 4,
            'failure_pct' => 75.0,
            'server_errors' => 0,
            'failed_validations' => 3,
        ])
        ->and($answer['result']['findings'][0]['evidence']['status_counts'])->toEqual([302 => 2, 422 => 1]);
});

it('leaves a failed validation out when only server errors are asked for', function () {
    failedValidationTraffic();

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes', 'threshold' => 500]);

    expect($answer['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 5, 'total' => 0])
        ->and($answer['result']['caveats'])->toBe([]);
});

it('lists the requests that failed validation with the fields that failed, apart from the redirects that succeeded', function () {
    failedValidationTraffic();

    $failed = Answers::assert(Occurrences::class, ['type' => 'request', 'failed_validation' => true])['result']['rows'];
    $passed = Answers::assert(Occurrences::class, ['type' => 'request', 'status' => '302', 'failed_validation' => false])['result']['rows'];

    expect(array_map(fn (array $row) => [$row['detail']['status_code'], $row['detail']['invalid_fields']], $failed))->toBe([
        [422, ['email']],
        [302, ['email']],
        [302, ['email']],
    ])
        ->and(array_map(fn (array $row) => [$row['name'], $row['detail']['invalid_fields']], $passed))->toBe([
            ['/newsletter', null],
            ['/signup', null],
        ]);
});

it('shows the fields that failed on the request itself', function () {
    failedValidationRoutes();

    test()->post('/signup', ['email' => 'not-an-address']);

    $finding = Answers::assert(Detect::class, ['shape' => 'failing-routes'])['result']['findings'][0];
    $answer = Answers::assert(Execution::class, ['execution_id' => $finding['latest_execution_id']]);

    expect($answer['result']['request']['invalid_fields'])->toBe(['email']);
});

it('counts a failed validation in the failure rate of its route, whatever status it answered', function () {
    failedValidationTraffic();

    $answer = Answers::assert(Rank::class, ['type' => 'request']);
    $groups = collect($answer['result']['groups'])->keyBy('label');

    expect($answer['result']['failure_definition'])->toBe('status >= 400 or failed validation')
        ->and($groups['/signup']['failure_pct'])->toEqual(75.0)
        ->and($groups['/newsletter']['failure_pct'])->toEqual(0.0);
});

it('offers a request that was redirected back as the failed execution of its trace', function () {
    failedValidationRoutes();

    test()->post('/signup', ['email' => 'not-an-address']);

    $redirected = Answers::assert(Occurrences::class, ['type' => 'request', 'status' => '302', 'failed_validation' => true])['result']['rows'][0];
    $answer = Answers::assert(Trace::class, ['trace_id' => $redirected['trace_id']]);

    expect($answer['next'])->toContain([
        'tool' => 'execution',
        'arguments' => ['execution_id' => $redirected['execution_id']],
        'why' => __('firewatch::messages.trace_next_failed'),
    ]);
});

it('does not see a form whose errors the controller returned by hand', function () {
    failedValidationRoutes();

    test()->post('/newsletter', ['email' => 'taylor@example.com'])->assertSessionHasErrors('email');

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes']);
    $rows = Answers::assert(Occurrences::class, ['type' => 'request'])['result']['rows'];

    expect($answer['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1])
        ->and($rows[0]['detail'])->toMatchArray(['status_code' => 302, 'invalid_fields' => null]);
});

it('keeps a failed validation that answered a server error among the server errors', function () {
    forceRequests();

    Route::post('/imports', fn () => throw ValidationException::withMessages(['file' => 'The file is unreadable.'])->status(500));

    test()->postJson('/imports');

    $answer = Answers::assert(Detect::class, ['shape' => 'failing-routes', 'threshold' => 500]);

    expect($answer['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
        ->and($answer['result']['findings'][0]['evidence'])->toMatchArray(['failed' => 1, 'server_errors' => 1, 'failed_validations' => 1])
        ->and($answer['result']['findings'][0]['evidence']['status_counts'])->toEqual([500 => 1]);
});

it('names the blind spot of a failed validation on every answer about requests', function () {
    failedValidationTraffic();

    $answer = Answers::assert(Occurrences::class, ['type' => 'request', 'failed_validation' => false]);

    expect(array_column($answer['blind_spots'], 'id'))->toContain('unrendered-validation-failures');
});
