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

const MEM_AT = 1790776000.0;

const MEM_MB = 1_048_576;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(MEM_AT + 3600));
});

/**
 * Build an execution that peaked at some bytes, or that has no peak.
 *
 * @param  array<string, mixed>  $fields
 */
function memExecution(string $id, ?int $bytes, string $label = '/orders', RecordType $type = RecordType::REQUEST, array $fields = []): RecordBuilder
{
    $labelled = $type === RecordType::REQUEST ? ['route_path' => $label] : ['name' => $label];
    $execution = syntheticRecord($type)->inExecution($id)->with([
        '_group' => md5($label),
        'timestamp' => MEM_AT,
        ...$labelled,
        ...$fields,
    ]);

    return $bytes === null ? $execution->without('peak_memory_usage') : $execution->with(['peak_memory_usage' => $bytes]);
}

/**
 * Build the executions of one group that peaked at each of the megabytes, one second apart.
 *
 * @param  list<int|float>  $megabytes
 * @return list<RecordBuilder>
 */
function memPeaks(array $megabytes, string $label = '/orders'): array
{
    return array_map(
        fn (int|float $peak, int $index) => memExecution("{$label}-{$index}", (int) ($peak * MEM_MB), $label, fields: ['timestamp' => MEM_AT + $index]),
        $megabytes,
        array_keys($megabytes),
    );
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function memAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'memory', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function memRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'memory', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when an execution of a group peaked at 64 megabytes or more, over every execution examined', function () {
        ingest([...memPeaks([10, 64]), ...memPeaks([10], '/health')]);

        $envelope = memAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'memory', 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'memory', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.memory')]))
            ->and($envelope['result']['threshold'])->toBe(['name' => 'peak', 'value' => 64, 'default' => 64, 'unit' => 'mb', 'range' => ['min' => 1, 'max' => null], 'is_default' => true])
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('/orders'));
    });

    it('tells one byte under the threshold from the threshold, in bytes and not in the megabytes shown', function (int|float|null $threshold, int $bytes, string $verdict) {
        ingest([memExecution('one', $bytes)]);

        $arguments = $threshold === null ? [] : ['threshold' => $threshold];

        expect(memAnswer($arguments)['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        'one byte under 64 megabytes' => [null, 67_108_863, 'clean'],
        'exactly 64 megabytes' => [null, 67_108_864, 'findings'],
        'one byte under 64.5 megabytes' => [64.5, 67_633_151, 'clean'],
        'exactly 64.5 megabytes' => [64.5, 67_633_152, 'findings'],
        'one byte under 1 megabyte' => [1, 1_048_575, 'clean'],
        'exactly 1 megabyte' => [1, 1_048_576, 'findings'],
        'one byte under 1.5 megabytes' => [1.5, 1_572_863, 'clean'],
        'exactly 1.5 megabytes' => [1.5, 1_572_864, 'findings'],
    ]);

    it('states the threshold a call passed, and that it is not the default', function () {
        ingest(memPeaks([10]));

        $threshold = memAnswer(['threshold' => 64.5])['result']['threshold'];

        expect($threshold)->toBe(['name' => 'peak', 'value' => 64.5, 'default' => 64, 'unit' => 'mb', 'range' => ['min' => 1, 'max' => null], 'is_default' => false]);
    });

    it('refuses a threshold below one megabyte or that is no number, naming what it accepts', function (mixed $threshold) {
        $refusal = memRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a number of 1 or more');
    })->with([
        'half a megabyte' => [0.5],
        'just under one' => [0.99],
        'zero' => [0],
        'a negative number' => [-64],
        'text' => ['64'],
    ]);

    it('is clean over the executions that have a peak, and says how many', function () {
        ingest(memPeaks([10, 20, 63.9]));

        $envelope = memAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 3, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'memory', 'examined' => 3, 'input' => __('firewatch::messages.detect_input.memory')]));
    });

    it('does not examine an execution that has no peak', function () {
        ingest([
            memExecution('measured', 10 * MEM_MB),
            memExecution('unmeasured', null),
            memExecution('unreadable', null, fields: ['peak_memory_usage' => 'unknown']),
        ]);

        expect(memAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('is not evaluated when no execution has a peak, never clean', function () {
        ingest([memExecution('unmeasured', null), syntheticRecord(RecordType::QUERY)]);

        $envelope = memAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'memory', 'reason' => 'no_records']));
    });

    it('examines the executions of all four types, and finds each', function (RecordType $type, string $label) {
        ingest([memExecution('one', 80 * MEM_MB, $label, $type)]);

        $envelope = memAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0])->toMatchArray(['group' => md5($label), 'name' => $label]);
    })->with([
        'a request' => [RecordType::REQUEST, '/orders'],
        'a command' => [RecordType::COMMAND, 'orders:ship'],
        'a job attempt' => [RecordType::JOB_ATTEMPT, 'ShipOrder'],
        'a scheduled task' => [RecordType::SCHEDULED_TASK, 'prune-orders'],
    ]);

    it('judges the executions that started in the window', function () {
        ingest([
            memExecution('before', 80 * MEM_MB, fields: ['timestamp' => MEM_AT - 100]),
            memExecution('within', 10 * MEM_MB),
        ]);

        $envelope = memAnswer(['since' => (string) (MEM_AT - 1), 'until' => (string) (MEM_AT + 1)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });
});

