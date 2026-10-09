<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Date;

const BUDGET_AT = 1790776000.0;

const BUDGET_MEGABYTE = 1048576;

/**
 * @param  list<array<string, mixed>>  $budgets
 */
function budgetsAre(array $budgets): void
{
    config()->set('firewatch.budgets', $budgets);
    registerFirewatch();
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function budgetAnswer(RecordType $type, array $fields = []): array
{
    ingest([syntheticRecord($type)->inExecution('judged')->with(['timestamp' => BUDGET_AT, ...$fields])]);

    return Envelope::assert(Execution::class, ['execution_id' => 'judged']);
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function budgetOf(RecordType $type, array $fields = []): array
{
    return budgetAnswer($type, $fields)['result']['header']['budget'];
}

/**
 * @param  list<string>|null  $methods
 * @return array<string, mixed>
 */
function budgetRoute(?array $methods, ?string $path): array
{
    return ['route_methods' => $methods, 'route_path' => $path];
}

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(BUDGET_AT - 3600));
});

describe('which entry governs', function () {
    it('picks the specific entry over the global one, in either file order', function (array $budgets, int $entry) {
        budgetsAre($budgets);

        expect(budgetOf(RecordType::REQUEST, budgetRoute(['GET', 'HEAD'], 'orders/{order}')))->toMatchArray(['entry' => $entry]);
    })->with([
        'global first' => [[
            ['type' => 'request', 'duration' => 1000],
            ['type' => 'request', 'path' => 'orders/*', 'duration' => 1000],
        ], 2],
        'global last' => [[
            ['type' => 'request', 'path' => 'orders/*', 'duration' => 1000],
            ['type' => 'request', 'duration' => 1000],
        ], 1],
    ]);

    it('picks the first matching specific entry', function () {
        budgetsAre([
            ['type' => 'request', 'path' => 'invoices/*', 'duration' => 1000],
            ['type' => 'request', 'path' => 'orders/*', 'duration' => 1000],
            ['type' => 'request', 'path' => 'orders/{order}', 'duration' => 1000],
        ]);

        expect(budgetOf(RecordType::REQUEST, budgetRoute(['GET'], 'orders/{order}')))->toMatchArray(['entry' => 2]);
    });

    it('uses the global entry whole, so a specific entry does not borrow its memory', function () {
        budgetsAre([
            ['type' => 'request', 'duration' => 1000, 'memory' => 1],
            ['type' => 'request', 'path' => 'orders/*', 'duration' => 1000],
        ]);

        $budget = budgetOf(RecordType::REQUEST, [...budgetRoute(['GET'], 'orders/{order}'), 'peak_memory_usage' => 40 * BUDGET_MEGABYTE]);

        expect($budget)->toMatchArray(['entry' => 2, 'state' => 'within'])
            ->and(array_column($budget['measures'], 'measure'))->toBe(['duration']);
    });

    it('matches the methods as a subset of the route methods', function (array $methods, int $entry) {
        budgetsAre([
            ['type' => 'request', 'methods' => $methods, 'duration' => 1000],
            ['type' => 'request', 'duration' => 1000],
        ]);

        expect(budgetOf(RecordType::REQUEST, budgetRoute(['GET', 'HEAD'], 'orders')))->toMatchArray(['entry' => $entry]);
    })->with([
        'GET against GET|HEAD' => [['GET'], 1],
        'GET and HEAD' => [['GET', 'HEAD'], 1],
        'POST' => [['POST'], 2],
        'GET and POST' => [['GET', 'POST'], 2],
    ]);

    it('ignores a leading slash on both sides, so the root route is matched by a slash or empty', function (string $pattern, string $path) {
        budgetsAre([
            ['type' => 'request', 'path' => $pattern, 'duration' => 1000],
            ['type' => 'request', 'duration' => 1000],
        ]);

        expect(budgetOf(RecordType::REQUEST, budgetRoute(['GET'], $path)))->toMatchArray(['entry' => 1]);
    })->with([
        'slash on the pattern' => ['/orders/*', 'orders/{order}'],
        'slash on the route' => ['orders/*', '/orders/{order}'],
        'slash on both' => ['/orders/*', '/orders/{order}'],
        'neither' => ['orders/*', 'orders/{order}'],
        'root pattern as a slash' => ['/', '/'],
        'root pattern as empty' => ['', '/'],
    ]);

    it('needs both matchers and matches the path case-sensitively', function (array $matchers) {
        budgetsAre([
            ['type' => 'request', 'duration' => 1000, ...$matchers],
            ['type' => 'request', 'duration' => 1000],
        ]);

        expect(budgetOf(RecordType::REQUEST, budgetRoute(['GET'], 'orders/{order}')))->toMatchArray(['entry' => 2]);
    })->with([
        'case' => [['path' => 'Orders/*']],
        'the method fails' => [['path' => 'orders/*', 'methods' => ['POST']]],
        'the path fails' => [['path' => 'invoices/*', 'methods' => ['GET']]],
    ]);

    it('judges a request with no matched route only against the global entry', function () {
        budgetsAre([
            ['type' => 'request', 'path' => '*', 'duration' => 1000],
            ['type' => 'request', 'path' => '', 'duration' => 1000],
            ['type' => 'request', 'methods' => ['GET'], 'duration' => 1000],
            ['type' => 'request', 'duration' => 1000],
        ]);

        expect(budgetOf(RecordType::REQUEST, budgetRoute([], '')))->toMatchArray(['entry' => 4]);
    });

    it('matches the name of a command, job attempt and scheduled task', function (RecordType $type, string $name, int $entry) {
        budgetsAre([
            ['type' => 'request', 'duration' => 1000],
            ['type' => $type->value, 'name' => 'other:*', 'duration' => 1000],
            ['type' => $type->value, 'name' => 'reports:*', 'duration' => 1000],
            ['type' => $type->value, 'duration' => 1000],
        ]);

        expect(budgetOf($type, ['name' => $name]))->toMatchArray(['entry' => $entry]);
    })->with(function () {
        foreach ([RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK] as $type) {
            yield "{$type->value} matching" => [$type, 'reports:daily', 3];
            yield "{$type->value} falling to the global entry" => [$type, 'orders:sync', 4];
        }
    });
});

