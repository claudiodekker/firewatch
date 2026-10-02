<?php

use ClaudioDekker\Firewatch\RecordType;
use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\WireFixture;

it('builds a record with the fields of its wire fixture and deterministic values for the placeholders', function (RecordType $type) {
    $fixture = app(WireFixture::class)->load(Producer::from($type->value));

    $record = syntheticRecord($type)->make();

    expect(array_keys($record))->toBe(array_keys($fixture))
        ->and(array_filter($record, fn (mixed $value) => is_string($value) && preg_match('/^\{\w+\}$/', $value) === 1))->toBe([]);
})->with(RecordType::cases());

it('resolves each placeholder to its deterministic value', function () {
    $exception = syntheticRecord(RecordType::EXCEPTION)->make();
    $request = syntheticRecord(RecordType::REQUEST)->make();

    expect($exception)->toMatchArray([
        'timestamp' => 1767225600.25,
        'deploy' => '',
        'server' => 'web-1',
        '_group' => str_repeat('a', 32),
        'trace_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f',
        'execution_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f',
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 42,
        'trace' => '[]',
        'php_version' => '1.0.0',
        'laravel_version' => '1.0.0',
    ])->and($request)->toMatchArray([
        'ip' => '127.0.0.1',
        'duration' => 1000,
        'bootstrap' => 1000,
        'peak_memory_usage' => 16777216,
    ]);
});

it('replaces and leaves out wire fields', function () {
    $record = syntheticRecord(RecordType::CACHE_EVENT)->with(['key' => 'users', 'colour' => 'red'])->without('ttl', 'store')->make();

    expect($record)->toMatchArray(['key' => 'users', 'colour' => 'red'])
        ->not->toHaveKeys(['ttl', 'store']);
});

it('places a record in an execution by the field its type links on', function (RecordType $type, string $field) {
    $record = syntheticRecord($type)->inExecution('execution-1')->make();

    expect($record[$field])->toBe('execution-1');
})->with([
    'a child' => ['type' => RecordType::QUERY, 'field' => 'execution_id'],
    'a request' => ['type' => RecordType::REQUEST, 'field' => 'trace_id'],
    'a scheduled task' => ['type' => RecordType::SCHEDULED_TASK, 'field' => 'trace_id'],
    'a job attempt' => ['type' => RecordType::JOB_ATTEMPT, 'field' => 'attempt_id'],
]);

it('stamps a record with a deploy', function () {
    $record = syntheticRecord(RecordType::REQUEST)->deploy('v1.2.3')->make();

    expect($record['deploy'])->toBe('v1.2.3');
});

it('sends a synthetic record through Firewatch\'s real ingest, which maps it and counts its drift', function () {
    ingest([syntheticRecord(RecordType::CACHE_EVENT)->inExecution('execution-1')->with(['colour' => 'red'])]);

    expect(storeRows('SELECT execution_id, key FROM cache_events'))->toBe([['execution_id' => 'execution-1', 'key' => 'orders']])
        ->and(storeRows('SELECT kind, detail FROM drift'))->toBe([['kind' => 'unknown_field', 'detail' => 'colour']]);
});
