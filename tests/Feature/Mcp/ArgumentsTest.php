<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use ClaudioDekker\Firewatch\RecordType;

/**
 * Get the text a tool answers the call with.
 *
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function argumentText(string $tool, array $arguments): string
{
    $response = FirewatchServer::tool($tool, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

it('refuses a group that is no group id, naming what a group id is', function (string $tool, array $arguments, string $example, mixed $group, string $shown) {
    $text = argumentText($tool, [...$arguments, 'group' => $group]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'group', 'expected' => __('firewatch::messages.argument_values.group'), 'value' => $shown, 'accepted' => __('firewatch::messages.argument_values.group'), 'example' => $example]));
})->with([
    'rank' => [Rank::class, [], 'rank(group: "<group id>")'],
    'compare' => [Compare::class, ['split_at' => '-1h'], 'compare(group: "<group id>", split_at: "<time>")'],
    'trend' => [Trend::class, [], 'trend(group: "<group id>")'],
    'detect' => [Detect::class, ['shape' => 'failing-routes'], 'detect(shape: "failing-routes", group: "<group id>")'],
    'occurrences' => [Occurrences::class, [], 'occurrences(group: "<group id>")'],
])->with([
    'too short' => ['abc', '"abc"'],
    'too long' => [str_repeat('a', 33), '"'.str_repeat('a', 33).'"'],
    'not hex' => [str_repeat('g', 32), '"'.str_repeat('g', 32).'"'],
    'in capitals' => [str_repeat('A', 32), '"'.str_repeat('A', 32).'"'],
    'no text' => [5, '5'],
]);

it('refuses a limit that is no whole number of the range, never clamping it', function (string $tool, array $arguments, int $maximum, string $example) {
    foreach ([0, $maximum + 1, -1, 5.5, '5', true] as $limit) {
        $text = argumentText($tool, [...$arguments, 'limit' => $limit]);

        expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'limit', 'expected' => __('firewatch::messages.argument_values.range', ['minimum' => 1, 'maximum' => $maximum]), 'value' => json_encode($limit, JSON_THROW_ON_ERROR), 'accepted' => __('firewatch::messages.argument_values.whole_number', ['minimum' => 1, 'maximum' => $maximum]), 'example' => $example]));
    }
})->with([
    'actor' => [Actor::class, ['who' => 'taylor'], 100, 'actor(who: "taylor", limit: 20)'],
    'compare' => [Compare::class, ['type' => 'request', 'split_at' => '-1h'], 100, 'compare(type: "request", split_at: "<time>", limit: 20)'],
    'detect' => [Detect::class, ['shape' => 'failing-routes'], 100, 'detect(shape: "n-plus-one", limit: 20)'],
    'execution' => [Execution::class, [], 100, 'execution(limit: 50)'],
    'occurrences' => [Occurrences::class, ['type' => 'request'], 100, 'occurrences(type: "request", limit: 20)'],
    'query' => [Query::class, ['sql' => 'SELECT 1'], 500, 'query(sql: "SELECT * FROM requests", limit: 50)'],
    'rank by type' => [Rank::class, ['type' => 'queued-job'], 100, 'rank(type: "queued-job", limit: 20)'],
    'rank by group' => [Rank::class, ['group' => str_repeat('a', 32)], 100, 'rank(group: "<group id>", limit: 20)'],
    'trace' => [Trace::class, ['trace_id' => 'trace'], 100, 'trace(trace_id: "<trace id>", limit: 50)'],
]);

it('declares the limit range and default it enforces', function (string $tool, int $maximum, int $default) {
    expect(listedArgument($tool, 'limit'))->toMatchArray(['minimum' => 1, 'maximum' => $maximum, 'default' => $default]);
})->with([
    'actor' => [Actor::class, 100, 20],
    'compare' => [Compare::class, 100, 20],
    'detect' => [Detect::class, 100, 20],
    'execution' => [Execution::class, 100, 50],
    'occurrences' => [Occurrences::class, 100, 20],
    'query' => [Query::class, 500, 50],
    'rank' => [Rank::class, 100, 20],
    'trace' => [Trace::class, 100, 50],
]);

it('accepts a limit of 1 and of the maximum', function (string $tool, array $arguments, int $maximum) {
    foreach ([1, $maximum] as $limit) {
        $text = argumentText($tool, [...$arguments, 'limit' => $limit]);

        expect($text)->not->toStartWith('error: invalid_argument');
    }
})->with([
    'actor' => [Actor::class, ['who' => 'taylor'], 100],
    'compare' => [Compare::class, ['type' => 'request', 'split_at' => '-1h'], 100],
    'detect' => [Detect::class, ['shape' => 'failing-routes'], 100],
    'execution' => [Execution::class, [], 100],
    'occurrences' => [Occurrences::class, ['type' => 'request'], 100],
    'rank' => [Rank::class, ['type' => 'request'], 100],
    'trace' => [Trace::class, ['trace_id' => 'trace'], 100],
]);

it('refuses a type that is none of those the tool reads, matching it exactly', function (string $tool, array $arguments, string $expected, string $accepted, string $example, mixed $type, string $shown) {
    $text = argumentText($tool, [...$arguments, 'type' => $type]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'type', 'expected' => $expected, 'value' => $shown, 'accepted' => $accepted, 'example' => $example]));
})->with(function () {
    $grouped = 'request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request, queued-job';
    $events = 'request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request, queued-job';

    return [
        'rank' => [Rank::class, [], fn () => __('firewatch::messages.argument_values.grouped_type'), $grouped, 'rank(type: "request", by: "p95_duration")'],
        'compare' => [Compare::class, ['split_at' => '-1h'], fn () => __('firewatch::messages.argument_values.grouped_type'), $grouped, 'compare(type: "request", split_at: "<now of an earlier answer>")'],
        'trend' => [Trend::class, [], fn () => __('firewatch::messages.argument_values.grouped_type'), $grouped, 'trend(type: "request")'],
        'occurrences' => [Occurrences::class, [], 'one of the record types', $events, 'occurrences(type: "request")'],
        'describe' => [Describe::class, [], 'one of the twelve record types or user', "{$events}, user", 'describe(type: "request")'],
        'execution' => [Execution::class, [], 'one of the four execution types', 'request, command, job-attempt, scheduled-task', 'execution(type: "request")'],
        'fingerprint' => [Fingerprint::class, [], 'one of the eleven types with a recipe', 'request, command, job-attempt, queued-job, scheduled-task, query, cache-event, outgoing-request, mail, notification, exception', 'fingerprint(type: "request", methods: ["GET","HEAD"], path: "<path>")'],
    ];
})->with([
    'a view name' => ['requests', '"requests"'],
    'an underscore' => ['job_attempt', '"job_attempt"'],
    'a capital' => ['Request', '"Request"'],
    'padding' => [' request', '" request"'],
    'no text' => [5, '5'],
]);

it('refuses a type outside those the tool reads, though it is a record type', function (string $tool, array $arguments, string $type) {
    $text = argumentText($tool, [...$arguments, 'type' => $type]);

    expect($text)->toStartWith('error: invalid_argument')->toContain("got \"{$type}\"");
})->with([
    'a log to rank' => [Rank::class, [], 'log'],
    'a log to compare' => [Compare::class, ['split_at' => '-1h'], 'log'],
    'a log to trend' => [Trend::class, [], 'log'],
    'the user directory among occurrences' => [Occurrences::class, [], 'user'],
    'a query as an execution' => [Execution::class, [], 'query'],
]);

it('refuses a call that names neither a type nor a group', function (string $tool, array $arguments, string $example) {
    $text = argumentText($tool, $arguments);

    expect($text)->toBe(__('firewatch::messages.missing_argument', ['argument' => 'type', 'accepted' => 'request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request, queued-job', 'example' => $example]));
})->with([
    'rank' => [Rank::class, [], 'rank(type: "request", by: "p95_duration")'],
    'compare' => [Compare::class, ['split_at' => '-1h'], 'compare(type: "request", split_at: "<now of an earlier answer>")'],
    'trend' => [Trend::class, [], 'trend(type: "request")'],
]);

it('refuses a type that does not hold the group, naming the types that do', function (string $tool, array $arguments, string $example) {
    ingest([syntheticRecord(RecordType::REQUEST)->with(['_group' => str_repeat('a', 32)])]);

    $text = argumentText($tool, [...$arguments, 'group' => str_repeat('a', 32), 'type' => 'query']);

    expect($text)->toBe(__('firewatch::messages.conflicting_arguments', ['argument' => 'type', 'with' => 'group', 'accepted' => __('firewatch::messages.argument_values.holding', ['types' => 'request']), 'example' => $example]));
})->with([
    'rank' => [Rank::class, [], 'rank(group: "'.str_repeat('a', 32).'")'],
    'compare' => [Compare::class, ['split_at' => '-1h'], 'compare(group: "'.str_repeat('a', 32).'", split_at: "<time>")'],
    'trend' => [Trend::class, [], 'trend(group: "'.str_repeat('a', 32).'")'],
    'occurrences' => [Occurrences::class, [], 'occurrences(group: "'.str_repeat('a', 32).'")'],
]);

it('declares the matching length it enforces', function (string $tool) {
    expect(listedArgument($tool, 'matching'))->toMatchArray(['minLength' => 1, 'maxLength' => 200]);
})->with([
    'rank' => [Rank::class],
    'occurrences' => [Occurrences::class],
]);

it('refuses a matching outside the declared length, naming the length', function (string $tool, array $arguments, string $example, string $matching) {
    $text = argumentText($tool, [...$arguments, 'matching' => $matching]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'matching', 'expected' => '1 to 200 characters', 'value' => json_encode($matching), 'accepted' => 'a substring of 1 to 200 characters', 'example' => $example]));
})->with([
    'rank' => [Rank::class, ['type' => 'request'], 'rank(type: "request", matching: "orders")'],
    'occurrences' => [Occurrences::class, ['type' => 'log'], 'occurrences(type: "log", matching: "timeout")'],
])->with([
    'empty' => [''],
    'too long' => [str_repeat('a', 201)],
]);

it('refuses a deploy that is no text', function (string $tool, array $arguments, string $example) {
    $text = argumentText($tool, [...$arguments, 'deploy' => 1]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'deploy', 'expected' => __('firewatch::messages.argument_values.deploy'), 'value' => '1', 'accepted' => __('firewatch::messages.argument_values.deploy'), 'example' => $example]));
})->with([
    'rank' => [Rank::class, ['type' => 'queued-job'], 'rank(type: "queued-job", deploy: "v1")'],
    'trend' => [Trend::class, ['type' => 'queued-job'], 'trend(type: "request", deploy: "v1")'],
    'occurrences' => [Occurrences::class, ['type' => 'queued-job'], 'occurrences(type: "request", deploy: "v1")'],
]);

it('refuses a filter of text that is no text', function (string $argument, mixed $value, string $shown, string $example) {
    $text = argumentText(Occurrences::class, ['type' => 'request', $argument => $value]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => $argument, 'expected' => __('firewatch::messages.argument_values.text'), 'value' => $shown, 'accepted' => __('firewatch::messages.argument_values.text'), 'example' => $example]));
})->with([
    'a method' => ['method', 5, '5', 'occurrences(type: "request")'],
    'a status' => ['status', 500, '500', 'occurrences(type: "request")'],
    'an outcome' => ['outcome', true, 'true', 'occurrences(type: "request")'],
    'a level' => ['level', ['error'], '["error"]', 'occurrences(type: "request")'],
    'a percentile' => ['at_or_above', 95, '95', 'occurrences(type: "request")'],
    'a matching' => ['matching', 1.5, '1.5', 'occurrences(type: "log", matching: "timeout")'],
]);