describe('the verdict', function () {
    it('judges each ceiling strictly greater against equal', function (int $duration, int $memory, string $state, array $exceeded) {
        budgetsAre([['type' => 'command', 'duration' => 300, 'memory' => 32]]);

        $budget = budgetOf(RecordType::COMMAND, ['duration' => $duration, 'peak_memory_usage' => $memory]);

        expect($budget)->toMatchArray(['state' => $state, 'reason' => null, 'entry' => 1, 'measured_on' => 'own'])
            ->and(array_column($budget['measures'], 'exceeded', 'measure'))->toBe($exceeded);
    })->with([
        'both equal' => [300_000, 32 * BUDGET_MEGABYTE, 'within', ['duration' => false, 'memory' => false]],
        'both under' => [299_999, 32 * BUDGET_MEGABYTE - 1, 'within', ['duration' => false, 'memory' => false]],
        'duration over' => [300_001, 32 * BUDGET_MEGABYTE, 'exceeded', ['duration' => true, 'memory' => false]],
        'memory over' => [300_000, 32 * BUDGET_MEGABYTE + 1, 'exceeded', ['duration' => false, 'memory' => true]],
        'both over' => [300_001, 32 * BUDGET_MEGABYTE + 1, 'exceeded', ['duration' => true, 'memory' => true]],
    ]);

    it('states the unit, the ceiling and the measured value of each measure', function () {
        budgetsAre([['type' => 'command', 'duration' => 300.5, 'memory' => 32]]);

        $budget = budgetOf(RecordType::COMMAND, ['duration' => 412_000, 'peak_memory_usage' => 20 * BUDGET_MEGABYTE]);

        expect($budget['measures'])->toEqual([
            ['measure' => 'duration', 'unit' => 'ms', 'ceiling' => 300.5, 'measured' => 412.0, 'exceeded' => true],
            ['measure' => 'memory', 'unit' => 'mb', 'ceiling' => 32, 'measured' => 20.0, 'exceeded' => false],
        ]);
    });

    it('lists only the ceilings the entry sets', function () {
        budgetsAre([['type' => 'command', 'memory' => 32]]);

        expect(array_column(budgetOf(RecordType::COMMAND)['measures'], 'measure'))->toBe(['memory']);
    });

    it('lets exceeded dominate an unmeasurable ceiling', function () {
        budgetsAre([['type' => 'command', 'duration' => 300, 'memory' => 32]]);

        $budget = budgetOf(RecordType::COMMAND, ['duration' => 500_000, 'peak_memory_usage' => null]);

        expect($budget)->toMatchArray(['state' => 'exceeded', 'reason' => null])
            ->and(array_column($budget['measures'], 'measured', 'measure')['memory'])->toBeNull();
    });

    it('does not call an execution within when a ceiling is unmeasurable', function () {
        budgetsAre([['type' => 'command', 'duration' => 300, 'memory' => 32]]);

        expect(budgetOf(RecordType::COMMAND, ['duration' => 100_000, 'peak_memory_usage' => null]))
            ->toMatchArray(['state' => 'not_evaluated', 'reason' => 'no_measurement', 'entry' => 1, 'measured_on' => 'own']);
    });

    it('is not evaluated with no valid entry at all', function () {
        budgetsAre([]);

        expect(budgetOf(RecordType::COMMAND))->toBe([
            'state' => 'not_evaluated',
            'reason' => 'no_budget_configured',
            'entry' => null,
            'measured_on' => null,
            'measures' => [],
        ]);
    });

    it('is not evaluated when no entry of the type governs', function () {
        budgetsAre([
            ['type' => 'request', 'duration' => 300],
            ['type' => 'command', 'name' => 'reports:*', 'duration' => 300],
        ]);

        expect(budgetOf(RecordType::COMMAND, ['name' => 'orders:sync']))
            ->toMatchArray(['state' => 'not_evaluated', 'reason' => 'no_matching_budget', 'entry' => null, 'measures' => []]);
    });

    it('does not run a skipped scheduled task, whether an entry governs it or not', function (array $budgets) {
        budgetsAre($budgets);

        expect(budgetOf(RecordType::SCHEDULED_TASK, ['status' => 'skipped', 'duration' => 9_000_000]))
            ->toMatchArray(['state' => 'not_evaluated', 'reason' => 'not_run']);
    })->with([
        'a global entry' => [[['type' => 'scheduled-task', 'duration' => 5000]]],
        'no matching entry' => [[['type' => 'scheduled-task', 'name' => 'other', 'duration' => 5000]]],
    ]);

    it('still judges a failed execution', function (RecordType $type, array $fields) {
        budgetsAre([['type' => $type->value, 'duration' => 300]]);

        expect(budgetOf($type, [...$fields, 'duration' => 400_000]))->toMatchArray(['state' => 'exceeded', 'entry' => 1]);
    })->with([
        'a request' => [RecordType::REQUEST, ['status_code' => 500]],
        'a job attempt' => [RecordType::JOB_ATTEMPT, ['status' => 'failed']],
        'a scheduled task' => [RecordType::SCHEDULED_TASK, ['status' => 'failed']],
    ]);

    it('states how many entries were ignored, and only when some were', function (array $budgets, array $expected) {
        budgetsAre($budgets);

        expect(budgetOf(RecordType::COMMAND, ['duration' => 100_000]))->toMatchArray($expected);
    })->with([
        'one ignored beside a governing entry' => [
            [['type' => 'bogus'], ['type' => 'command', 'duration' => 300]],
            ['state' => 'within', 'entry' => 2, 'ignored_entries' => 1],
        ],
        'every entry ignored' => [
            [['type' => 'bogus'], ['duration' => 300]],
            ['state' => 'not_evaluated', 'reason' => 'no_budget_configured', 'ignored_entries' => 2],
        ],
    ]);

    it('leaves ignored_entries out when none were ignored', function () {
        budgetsAre([['type' => 'command', 'duration' => 300]]);

        expect(budgetOf(RecordType::COMMAND))->not->toHaveKey('ignored_entries');
    });
});

