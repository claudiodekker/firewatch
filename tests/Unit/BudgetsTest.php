<?php

use ClaudioDekker\Firewatch\Configuration\BudgetEntry;
use ClaudioDekker\Firewatch\Configuration\Budgets;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\ExecutionType;

/**
 * Get the one-line report of each issue the budgets found.
 *
 * @return list<string>
 */
function budgetIssueLines(Budgets $budgets): array
{
    return array_map(fn (ConfigurationIssue $issue) => $issue->line(), $budgets->issues);
}

it('keeps valid budget entries numbered by their position', function () {
    $budgets = Budgets::parse(['budgets' => [
        ['type' => 'request', 'methods' => ['post', 'Get'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
        ['type' => 'request', 'duration' => 0.5],
        ['type' => 'command', 'name' => 'reports:*', 'duration' => 3600000],
        ['type' => 'job-attempt', 'name' => 'App\\Jobs\\*', 'memory' => 65536],
        ['type' => 'scheduled-task', 'memory' => 12.5],
    ]]);

    expect($budgets->entries)->toEqual([
        new BudgetEntry(number: 1, type: ExecutionType::REQUEST, methods: ['POST', 'GET'], path: 'checkout/*', name: null, durationMilliseconds: 800, memoryMegabytes: 64),
        new BudgetEntry(number: 2, type: ExecutionType::REQUEST, methods: null, path: null, name: null, durationMilliseconds: 0.5, memoryMegabytes: null),
        new BudgetEntry(number: 3, type: ExecutionType::COMMAND, methods: null, path: null, name: 'reports:*', durationMilliseconds: 3600000, memoryMegabytes: null),
        new BudgetEntry(number: 4, type: ExecutionType::JOB_ATTEMPT, methods: null, path: null, name: 'App\\Jobs\\*', durationMilliseconds: null, memoryMegabytes: 65536),
        new BudgetEntry(number: 5, type: ExecutionType::SCHEDULED_TASK, methods: null, path: null, name: null, durationMilliseconds: null, memoryMegabytes: 12.5),
    ])->and($budgets)->toHaveProperties(['ignored' => 0, 'issues' => []]);
});

it('ignores an invalid budget entry', function (mixed $entry, string $reason) {
    $budgets = Budgets::parse(['budgets' => [$entry]]);

    expect($budgets)->toHaveProperties(['entries' => [], 'ignored' => 1])
        ->and(budgetIssueLines($budgets))->toBe(["firewatch.budgets[1]: {$reason}; entry ignored"]);
})->with([
    'not an entry' => ['request', '"request" is not a budget entry'],
    'missing type' => [['duration' => 300], 'type is missing'],
    'unknown type' => [['type' => 'Request', 'duration' => 300], 'type "Request" is not request, command, job-attempt or scheduled-task'],
    'unknown key' => [['type' => 'request', 'duration' => 300, 'queries' => 10], 'key "queries" is not allowed'],
    'name on a request' => [['type' => 'request', 'name' => 'x', 'duration' => 300], 'matcher "name" is not allowed for type request'],
    'methods on a command' => [['type' => 'command', 'methods' => ['GET'], 'duration' => 300], 'matcher "methods" is not allowed for type command'],
    'path on a job attempt' => [['type' => 'job-attempt', 'path' => 'x', 'duration' => 300], 'matcher "path" is not allowed for type job-attempt'],
    'empty methods' => [['type' => 'request', 'methods' => [], 'duration' => 300], 'methods [] is not a non-empty list of HTTP verbs'],
    'methods not a list' => [['type' => 'request', 'methods' => 'GET', 'duration' => 300], 'methods "GET" is not a non-empty list of HTTP verbs'],
    'methods keyed' => [['type' => 'request', 'methods' => ['a' => 'GET'], 'duration' => 300], 'methods array is not a non-empty list of HTTP verbs'],
    'a method that is not a verb' => [['type' => 'request', 'methods' => ['GET', 'FETCH'], 'duration' => 300], 'method "FETCH" is not GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS'],
    'path not a string' => [['type' => 'request', 'path' => 5, 'duration' => 300], 'path 5 is not a string'],
    'name not a string' => [['type' => 'command', 'name' => null, 'duration' => 300], 'name null is not a string'],
    'duration as a string' => [['type' => 'request', 'duration' => '300ms'], 'duration "300ms" is not a number greater than 0 and at most 3600000'],
    'duration zero' => [['type' => 'request', 'duration' => 0], 'duration 0 is not a number greater than 0 and at most 3600000'],
    'duration negative' => [['type' => 'request', 'duration' => -1], 'duration -1 is not a number greater than 0 and at most 3600000'],
    'duration above the maximum' => [['type' => 'request', 'duration' => 3600000.5], 'duration 3600000.5 is not a number greater than 0 and at most 3600000'],
    'memory above the maximum' => [['type' => 'request', 'memory' => 65537], 'memory 65537 is not a number greater than 0 and at most 65536'],
    'memory as a boolean' => [['type' => 'request', 'memory' => true], 'memory true is not a number greater than 0 and at most 65536'],
    'memory not a number' => [['type' => 'request', 'memory' => NAN], 'memory NAN is not a number greater than 0 and at most 65536'],
    'no ceiling' => [['type' => 'scheduled-task'], 'no duration or memory ceiling'],
]);

it('numbers entries by their place in the file when some are ignored', function () {
    $budgets = Budgets::parse(['budgets' => [
        ['type' => 'request', 'duration' => 300],
        ['type' => 'request', 'name' => 'x', 'duration' => 300],
        ['type' => 'command', 'duration' => 1000],
        ['type' => 'job'],
    ]]);

    expect(array_map(fn (BudgetEntry $entry) => $entry->number, $budgets->entries))->toBe([1, 3])
        ->and($budgets->ignored)->toBe(2)
        ->and(budgetIssueLines($budgets))->toBe([
            'firewatch.budgets[2]: matcher "name" is not allowed for type request; entry ignored',
            'firewatch.budgets[4]: type "job" is not request, command, job-attempt or scheduled-task; entry ignored',
        ]);
});

it('uses no budgets when budgets is not a list', function () {
    $budgets = Budgets::parse(['budgets' => 'fast']);

    expect($budgets)->toHaveProperties(['entries' => [], 'ignored' => 0])
        ->and(budgetIssueLines($budgets))->toBe(['firewatch.budgets: "fast" is not a list of budget entries; using none']);
});

it('names the global entries an earlier global entry of the same type shadows', function () {
    $budgets = Budgets::parse(['budgets' => [
        ['type' => 'request', 'duration' => 800],
        ['type' => 'command', 'duration' => 800],
        ['type' => 'request', 'path' => 'checkout/*', 'duration' => 100],
        ['type' => 'request', 'duration' => 900],
        ['type' => 'command', 'duration' => 900],
        ['type' => 'request', 'duration' => 950],
    ]]);

    expect($budgets->shadowed())->toBe([4, 5, 6]);
});

it('shadows nothing when each type has one global entry', function () {
    $budgets = Budgets::parse(['budgets' => [
        ['type' => 'request', 'duration' => 800],
        ['type' => 'command', 'duration' => 800],
        ['type' => 'request', 'path' => 'a', 'duration' => 100],
        ['type' => 'request', 'path' => 'b', 'duration' => 100],
    ]]);

    expect($budgets->shadowed())->toBe([]);
});