describe('the finding', function () {
    it('states the group, its highest peak, the executions that reached the threshold and whom they reached', function () {
        ingest([
            memExecution('small', 10 * MEM_MB, fields: ['timestamp' => MEM_AT, 'user' => 'u0']),
            memExecution('highest', 100 * MEM_MB, fields: ['timestamp' => MEM_AT + 60, 'user' => 'u1']),
            memExecution('over', 70 * MEM_MB, fields: ['timestamp' => MEM_AT + 120, 'user' => 'u1']),
            memExecution('latest-over', 80 * MEM_MB, fields: ['timestamp' => MEM_AT + 180, 'user' => '']),
            memExecution('latest', 20 * MEM_MB, fields: ['timestamp' => MEM_AT + 240, 'user' => 'u2']),
        ]);

        $envelope = memAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('/orders'),
            'name' => '/orders',
            'count' => 100.0,
            'first_seen_at' => MEM_AT + 60,
            'last_seen_at' => MEM_AT + 180,
            'latest_execution_id' => 'latest-over',
            'worst_execution_id' => 'highest',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'peak_mb' => 100.0,
                'executions_over' => 3,
                'executions' => 5,
                'p50_mb' => 70.0,
            ],
        ]]);
    });

    it('shows megabytes to one decimal, as the other tools do', function () {
        ingest(memPeaks([64.26, 70.04, 70.06]));

        $evidence = memAnswer()['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['peak_mb' => 70.1, 'p50_mb' => 70.0]);
    });

    it('withholds the median peak below three executions', function (array $megabytes) {
        ingest(memPeaks($megabytes));

        $evidence = memAnswer()['result']['findings'][0]['evidence'];

        expect($evidence['p50_mb'])->toBeNull();
    })->with([
        'one execution' => [[80]],
        'two executions' => [[80, 90]],
    ]);

    it('takes the median peak at the nearest rank, over every execution that has a peak', function (array $megabytes, float $median) {
        ingest([...memPeaks($megabytes), memExecution('unmeasured', null)]);

        $evidence = memAnswer()['result']['findings'][0]['evidence'];

        expect($evidence['p50_mb'])->not->toBeNull()
            ->and($evidence)->toMatchArray(['p50_mb' => $median, 'executions' => count($megabytes)]);
    })->with([
        'three executions take the second' => [[90, 70, 80], 80.0],
        'four executions take the second' => [[100, 10, 30, 20], 20.0],
        'five executions take the third' => [[100, 10, 30, 20, 40], 30.0],
        'an outlier does not move it' => [[5000, 10, 12], 12.0],
    ]);

    it('names as the worst the execution that peaked highest, and the latest of those that tie', function () {
        ingest([
            memExecution('first-highest', 90 * MEM_MB, fields: ['timestamp' => MEM_AT]),
            memExecution('second-highest', 90 * MEM_MB, fields: ['timestamp' => MEM_AT + 60]),
            memExecution('recent', 70 * MEM_MB, fields: ['timestamp' => MEM_AT + 120]),
        ]);

        $finding = memAnswer()['result']['findings'][0];

        expect($finding['worst_execution_id'])->toBe('second-highest')
            ->and($finding['latest_execution_id'])->toBe('recent');
    });

    it('counts the distinct users of the executions that reached the threshold, and those without one apart', function () {
        ingest([
            memExecution('a', 80 * MEM_MB, fields: ['user' => 'u1']),
            memExecution('b', 80 * MEM_MB, fields: ['user' => 'u1']),
            memExecution('c', 80 * MEM_MB, fields: ['user' => 'u2']),
            memExecution('d', 80 * MEM_MB, fields: ['user' => '']),
            memExecution('under', 10 * MEM_MB, fields: ['user' => 'u3']),
            memExecution('under-without', 10 * MEM_MB, fields: ['user' => '']),
        ]);

        expect(memAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });

    it('counts the executions over at the threshold asked for', function () {
        ingest(memPeaks([10, 20, 30]));

        $evidence = memAnswer(['threshold' => 20])['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['peak_mb' => 30.0, 'executions_over' => 2, 'executions' => 3]);
    });

    it('labels the group of requests that matched no route', function () {
        ingest([memExecution('one', 80 * MEM_MB, '')]);

        expect(memAnswer()['result']['findings'][0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
    });
});

describe('the order', function () {
    it('lists the highest peak first, then the most executions over, then the group hash', function () {
        ingest([
            ...memPeaks([80, 80], '/two-over'),
            ...memPeaks([80, 10], '/one-over-b'),
            ...memPeaks([80, 10], '/one-over-a'),
            ...memPeaks([90], '/highest'),
            ...memPeaks([10], '/under'),
        ]);

        $tied = [md5('/one-over-a'), md5('/one-over-b')];
        sort($tied);

        expect(array_column(memAnswer()['result']['findings'], 'group'))->toBe([md5('/highest'), md5('/two-over'), ...$tied]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $route) => memPeaks([64 + $route], "/route-{$route}"), range(1, 4))));

        $envelope = memAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['/route-4', '/route-3', '/route-2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 4, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $route) => memPeaks([80], "/route-{$route}"), range(1, 3))));

        $envelope = memAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('one group', function () {
    it('restricts the judgement to the group, and examines only its executions', function () {
        ingest([...memPeaks([80, 10]), ...memPeaks([90], '/health'), memExecution('command', 90 * MEM_MB, 'orders:ship', RecordType::COMMAND)]);

        $envelope = memAnswer(['group' => md5('/orders')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['/orders']);
    });

    it('restricts the judgement to the group of a command', function () {
        ingest([...memPeaks([80]), memExecution('command', 90 * MEM_MB, 'orders:ship', RecordType::COMMAND)]);

        $envelope = memAnswer(['group' => md5('orders:ship')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['worst_execution_id'])->toBe('command');
    });

    it('is clean when the group has executions and none reached the threshold', function () {
        ingest([...memPeaks([10]), ...memPeaks([90], '/health')]);

        expect(memAnswer(['group' => md5('/orders')])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('answers that no execution matches a group that holds none', function () {
        ingest([...memPeaks([80]), ...memPeaks([10], '/health')]);

        $envelope = memAnswer(['group' => md5('/missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 2]);
    });
});

describe('the caveat and the blind spots', function () {
    it('always says that a peak is not attributable to code', function (array $megabytes, string $verdict) {
        ingest([...memPeaks($megabytes), syntheticRecord(RecordType::QUERY)]);

        $result = memAnswer()['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_memory')]);
    })->with([
        'with findings' => [[80], 'findings'],
        'when clean' => [[10], 'clean'],
        'when not evaluated' => [[], 'not_evaluated'],
    ]);

    it('states that memory is the peak of the whole process, also when nothing was examined', function (array $megabytes) {
        ingest([...memPeaks($megabytes), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(memAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKey('memory-is-process-peak')
            ->and($blindSpots['memory-is-process-peak'])->toBe(__('firewatch::messages.blind_spots.memory-is-process-peak'));
    })->with([
        'with findings' => [[80]],
        'with nothing examined' => [[]],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to the execution that peaked highest, its records and its group, and the calls run', function () {
        ingest([
            memExecution('highest', 90 * MEM_MB, fields: ['timestamp' => MEM_AT]),
            memExecution('recent', 70 * MEM_MB, fields: ['timestamp' => MEM_AT + 60]),
        ]);

        $envelope = memAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'highest'], ['group' => md5('/orders')], ['group' => md5('/orders')]]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, at the default threshold, with its worst finding', function () {
        ingest([...memPeaks([80, 10]), ...memPeaks([90], '/health'), ...memPeaks([63], '/under')]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'memory');

        expect($row)->toBe([
            'detector' => 'memory',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 4,
            'total' => 2,
            'worst' => ['name' => '/health', 'group' => md5('/health')],
        ]);
    });

    it('is run after the others when no shape is named', function () {
        ingest(memPeaks([80]));

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'memory'])
            ->and($detectors[4])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});