describe('the budget note', function () {
    it('says the verdict in one line', function (array $budgets, array $fields, string $state, string $details) {
        budgetsAre($budgets);

        expect(budgetAnswer(RecordType::COMMAND, $fields)['notes'])->toBe([__('firewatch::messages.budget_line', ['state' => $state, 'details' => $details])]);
    })->with([
        'exceeded' => [
            [['type' => 'command', 'duration' => 300, 'memory' => 32]],
            ['duration' => 412_000, 'peak_memory_usage' => 20 * BUDGET_MEGABYTE],
            'exceeded', 'duration 412.00 ms over 300.00 ms; entry 1',
        ],
        'both ceilings exceeded' => [
            [['type' => 'command', 'duration' => 300, 'memory' => 32]],
            ['duration' => 412_000, 'peak_memory_usage' => 40 * BUDGET_MEGABYTE],
            'exceeded', 'duration 412.00 ms over 300.00 ms, memory 40.0 MB over 32.0 MB; entry 1',
        ],
        'within' => [
            [['type' => 'command', 'duration' => 300]],
            ['duration' => 100_000],
            'within', 'entry 1',
        ],
        'not evaluated' => [
            [['type' => 'command', 'name' => 'other', 'duration' => 300]],
            [],
            'not evaluated', 'no_matching_budget',
        ],
        'with ignored entries' => [
            [['type' => 'bogus'], ['type' => 'command', 'duration' => 300]],
            ['duration' => 100_000],
            'within', 'entry 2; ignored_entries: 1',
        ],
    ]);
});
