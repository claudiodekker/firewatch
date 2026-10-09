<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;

const RANK_BUDGET_AT = 1790776000.0;

const RANK_BUDGET_MEGABYTE = 1048576;

/**
 * @param  list<array<string, mixed>>  $budgets
 */
function rankBudgetsAre(array $budgets): void
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
function rankBudgetGroup(RecordType $type, string $letter, array $milliseconds, array $fields = []): array
{
    return array_map(fn (int $duration) => syntheticRecord($type)->with([
        '_group' => str_repeat($letter, 32),
        'duration' => $duration * 1000,
        'timestamp' => RANK_BUDGET_AT,
        ...$fields,
    ]), $milliseconds);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function rankBudgetOf(RecordType $type, array $arguments = []): array
{
    return Envelope::assert(Rank::class, ['type' => $type->value, ...$arguments])['result']['groups'][0]['budget'];
}

describe('a group against its budget', function () {
    it('judges the maximum below 20 executions and the nearest-rank p95 from 20', function (int $executions, array $slowest, string $state, string $measuredOn, float $measured) {
        rankBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(rankBudgetGroup(RecordType::COMMAND, 'a', [...array_fill(0, $executions - count($slowest), 10), ...$slowest]));

        $budget = rankBudgetOf(RecordType::COMMAND);

        expect($budget)->toMatchArray(['state' => $state, 'measured_on' => $measuredOn, 'entry' => 1])
            ->and($budget['measures'][0])->toMatchArray(['measure' => 'duration', 'measured' => $measured, 'ceiling' => 100]);
    })->with([
        '19 executions, one slow: the maximum' => [19, [500], 'exceeded', 'max', 500.0],
        '20 executions, one slow: the p95 skips it' => [20, [500], 'within', 'p95', 10.0],
        '20 executions, two slow: the 19th is the p95' => [20, [101, 500], 'exceeded', 'p95', 101.0],
        '20 executions, the 19th at the ceiling' => [20, [100, 500], 'within', 'p95', 100.0],
        '19 executions, the maximum at the ceiling' => [19, [100], 'within', 'max', 100.0],
    ]);

    it('judges memory on the same figure as duration', function () {
        rankBudgetsAre([['type' => 'command', 'memory' => 32]]);
        ingest([
            ...rankBudgetGroup(RecordType::COMMAND, 'a', array_fill(0, 19, 10), ['peak_memory_usage' => 8 * RANK_BUDGET_MEGABYTE]),
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [10], ['peak_memory_usage' => 48 * RANK_BUDGET_MEGABYTE]),
        ]);

        $budget = rankBudgetOf(RecordType::COMMAND);

        expect($budget)->toMatchArray(['state' => 'within', 'measured_on' => 'p95'])
            ->and($budget['measures'][0])->toMatchArray(['measure' => 'memory', 'measured' => 8.0, 'ceiling' => 32]);
    });

    it('leaves the skipped task runs out of the figure', function () {
        rankBudgetsAre([['type' => 'scheduled-task', 'duration' => 100, 'memory' => 16]]);
        ingest([
            ...rankBudgetGroup(RecordType::SCHEDULED_TASK, 'a', [10, 20], ['status' => 'processed', 'peak_memory_usage' => 4 * RANK_BUDGET_MEGABYTE]),
            ...rankBudgetGroup(RecordType::SCHEDULED_TASK, 'a', [9_000], ['status' => 'skipped', 'peak_memory_usage' => 900 * RANK_BUDGET_MEGABYTE]),
        ]);

        $budget = rankBudgetOf(RecordType::SCHEDULED_TASK);

        expect($budget)->toMatchArray(['state' => 'within', 'measured_on' => 'max'])
            ->and(array_column($budget['measures'], 'measured', 'measure'))->toEqual(['duration' => 20, 'memory' => 4]);
    });

    it('does not evaluate a group whose every run was skipped', function () {
        rankBudgetsAre([['type' => 'scheduled-task', 'duration' => 100]]);
        ingest(rankBudgetGroup(RecordType::SCHEDULED_TASK, 'a', [9_000, 9_000], ['status' => 'skipped']));

        expect(rankBudgetOf(RecordType::SCHEDULED_TASK))->toMatchArray(['state' => 'not_evaluated', 'reason' => 'not_run', 'entry' => null, 'measures' => []]);
    });

    it('counts the failed executions', function () {
        rankBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(rankBudgetGroup(RecordType::COMMAND, 'a', [10, 500], ['exit_code' => 1]));

        expect(rankBudgetOf(RecordType::COMMAND))->toMatchArray(['state' => 'exceeded', 'measured_on' => 'max']);
    });

    it('is the verdict of the entry that governs the group, from its latest record', function () {
        rankBudgetsAre([
            ['type' => 'request', 'path' => 'orders/*', 'duration' => 100],
            ['type' => 'request', 'duration' => 1000],
        ]);
        ingest([
            ...rankBudgetGroup(RecordType::REQUEST, 'a', [300], ['route_methods' => ['GET'], 'route_path' => 'orders/{order}']),
            ...rankBudgetGroup(RecordType::REQUEST, 'b', [300], ['route_methods' => ['GET'], 'route_path' => 'invoices/{invoice}']),
        ]);

        $entries = collect(Envelope::assert(Rank::class, ['type' => 'request'])['result']['groups'])->mapWithKeys(fn (array $row) => [$row['group'] => $row['budget']['entry']]);

        expect($entries->all())->toBe([str_repeat('a', 32) => 1, str_repeat('b', 32) => 2]);
    });

    it('does not evaluate a group with no budget configured, or none that matches', function (array $budgets, string $reason) {
        rankBudgetsAre($budgets);
        ingest(rankBudgetGroup(RecordType::COMMAND, 'a', [10]));

        expect(rankBudgetOf(RecordType::COMMAND))->toMatchArray(['state' => 'not_evaluated', 'reason' => $reason, 'entry' => null, 'measured_on' => null]);
    })->with([
        'no budget' => [[], 'no_budget_configured'],
        'no entry of the type' => [[['type' => 'request', 'duration' => 100]], 'no_matching_budget'],
        'no matching name' => [[['type' => 'command', 'name' => 'other:*', 'duration' => 100]], 'no_matching_budget'],
    ]);

    it('states the entries it ignored', function () {
        rankBudgetsAre([['type' => 'command', 'duration' => 100], ['type' => 'bogus', 'duration' => 100]]);
        ingest(rankBudgetGroup(RecordType::COMMAND, 'a', [10]));

        expect(rankBudgetOf(RecordType::COMMAND))->toMatchArray(['state' => 'within', 'ignored_entries' => 1]);
    });

    it('judges each group of the ranking, honouring the deploy and the window', function () {
        rankBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest([
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [500], ['deploy' => 'v1', 'timestamp' => RANK_BUDGET_AT]),
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [10], ['deploy' => 'v2', 'timestamp' => RANK_BUDGET_AT + 100]),
        ]);

        expect(rankBudgetOf(RecordType::COMMAND))->toMatchArray(['state' => 'exceeded', 'measured_on' => 'max'])
            ->and(rankBudgetOf(RecordType::COMMAND, ['deploy' => 'v2']))->toMatchArray(['state' => 'within'])
            ->and(rankBudgetOf(RecordType::COMMAND, ['since' => RANK_BUDGET_AT + 50]))->toMatchArray(['state' => 'within']);
    });
});

