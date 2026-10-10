<?php

use ClaudioDekker\Firewatch\Mcp\Detectors\DetectorName;
use Illuminate\Support\Arr;

test('every tool description is at most 150 words', function () {
    $words = array_map(fn (array $tool) => str_word_count($tool['description']), toolListing()['tools']);

    expect(max($words))->toBeLessThanOrEqual(150);
});

test('every tool the assistant-facing text names in backticks is registered, or is a problem shape', function () {
    $listed = toolListing()['tools'];
    $tools = array_column($listed, 'name');
    $arguments = array_merge(...array_map(fn (array $tool) => array_keys($tool['inputSchema']['properties']), $listed));
    $answerFields = ['next', 'now', 'withheld', 'detail', 'first_seen_at'];
    $otherWords = ['database', 'started_at', 'proc_open'];
    $shapes = array_column(DetectorName::cases(), 'value');
    $text = implode("\n", Arr::flatten(trans('firewatch::messages')));

    preg_match_all('/`([a-z_]+)(\(|`)/', $text, $matches, PREG_SET_ORDER);

    $calls = array_map(fn (array $match) => $match[1], array_filter($matches, fn (array $match) => $match[2] === '('));
    $words = array_map(fn (array $match) => $match[1], array_filter($matches, fn (array $match) => $match[2] === '`'));

    expect(array_values(array_unique(array_diff($calls, $tools))))->toBe([])
        ->and(array_values(array_unique(array_diff($words, $tools, $arguments, $answerFields, $otherWords, $shapes))))->toBe([]);
});

test('the tool listing is under 6,000 tokens at three characters a token', function () {
    $characters = strlen(json_encode(toolListing()['tools'], JSON_THROW_ON_ERROR));

    expect(intdiv($characters, 3))->toBeLessThan(6000);
});

test('every argument description is at most 30 words', function () {
    $words = [];

    foreach (toolListing()['tools'] as $tool) {
        foreach ($tool['inputSchema']['properties'] as $argument => $schema) {
            $words["{$tool['name']}.{$argument}"] = str_word_count($schema['description']);
        }
    }

    expect(array_filter($words, fn (int $count) => $count > 30))->toBe([]);
});

test('the instructions describe every listed tool once, in the order an assistant drills down', function () {
    preg_match_all('/`([a-z]+)` \(/', __('firewatch::messages.instructions'), $matches);

    expect($matches[1])->toBe(['overview', 'detect', 'rank', 'occurrences', 'execution', 'trace', 'actor', 'compare', 'trend', 'query', 'describe', 'fingerprint'])
        ->and(array_diff(array_column(toolListing()['tools'], 'name'), $matches[1]))->toBe([]);
});

test('the instructions are about 330 words', function () {
    expect(str_word_count(__('firewatch::messages.instructions')))->toBeBetween(300, 360);
});

/**
 * Determine if the text names the value as a whole word.
 */
function namesValue(string $text, string $value): bool
{
    return preg_match('/(?<![\w-])'.preg_quote($value, '/').'(?![\w-])/', $text) === 1;
}

test('every closed value of an argument is named in its description or its tool description', function () {
    $recordTypes = ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'];
    $groupTypes = array_values(array_diff($recordTypes, ['log']));
    $closed = [
        'rank.type' => $groupTypes,
        'compare.type' => $groupTypes,
        'trend.type' => $groupTypes,
        'occurrences.type' => $recordTypes,
        'execution.type' => ['request', 'command', 'job-attempt', 'scheduled-task'],
        'describe.type' => [...$recordTypes, 'user'],
        'fingerprint.type' => ['request', 'command', 'job-attempt', 'queued-job', 'scheduled-task', 'query', 'cache-event', 'outgoing-request', 'mail', 'notification'],
        'rank.by' => ['p95_duration', 'p50_duration', 'max_duration', 'total_duration', 'occurrences', 'p95_memory', 'max_memory', 'last_seen', 'queries'],
        'compare.by' => ['p95_duration', 'p50_duration', 'max_duration', 'total_duration', 'occurrences', 'p95_memory', 'p50_memory', 'max_memory', 'queries'],
        'trend.by' => ['occurrences', 'max_duration', 'avg_duration', 'total_duration', 'max_memory'],
        'occurrences.order' => ['recent', 'slowest', 'memory', 'queries'],
        'occurrences.outcome' => ['processed', 'failed', 'released', 'skipped'],
        'occurrences.level' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        'occurrences.at_or_above' => ['median', 'p95'],
        'detect.shape' => ['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'cache', 'memory'],
    ];

    $unnamed = [];

    foreach (toolListing()['tools'] as $tool) {
        foreach ($tool['inputSchema']['properties'] as $argument => $schema) {
            $values = [...($closed["{$tool['name']}.{$argument}"] ?? []), ...($schema['enum'] ?? [])];

            foreach (array_unique($values) as $value) {
                if (! namesValue($schema['description'], $value) && ! namesValue($tool['description'], $value)) {
                    $unnamed[] = "{$tool['name']}.{$argument}: {$value}";
                }
            }
        }
    }

    expect($unnamed)->toBe([]);
});
