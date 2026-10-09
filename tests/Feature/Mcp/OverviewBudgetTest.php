<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;

const OVERVIEW_BUDGET_AT = 1790776000.0;

/**
 * @param  list<array<string, mixed>>  $budgets
 */
function overviewBudgetsAre(array $budgets): void
{
    config()->set('firewatch.budgets', $budgets);
    registerFirewatch();
}

/**
 * Make the records of one group, one per given duration in milliseconds.
 *
 * @param  list<int>  $milliseconds
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function overviewBudgetGroup(RecordType $type, string $letter, array $milliseconds, array $fields = []): array
{
    return array_map(fn (int $duration) => syntheticRecord($type)->with([
        '_group' => str_repeat($letter, 32),
        'duration' => $duration * 1000,
        'timestamp' => OVERVIEW_BUDGET_AT,
        ...$fields,
    ]), $milliseconds);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function overviewBudgetAnswer(array $arguments = []): array
{
    return Envelope::assert(Overview::class, $arguments);
}

describe('the counts', function () {
    it('counts the groups of the window by verdict, and states the ignored entries', function () {
        overviewBudgetsAre([
            ['type' => 'command', 'name' => 'slow:*', 'duration' => 100],
            ['type' => 'command', 'name' => 'fast:*', 'duration' => 100],
            ['type' => 'request', 'duration' => 100],
            ['type' => 'bogus'],
        ]);
        ingest([
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [500], ['name' => 'slow:one']),
            ...overviewBudgetGroup(RecordType::COMMAND, 'b', [10], ['name' => 'fast:one']),
            ...overviewBudgetGroup(RecordType::COMMAND, 'c', [10], ['name' => 'other:one']),
            ...overviewBudgetGroup(RecordType::REQUEST, 'd', [900]),
            ...overviewBudgetGroup(RecordType::JOB_ATTEMPT, 'e', [10]),
        ]);

        $answer = overviewBudgetAnswer();

        expect($answer['result']['budgets'])->toBe(['exceeded' => 2, 'within' => 1, 'not_evaluated' => 2, 'ignored_entries' => 1])
            ->and($answer['notes'])->toContain('Budgets: 2 groups exceeded, 1 within, 2 not evaluated; ignored_entries: 1. Groups not listed are not proven within budget unless counted as within.');
    });

    it('counts one exceeded group in the singular', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [500]));

        expect(overviewBudgetAnswer()['notes'])->toContain('Budgets: 1 group exceeded, 0 within, 0 not evaluated. Groups not listed are not proven within budget unless counted as within.');
    });

    it('states no ignored entries when the normaliser dropped none', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [10]));

        $answer = overviewBudgetAnswer();

        expect($answer['result']['budgets'])->toBe(['exceeded' => 0, 'within' => 1, 'not_evaluated' => 0])
            ->and($answer['result'])->not->toHaveKey('budgets_exceeded')
            ->and($answer['notes'])->toContain('Budgets: 0 groups exceeded, 1 within, 0 not evaluated. Groups not listed are not proven within budget unless counted as within.');
    });

    it('counts only the groups with a record in the window', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest([
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [500], ['timestamp' => OVERVIEW_BUDGET_AT]),
            ...overviewBudgetGroup(RecordType::COMMAND, 'b', [10], ['timestamp' => OVERVIEW_BUDGET_AT + 100]),
        ]);

        $answer = overviewBudgetAnswer(['since' => OVERVIEW_BUDGET_AT + 50]);

        expect($answer['result']['budgets'])->toBe(['exceeded' => 0, 'within' => 1, 'not_evaluated' => 0]);
    });

    it('says the budgets are not evaluated when none is configured', function () {
        overviewBudgetsAre([]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [10]));

        $answer = overviewBudgetAnswer();

        expect($answer['result']['budgets'])->toBe(['state' => 'not_evaluated', 'reason' => 'no_budget_configured'])
            ->and($answer['result'])->not->toHaveKey('budgets_exceeded')
            ->and($answer['notes'])->toContain('Budgets: not evaluated (no_budget_configured)');
    });

    it('says the budgets are not evaluated when every entry was ignored, and states how many', function () {
        overviewBudgetsAre([['type' => 'bogus'], ['type' => 'command']]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [10]));

        $answer = overviewBudgetAnswer();

        expect($answer['result']['budgets'])->toBe(['state' => 'not_evaluated', 'reason' => 'no_budget_configured', 'ignored_entries' => 2])
            ->and($answer['notes'])->toContain('Budgets: not evaluated (no_budget_configured); ignored_entries: 2');
    });

    it('puts the budgets after the fixed sections and before the detectors', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [500]));

        $keys = array_keys(overviewBudgetAnswer()['result']);

        expect(array_slice($keys, -3))->toBe(['budgets', 'budgets_exceeded', 'detectors'])
            ->and($keys[array_search('budgets', $keys, true) - 1])->toBe('actors');
    });
});

describe('the exceeded groups', function () {
    it('lists the exceeded groups worst first by the largest ratio over their exceeded ceilings', function () {
        overviewBudgetsAre([
            ['type' => 'command', 'name' => 'both:*', 'duration' => 100, 'memory' => 10],
            ['type' => 'command', 'duration' => 100],
            ['type' => 'request', 'duration' => 100],
        ]);
        ingest([
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [300], ['name' => 'plain:one']),
            ...overviewBudgetGroup(RecordType::COMMAND, 'b', [200], ['name' => 'both:one', 'peak_memory_usage' => 50 * 1048576]),
            ...overviewBudgetGroup(RecordType::REQUEST, 'c', [250], ['route_path' => 'orders']),
        ]);

        $rows = overviewBudgetAnswer()['result']['budgets_exceeded'];

        expect(array_column($rows, 'group'))->toBe([str_repeat('b', 32), str_repeat('a', 32), str_repeat('c', 32)])
            ->and($rows[0])->toMatchArray(['type' => 'command', 'label' => 'both:one', 'measure' => 'memory', 'unit' => 'mb', 'measured' => 50.0, 'ceiling' => 10, 'measured_on' => 'max'])
            ->and($rows[1])->toMatchArray(['type' => 'command', 'measure' => 'duration', 'unit' => 'ms', 'measured' => 300.0, 'ceiling' => 100])
            ->and($rows[2]['type'])->toBe('request');
    });

    it('breaks a tie by the group hash', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest([
            ...overviewBudgetGroup(RecordType::COMMAND, 'c', [300]),
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [300]),
            ...overviewBudgetGroup(RecordType::COMMAND, 'b', [300]),
        ]);

        expect(array_column(overviewBudgetAnswer()['result']['budgets_exceeded'], 'group'))->toBe([str_repeat('a', 32), str_repeat('b', 32), str_repeat('c', 32)]);
    });

    it('points each row at the rank of its group in the window', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [300]));

        $row = overviewBudgetAnswer(['since' => OVERVIEW_BUDGET_AT - 10])['result']['budgets_exceeded'][0];

        expect($row['next'])->toBe('rank(group: "'.str_repeat('a', 32).'", since: '.json_encode(OVERVIEW_BUDGET_AT - 10).')');
    });

    it('lists at most 10 groups, with the standard truncated entry', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(collect(range(1, 12))->flatMap(fn (int $index) => overviewBudgetGroup(RecordType::COMMAND, dechex($index), [100 + $index]))->all());

        $answer = overviewBudgetAnswer();
        $entry = collect($answer['truncated'])->firstWhere('section', 'budgets_exceeded');

        expect($answer['result']['budgets'])->toMatchArray(['exceeded' => 12])
            ->and($answer['result']['budgets_exceeded'])->toHaveCount(10)
            ->and($answer['result']['budgets_exceeded'][0]['measured'])->toEqual(112)
            ->and($entry)->toMatchArray(['section' => 'budgets_exceeded', 'shown' => 10, 'matched' => 12, 'reason' => 'limit']);
    });

    it('lists a group on the p95 once it has 20 executions that ran', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [...array_fill(0, 18, 10), 101, 500]));

        $row = overviewBudgetAnswer()['result']['budgets_exceeded'][0];

        expect($row)->toMatchArray(['measured' => 101.0, 'measured_on' => 'p95']);
    });

    it('does not list a skipped task, and counts it as not evaluated', function () {
        overviewBudgetsAre([['type' => 'scheduled-task', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::SCHEDULED_TASK, 'a', [9_000], ['status' => 'skipped']));

        $answer = overviewBudgetAnswer();

        expect($answer['result']['budgets'])->toBe(['exceeded' => 0, 'within' => 0, 'not_evaluated' => 1])
            ->and($answer['result'])->not->toHaveKey('budgets_exceeded');
    });
});

describe('the rest of the answer', function () {
    it('adds to the summary the groups over budget only when there are some', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest([
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [500]),
            ...overviewBudgetGroup(RecordType::COMMAND, 'b', [500]),
            ...overviewBudgetGroup(RecordType::COMMAND, 'c', [10]),
        ]);

        expect(overviewBudgetAnswer()['summary'])->toContain('2 groups over budget.');

        overviewBudgetsAre([['type' => 'command', 'duration' => 1000]]);

        expect(overviewBudgetAnswer()['summary'])->not->toContain('budget');
    });

    it('says 1 group over budget in the singular', function () {
        overviewBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(overviewBudgetGroup(RecordType::COMMAND, 'a', [500]));

        expect(overviewBudgetAnswer()['summary'])->toContain('1 group over budget.');
    });

    it('leaves the detectors, the rows and the summary of an answer without budgets as they were', function () {
        $records = [
            ...overviewBudgetGroup(RecordType::COMMAND, 'a', [500]),
            ...overviewBudgetGroup(RecordType::REQUEST, 'b', [900]),
        ];
        ingest($records);

        overviewBudgetsAre([]);
        $without = overviewBudgetAnswer();

        overviewBudgetsAre([['type' => 'command', 'duration' => 100], ['type' => 'request', 'duration' => 100]]);
        $with = overviewBudgetAnswer();

        expect($with['result']['detectors'])->toBe($without['result']['detectors'])
            ->and($with['result']['slowest_by_total_time'])->toBe($without['result']['slowest_by_total_time'])
            ->and($with['result']['records'])->toBe($without['result']['records'])
            ->and($with['empty'])->toBe($without['empty'])
            ->and($with['summary'])->toBe(str_replace(' In the window:', ' 2 groups over budget. In the window:', $without['summary']));
    });
});