describe('the deploys of a group', function () {
    it('judges the executions of each deploy on their own', function () {
        rankBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest([
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [500], ['deploy' => 'v1', 'timestamp' => RANK_BUDGET_AT]),
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [10, 20], ['deploy' => 'v2', 'timestamp' => RANK_BUDGET_AT + 100]),
            ...rankBudgetGroup(RecordType::COMMAND, 'a', [30], ['deploy' => '', 'timestamp' => RANK_BUDGET_AT + 200]),
        ]);

        $deploys = Envelope::assert(Rank::class, ['group' => str_repeat('a', 32)])['result']['deploys'];

        expect(array_column($deploys, 'deploy'))->toBe(['v1', 'v2', __('firewatch::messages.rank_no_deploy')])
            ->and(array_column(array_column($deploys, 'budget'), 'state'))->toBe(['exceeded', 'within', 'within'])
            ->and($deploys[0]['budget']['measures'][0])->toMatchArray(['measured' => 500.0, 'ceiling' => 100]);
    });

    it('states the ignored entries on a deploy row', function () {
        rankBudgetsAre([['type' => 'command', 'duration' => 100], ['type' => 'bogus']]);
        ingest(rankBudgetGroup(RecordType::COMMAND, 'a', [10], ['deploy' => 'v1']));

        $deploys = Envelope::assert(Rank::class, ['group' => str_repeat('a', 32)])['result']['deploys'];

        expect($deploys[0]['budget'])->toMatchArray(['state' => 'within', 'ignored_entries' => 1]);
    });
});

describe('the types without a budget', function () {
    it('gives no budget key to a row of a type that is not an execution', function (RecordType $type) {
        rankBudgetsAre([['type' => 'command', 'duration' => 100]]);
        ingest(rankBudgetGroup($type, 'a', [10]));

        expect(Envelope::assert(Rank::class, ['type' => $type->value])['result']['groups'][0])->not->toHaveKey('budget');
    })->with([RecordType::QUERY, RecordType::OUTGOING_REQUEST, RecordType::QUEUED_JOB]);

    it('gives the row of each execution type a budget key', function (RecordType $type) {
        rankBudgetsAre([]);
        ingest(rankBudgetGroup($type, 'a', [10]));

        expect(Envelope::assert(Rank::class, ['type' => $type->value])['result']['groups'][0]['budget'])->toMatchArray(['state' => 'not_evaluated', 'reason' => 'no_budget_configured']);
    })->with([RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK]);
});
