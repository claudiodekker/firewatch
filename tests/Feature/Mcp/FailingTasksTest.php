<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const FTK_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(FTK_AT + 3600));
});

/**
 * Build one scheduled task.
 *
 * @param  array<string, mixed>  $fields
 */
function ftkTask(string $execution, ?string $status, string $name = 'prune-orders', array $fields = []): RecordBuilder
{
    $task = syntheticRecord(RecordType::SCHEDULED_TASK)->inExecution($execution)->with([
        '_group' => md5($name),
        'name' => $name,
        'timestamp' => FTK_AT + 1,
        'status' => $status,
        ...$fields,
    ]);

    return $status === null ? $task->without('status') : $task;
}

/**
 * Build the scheduled tasks of one group that ended with each of the statuses, in order.
 *
 * @param  list<string|null>  $statuses
 * @return list<RecordBuilder>
 */
function ftkTasks(string $name, array $statuses): array
{
    return array_map(
        fn (?string $status, int $index) => ftkTask("{$name}-".($index + 1), $status, $name),
        $statuses,
        array_keys($statuses),
    );
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function ftkAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'failing-tasks', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function ftkRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'failing-tasks', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when a scheduled task of a group failed or was skipped, over every one examined, and states no threshold', function () {
        ingest([...ftkTasks('prune-orders', ['processed', 'failed']), ...ftkTasks('send-digest', ['processed'])]);

        $envelope = ftkAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'failing-tasks', 'threshold' => null, 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'failing-tasks', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-tasks')]))
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('prune-orders'));
    });

    it('takes one failed or one skipped scheduled task for a finding, and one with another status or none for none', function (?string $status, string $verdict) {
        ingest([ftkTask('a', $status)]);

        expect(ftkAnswer()['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        'processed' => ['processed', 'clean'],
        'failed' => ['failed', 'findings'],
        'skipped' => ['skipped', 'findings'],
        'a status it does not know' => ['released', 'clean'],
        'no status' => [null, 'clean'],
    ]);

    it('refuses a threshold, whatever its value, since the shape takes none', function (mixed $threshold) {
        ingest([ftkTask('a', 'failed')]);

        $refusal = ftkRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: conflicting_arguments')
            ->toContain('argument: threshold')
            ->toContain('shape: failing-tasks')
            ->toContain('detect(shape: "failing-tasks")');
    })->with([
        'a whole number' => [1],
        'zero' => [0],
        'a fraction' => [1.5],
        'text' => ['1'],
    ]);

    it('is clean over the scheduled tasks examined when none failed or was skipped, and says how many', function () {
        ingest([...ftkTasks('prune-orders', ['processed']), ...ftkTasks('send-digest', ['processed'])]);

        $envelope = ftkAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'failing-tasks', 'examined' => 2, 'input' => __('firewatch::messages.detect_input.failing-tasks')]));
    });

    it('is not evaluated when the window holds no scheduled task, never clean', function () {
        ingest([syntheticRecord(RecordType::REQUEST), syntheticRecord(RecordType::COMMAND)]);

        $envelope = ftkAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'failing-tasks', 'reason' => 'no_records']));
    });

    it('judges the scheduled tasks that started in the window, the start included and the end not', function () {
        ingest([
            ftkTask('before', 'failed', fields: ['timestamp' => FTK_AT - 1]),
            ftkTask('at-since', 'processed', fields: ['timestamp' => FTK_AT]),
            ftkTask('at-until', 'failed', fields: ['timestamp' => FTK_AT + 10]),
        ]);

        $envelope = ftkAnswer(['since' => (string) FTK_AT, 'until' => (string) (FTK_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('finds a scheduled task that failed at the start of the window, and not one that failed at its end', function () {
        ingest([
            ftkTask('at-since', 'failed', fields: ['timestamp' => FTK_AT]),
            ftkTask('at-until', 'skipped', 'send-digest', ['timestamp' => FTK_AT + 10]),
        ]);

        $envelope = ftkAnswer(['since' => (string) FTK_AT, 'until' => (string) (FTK_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['latest_execution_id'])->toBe('at-since');
    });
});

describe('the finding', function () {
    it('states the group, its failed and skipped scheduled tasks among all of them, and the latest of those', function () {
        ingest([
            ftkTask('worked', 'processed', fields: ['timestamp' => FTK_AT]),
            ftkTask('skipped-first', 'skipped', fields: ['timestamp' => FTK_AT + 60]),
            ftkTask('failed-first', 'failed', fields: ['timestamp' => FTK_AT + 120]),
            ftkTask('failed-last', 'failed', fields: ['timestamp' => FTK_AT + 180]),
            ftkTask('skipped-last', 'skipped', fields: ['timestamp' => FTK_AT + 240]),
            ftkTask('late', 'processed', fields: ['timestamp' => FTK_AT + 300]),
        ]);

        $envelope = ftkAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('prune-orders'),
            'name' => 'prune-orders',
            'count' => 4,
            'first_seen_at' => FTK_AT + 60,
            'last_seen_at' => FTK_AT + 240,
            'latest_execution_id' => 'skipped-last',
            'reaches' => ['signed_in_actors' => 0, 'without_actor' => 4],
            'evidence' => [
                'kind' => 'failed',
                'failed' => 2,
                'skipped' => 2,
                'runs' => 6,
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('points at the latest skipped scheduled task of a group in which none failed, and at the one stored last of two that started together', function () {
        ingest([
            ftkTask('a', 'skipped', fields: ['timestamp' => FTK_AT + 5]),
            ftkTask('b', 'skipped', fields: ['timestamp' => FTK_AT + 9]),
            ftkTask('c', 'skipped', fields: ['timestamp' => FTK_AT + 9]),
            ftkTask('d', 'skipped', fields: ['timestamp' => FTK_AT + 7]),
            ftkTask('e', 'processed', fields: ['timestamp' => FTK_AT + 20]),
            ftkTask('f', null, fields: ['timestamp' => FTK_AT + 30]),
        ]);

        $finding = ftkAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['latest_execution_id' => 'c', 'first_seen_at' => FTK_AT + 5, 'last_seen_at' => FTK_AT + 9]);
    });

    it('names the group after its latest scheduled task that failed or was skipped', function () {
        ingest([
            ftkTask('a', 'failed', fields: ['name' => 'old-name', 'timestamp' => FTK_AT + 1]),
            ftkTask('b', 'failed', fields: ['name' => 'new-name', 'timestamp' => FTK_AT + 2]),
            ftkTask('c', 'skipped', fields: ['name' => 'skipped-name', 'timestamp' => FTK_AT + 3]),
            ftkTask('d', 'processed', fields: ['name' => 'zebra', 'timestamp' => FTK_AT + 4]),
        ]);

        expect(ftkAnswer()['result']['findings'][0])->toMatchArray(['group' => md5('prune-orders'), 'name' => 'skipped-name']);
    });

    it('labels a group whose scheduled task has no name', function () {
        ingest([ftkTask('a', 'failed', fields: ['name' => ''])]);

        expect(ftkAnswer()['result']['findings'][0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
    });
});

describe('the kind', function () {
    it('is failed for a group with a failed scheduled task, and skipped only for one with skips alone', function (array $statuses, string $kind, int $count) {
        ingest(ftkTasks('prune-orders', $statuses));

        $finding = ftkAnswer()['result']['findings'][0];

        expect($finding['evidence']['kind'])->toBe($kind)
            ->and($finding['count'])->toBe($count);
    })->with([
        'one failed' => [['failed'], 'failed', 1],
        'one skipped' => [['skipped'], 'skipped_only', 1],
        'one failed among skips' => [['skipped', 'failed', 'skipped'], 'failed', 3],
        'skips among those that worked' => [['processed', 'skipped', 'skipped', 'processed'], 'skipped_only', 2],
        'one failed among those that worked' => [['processed', 'failed'], 'failed', 1],
    ]);
});

describe('the order', function () {
    it('lists a group with a failed scheduled task before one that was only skipped, however often', function () {
        ingest([
            ...ftkTasks('skips', ['skipped', 'skipped', 'skipped', 'skipped']),
            ...ftkTasks('fails', ['failed']),
            ...ftkTasks('works', ['processed']),
        ]);

        $findings = ftkAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['fails', 'skips'])
            ->and(array_column(array_column($findings, 'evidence'), 'kind'))->toBe(['failed', 'skipped_only'])
            ->and(array_column($findings, 'count'))->toBe([1, 4]);
    });

    it('lists the most failed first, whatever was skipped, then the most skipped', function () {
        ingest([
            ...ftkTasks('once-and-skips', ['failed', 'skipped', 'skipped', 'skipped']),
            ...ftkTasks('twice', ['failed', 'failed']),
            ...ftkTasks('once', ['failed']),
            ...ftkTasks('once-and-a-skip', ['failed', 'skipped']),
            ...ftkTasks('two-skips', ['skipped', 'skipped']),
            ...ftkTasks('a-skip', ['skipped']),
        ]);

        expect(array_column(ftkAnswer()['result']['findings'], 'name'))->toBe(['twice', 'once-and-skips', 'once-and-a-skip', 'once', 'two-skips', 'a-skip']);
    });

    it('lists groups that tie by their group hash, whichever failed last', function () {
        ingest([
            ftkTask('a', 'failed', 'tied-a', ['timestamp' => FTK_AT + 30]),
            ftkTask('b', 'failed', 'tied-b', ['timestamp' => FTK_AT + 10]),
            ftkTask('c', 'failed', 'tied-c', ['timestamp' => FTK_AT + 20]),
        ]);

        $tied = [md5('tied-a'), md5('tied-b'), md5('tied-c')];
        sort($tied, SORT_STRING);

        expect(array_column(ftkAnswer()['result']['findings'], 'group'))->toBe($tied);
    });

    it('orders two group hashes that read as the same number as text', function () {
        $later = '0e'.str_repeat('2', 30);
        $earlier = '0e'.str_repeat('1', 30);

        ingest([
            ftkTask('a', 'failed', 'later', ['_group' => $later]),
            ftkTask('b', 'failed', 'earlier', ['_group' => $earlier]),
        ]);

        expect(array_column(ftkAnswer()['result']['findings'], 'group'))->toBe([$earlier, $later]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $task) => ftkTasks("task-{$task}", array_fill(0, $task, 'failed')), range(1, 4))));

        $envelope = ftkAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['task-4', 'task-3', 'task-2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 10, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $task) => ftkTasks("task-{$task}", ['skipped']), range(1, 3))));

        $envelope = ftkAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('one group', function () {
    it('restricts the judgement to the group, and examines only its scheduled tasks', function () {
        ingest([...ftkTasks('prune-orders', ['skipped', 'processed']), ...ftkTasks('send-digest', ['failed', 'failed'])]);

        $envelope = ftkAnswer(['group' => md5('prune-orders')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['prune-orders'])
            ->and($envelope['result']['findings'][0]['evidence'])->toBe(['kind' => 'skipped_only', 'failed' => 0, 'skipped' => 1, 'runs' => 2]);
    });

    it('is clean when the group has scheduled tasks and none failed or was skipped', function () {
        ingest([...ftkTasks('prune-orders', ['processed']), ...ftkTasks('send-digest', ['failed'])]);

        expect(ftkAnswer(['group' => md5('prune-orders')])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
    });

    it('answers that no scheduled task matches a group that holds none', function () {
        ingest([...ftkTasks('prune-orders', ['failed']), ...ftkTasks('send-digest', ['processed'])]);

        $envelope = ftkAnswer(['group' => md5('missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 2]);
    });
});

describe('the caveats', function () {
    it('says that a skip is often intended and that a task that did not fire leaves no record, whatever the verdict', function (array $statuses, array $arguments, string $verdict) {
        ingest([...ftkTasks('prune-orders', $statuses), syntheticRecord(RecordType::QUERY)]);

        $result = ftkAnswer($arguments)['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_skipped'), __('firewatch::messages.detect_caveat_not_fired')]);
    })->with([
        'with a failed scheduled task' => [['failed'], [], 'findings'],
        'with a skipped scheduled task' => [['skipped'], [], 'findings'],
        'when clean' => [['processed'], [], 'clean'],
        'with nothing examined' => [[], [], 'not_evaluated'],
        'for a group that holds none' => [['failed'], ['group' => md5('missing')], 'not_evaluated'],
    ]);
});

describe('the blind spots', function () {
    it('states what a scheduled task does not carry, also when nothing was examined', function (array $statuses) {
        ingest([...ftkTasks('prune-orders', $statuses), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(ftkAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['dead-counters', 'memory-is-process-peak', 'actor-partial'])
            ->and($blindSpots['dead-counters'])->toBe(__('firewatch::messages.blind_spots.dead-counters'))
            ->and($blindSpots['memory-is-process-peak'])->toBe(__('firewatch::messages.blind_spots.memory-is-process-peak'))
            ->and($blindSpots['actor-partial'])->toBe(__('firewatch::messages.blind_spots.actor-partial'));
    })->with([
        'with findings' => [['failed']],
        'when clean' => [['processed']],
        'with nothing examined' => [[]],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to its latest failed or skipped scheduled task, its records and its group, then the next finding, and the calls run', function () {
        ingest([
            ftkTask('a', 'failed', fields: ['timestamp' => FTK_AT + 5]),
            ftkTask('b', 'skipped', fields: ['timestamp' => FTK_AT + 10]),
            ftkTask('c', 'skipped', 'send-digest'),
            ftkTask('d', 'processed', 'send-digest', ['timestamp' => FTK_AT + 50]),
        ]);

        $envelope = ftkAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'b'], ['group' => md5('prune-orders')], ['group' => md5('prune-orders')], ['execution_id' => 'c']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, with its worst finding', function () {
        ingest([
            ...ftkTasks('prune-orders', ['skipped', 'skipped', 'processed']),
            ...ftkTasks('send-digest', ['failed']),
            ...ftkTasks('works', ['processed']),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'failing-tasks');

        expect($row)->toBe([
            'detector' => 'failing-tasks',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 5,
            'total' => 2,
            'worst' => ['name' => 'send-digest', 'group' => md5('send-digest')],
        ]);
    });

    it('is run between queue-latency and memory when no shape is named', function () {
        ingest([ftkTask('a', 'failed')]);

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'memory'])
            ->and($detectors[5])->toMatchArray(['threshold' => null, 'verdict' => 'findings', 'total' => 1]);
    });
});
