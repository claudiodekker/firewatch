<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const TRACE_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(TRACE_AT - 3600));
});

/**
 * @param  array<string, mixed>  $fields
 */
function trcRequest(string $trace = 'trace', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::REQUEST)->inExecution($trace)->with([
        'timestamp' => TRACE_AT,
        'duration' => 2_000_000,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function trcCommand(string $trace, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::COMMAND)->inExecution($trace)->with([
        'timestamp' => TRACE_AT,
        'duration' => 2_000_000,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function trcAttempt(string $job, string $attempt, int $number = 1, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::JOB_ATTEMPT)->inExecution($attempt)->with([
        'job_id' => $job,
        'attempt' => $number,
        'trace_id' => 'trace',
        'status' => 'processed',
        'timestamp' => TRACE_AT + 1,
        'duration' => 1_000_000,
        ...$fields,
    ]);
}

/**
 * The record of a dispatch ends at TRACE_AT + 0.125: Nightwatch stamps it when it ends.
 *
 * @param  array<string, mixed>  $fields
 */
function trcDispatch(string $job, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::QUEUED_JOB)->with([
        'job_id' => $job,
        'trace_id' => 'trace',
        'execution_id' => 'trace',
        'connection' => 'redis',
        'queue' => 'default',
        'timestamp' => TRACE_AT + 0.125,
        'duration' => 125_000,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function trcAnswer(array $arguments = ['trace_id' => 'trace']): array
{
    return Envelope::assert(Trace::class, $arguments);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function trcRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Trace::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

/**
 * @param  array<string, mixed>  $envelope
 * @return array<string, mixed>
 */
function trcJob(array $envelope, string $job): array
{
    $jobs = array_values(array_filter($envelope['result']['jobs'], fn (array $row) => $row['job_id'] === $job));

    return $jobs[0];
}

dataset('trace inline connections', [
    'sync' => ['sync'],
    'deferred' => ['deferred'],
    'background' => ['background'],
    'null' => ['null'],
]);

describe('the executions of a trace', function () {
    it('lists the executions that share the trace in the order they started', function () {
        ingest([
            trcAttempt('job', 'attempt', fields: ['timestamp' => TRACE_AT + 5]),
            trcCommand('other-trace'),
            trcRequest('trace'),
            trcAttempt('other-job', 'second', fields: ['timestamp' => TRACE_AT + 3]),
        ]);

        $envelope = trcAnswer();

        expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['trace', 'second', 'attempt']);
    });

    it('breaks a tie on the start time by the record stored first', function () {
        ingest([
            trcAttempt('job-a', 'first', fields: ['timestamp' => TRACE_AT]),
            trcAttempt('job-b', 'second', fields: ['timestamp' => TRACE_AT]),
        ]);

        $envelope = trcAnswer();

        expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['first', 'second']);
    });

    it('describes each execution: its source, label, start, duration and how it ended', function (RecordType $type, array $fields, array $expected) {
        ingest([
            syntheticRecord($type)->inExecution($expected['execution_id'])->with([
                'trace_id' => 'trace',
                'timestamp' => TRACE_AT,
                'duration' => 2_000_000,
                ...$fields,
            ]),
        ]);

        $envelope = trcAnswer();

        expect($envelope['result']['executions'])->toEqual([[
            'execution_id' => $expected['execution_id'],
            'source' => $expected['source'],
            'label' => $expected['label'],
            'started_at' => TRACE_AT,
            'duration_ms' => 2000.0,
            'outcome' => $expected['outcome'],
        ]]);
    })->with([
        'a request' => [RecordType::REQUEST, ['route_path' => '/orders', 'status_code' => 201], ['execution_id' => 'trace', 'source' => 'request', 'label' => '/orders', 'outcome' => 201]],
        'a request that matched no route' => [RecordType::REQUEST, ['route_path' => '', 'status_code' => 404], fn () => ['execution_id' => 'trace', 'source' => 'request', 'label' => __('firewatch::messages.rank_no_route'), 'outcome' => 404]],
        'a command' => [RecordType::COMMAND, ['name' => 'orders:sync', 'exit_code' => 1], ['execution_id' => 'trace', 'source' => 'command', 'label' => 'orders:sync', 'outcome' => 1]],
        'a job attempt' => [RecordType::JOB_ATTEMPT, ['name' => 'App\\Jobs\\Ship', 'status' => 'failed'], ['execution_id' => 'attempt', 'source' => 'job', 'label' => 'App\\Jobs\\Ship', 'outcome' => 'failed']],
        'a scheduled task' => [RecordType::SCHEDULED_TASK, ['name' => 'inspire', 'status' => 'processed'], ['execution_id' => 'trace', 'source' => 'schedule', 'label' => 'inspire', 'outcome' => 'processed']],
    ]);

    it('has a null duration where the record has none', function () {
        ingest([trcRequest(fields: ['duration' => null])]);

        $envelope = trcAnswer();

        expect($envelope['result']['executions'][0]['duration_ms'])->toBeNull();
    });

    it('lists at most the limit, and says how many there are', function () {
        ingest([
            trcRequest(),
            ...array_map(fn (int $number) => trcAttempt("job-{$number}", "attempt-{$number}", fields: ['timestamp' => TRACE_AT + $number]), range(1, 4)),
        ]);

        $envelope = trcAnswer(['trace_id' => 'trace', 'limit' => 3]);

        expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['trace', 'attempt-1', 'attempt-2'])
            ->and($envelope['truncated'])->toBe([[
                'section' => 'executions',
                'shown' => 3,
                'matched' => 5,
                'reason' => 'limit',
                'how' => __('firewatch::messages.trace_executions_how'),
            ]]);
    });

    it('lists exactly the limit as complete', function () {
        ingest([trcRequest(), trcAttempt('job', 'attempt')]);

        $envelope = trcAnswer(['trace_id' => 'trace', 'limit' => 2]);

        expect($envelope['result']['executions'])->toHaveCount(2)
            ->and($envelope['truncated'])->toBe([]);
    });

    it('lists fifty executions by default', function () {
        ingest(array_map(fn (int $number) => trcAttempt("job-{$number}", "attempt-{$number}", fields: ['timestamp' => TRACE_AT + $number / 100]), range(1, 51)));

        $envelope = trcAnswer();

        expect($envelope['result']['executions'])->toHaveCount(50)
            ->and($envelope['truncated'][0]['matched'])->toBe(51);
    });

    it('does not cap the lineage of the jobs by the limit', function () {
        ingest([
            trcRequest(),
            trcDispatch('job-a'),
            trcDispatch('job-b'),
            trcDispatch('job-c'),
        ]);

        $envelope = trcAnswer(['trace_id' => 'trace', 'limit' => 1]);

        expect($envelope['result']['jobs'])->toHaveCount(3);
    });

    it('cuts the jobs of a trace that dispatched hundreds from the tail to fit the answer, and says so', function () {
        ingest([
            trcRequest(),
            ...array_map(fn (int $number) => trcDispatch(sprintf('job-%03d', $number), ['timestamp' => TRACE_AT + 0.125 + $number / 1000]), range(1, 300)),
        ]);

        $envelope = trcAnswer();
        $shown = array_column($envelope['result']['jobs'], 'job_id');

        expect($envelope['result']['executions'])->toHaveCount(1)
            ->and(count($shown))->toBeGreaterThan(0)->toBeLessThan(300)
            ->and($shown)->toBe(array_map(fn (int $number) => sprintf('job-%03d', $number), range(1, count($shown))))
            ->and($envelope['truncated'])->toBe([[
                'section' => 'jobs',
                'shown' => count($shown),
                'matched' => 300,
                'reason' => 'size',
                'how' => __('firewatch::messages.size_how', ['characters' => '24,000']),
            ]])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.trace_summary', ['id' => 'trace', 'executions' => '1 execution', 'jobs' => '300 queued jobs']));
    });
});

describe('the lineage of a queued job', function () {
    it('follows a job from its dispatch through its attempts, with the wait before each', function () {
        ingest([
            trcRequest(),
            trcDispatch('job', ['name' => 'App\\Jobs\\Ship', 'connection' => 'redis', 'queue' => 'shipping']),
            trcAttempt('job', 'attempt-1', 1, ['timestamp' => TRACE_AT + 1, 'duration' => 1_000_000, 'status' => 'released']),
            trcAttempt('job', 'attempt-2', 2, ['timestamp' => TRACE_AT + 5, 'duration' => 2_000_000, 'status' => 'processed']),
        ]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job)->toEqual([
            'job_id' => 'job',
            'name' => 'App\\Jobs\\Ship',
            'lineage' => 'complete',
            'outcome' => 'processed',
            'dispatch' => [
                'execution_id' => 'trace',
                'connection' => 'redis',
                'queue' => 'shipping',
                'started_at' => TRACE_AT,
                'duration_ms' => 125.0,
            ],
            'attempts' => [
                ['attempt' => 1, 'execution_id' => 'attempt-1', 'status' => 'released', 'started_at' => TRACE_AT + 1, 'duration_ms' => 1000.0, 'trace_id' => null, 'wait_ms' => 875.0],
                ['attempt' => 2, 'execution_id' => 'attempt-2', 'status' => 'processed', 'started_at' => TRACE_AT + 5, 'duration_ms' => 2000.0, 'trace_id' => null, 'wait_ms' => 3000.0],
            ],
        ]);
    });

    it('takes the outcome from the last attempt', function (string $status, string $outcome) {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'attempt-1', 1, ['status' => 'released']),
            trcAttempt('job', 'attempt-2', 2, ['status' => $status, 'timestamp' => TRACE_AT + 5]),
        ]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job['outcome'])->toBe($outcome);
    })->with([
        'processed' => ['processed', 'processed'],
        'failed' => ['failed', 'failed'],
        'released to run again' => ['released', 'retrying'],
    ]);

    it('has no outcome when the last attempt ended in a status that is none of the three', function () {
        ingest([trcDispatch('job'), trcAttempt('job', 'attempt', 1, ['status' => null])]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job['outcome'])->toBeNull();
    });

    it('orders attempts by when they started, whatever order they were stored in', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'second', 2, ['timestamp' => TRACE_AT + 5]),
            trcAttempt('job', 'first', 1, ['timestamp' => TRACE_AT + 1]),
        ]);

        $job = trcJob(trcAnswer(), 'job');

        expect(array_column($job['attempts'], 'execution_id'))->toBe(['first', 'second'])
            ->and($job['attempts'][0]['wait_ms'])->toEqual(875.0);
    });

    it('waits from the end of the previous attempt, whichever way it ended', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'first', 1, ['timestamp' => TRACE_AT + 1, 'duration' => 3_000_000, 'status' => 'failed']),
            trcAttempt('job', 'second', 2, ['timestamp' => TRACE_AT + 4.5]),
        ]);

        $job = trcJob(trcAnswer(), 'job');

        expect(array_column($job['attempts'], 'wait_ms'))->toEqual([875.0, 500.0]);
    });

    it('keeps a negative wait, unclamped, when an attempt starts before its dispatch ended', function () {
        ingest([trcDispatch('job'), trcAttempt('job', 'early', 1, ['timestamp' => TRACE_AT + 0.1])]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job['attempts'][0]['wait_ms'])->toEqual(-25.0);
    });

    it('has a null wait for the first attempt of a job whose dispatch is missing', function () {
        ingest([trcAttempt('job', 'first', 1), trcAttempt('job', 'second', 2, ['timestamp' => TRACE_AT + 3])]);

        $job = trcJob(trcAnswer(), 'job');

        expect(array_column($job['attempts'], 'wait_ms'))->toEqual([null, 1000.0])
            ->and($job['dispatch'])->toBeNull()
            ->and($job['lineage'])->toBe('no_dispatch')
            ->and($job['outcome'])->toBe('processed');
    });

    it('has a null wait where the end of what it waits for is unknown', function () {
        ingest([
            trcDispatch('with-dispatch', ['duration' => null]),
            trcAttempt('with-dispatch', 'first', 1, ['duration' => null]),
            trcAttempt('with-dispatch', 'second', 2, ['timestamp' => TRACE_AT + 3]),
        ]);

        $job = trcJob(trcAnswer(), 'with-dispatch');

        expect(array_column($job['attempts'], 'wait_ms'))->toBe([null, null])
            ->and($job['dispatch']['duration_ms'])->toBeNull();
    });

    it('names the trace of an attempt only when it differs from the one asked for, and joins on the job id', function () {
        ingest([
            trcRequest(),
            trcDispatch('job'),
            trcAttempt('job', 'same', 1, ['status' => 'released']),
            trcAttempt('job', 'elsewhere', 2, ['trace_id' => 'worker-trace', 'timestamp' => TRACE_AT + 5]),
        ]);

        $envelope = trcAnswer();

        expect(array_column(trcJob($envelope, 'job')['attempts'], 'trace_id'))->toBe([null, 'worker-trace'])
            ->and(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['trace', 'same']);
    });

    it('follows the jobs an execution in the trace ran as well as the ones it dispatched', function () {
        ingest([
            trcAttempt('ran-here', 'attempt', 1, ['trace_id' => 'worker-trace']),
            trcDispatch('ran-here', ['trace_id' => 'dispatch-trace', 'execution_id' => 'dispatching']),
        ]);

        $envelope = trcAnswer(['trace_id' => 'worker-trace']);

        expect(array_column($envelope['result']['jobs'], 'job_id'))->toBe(['ran-here'])
            ->and($envelope['result']['jobs'][0]['dispatch']['execution_id'])->toBe('dispatching');
    });

    it('shows a dispatch without attempts as pending, with no wait to show', function () {
        ingest([trcRequest(), trcDispatch('job', ['connection' => 'redis'])]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job)->toMatchArray(['lineage' => 'no_attempts', 'outcome' => 'pending', 'attempts' => []]);
    });

    it('shows a dispatch on an inline connection as having no attempts and no outcome, never pending', function (string $connection) {
        ingest([trcRequest(), trcDispatch('job', ['connection' => $connection])]);

        $envelope = trcAnswer();
        $job = trcJob($envelope, 'job');

        expect($job)->toMatchArray(['lineage' => 'no_attempts', 'outcome' => null, 'attempts' => []])
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded');
    })->with('trace inline connections');

    it('lists the jobs in the order their lineage began', function () {
        ingest([
            trcDispatch('later', ['timestamp' => TRACE_AT + 3]),
            trcAttempt('no-dispatch', 'attempt', 1, ['timestamp' => TRACE_AT + 1]),
            trcDispatch('first', ['timestamp' => TRACE_AT + 0.125]),
        ]);

        $envelope = trcAnswer();

        expect(array_column($envelope['result']['jobs'], 'job_id'))->toBe(['first', 'no-dispatch', 'later']);
    });

    it('uses the earliest dispatch of a job that was dispatched again', function () {
        ingest([
            trcDispatch('job', ['timestamp' => TRACE_AT + 2, 'queue' => 'again']),
            trcDispatch('job', ['queue' => 'first']),
            trcAttempt('job', 'attempt', 1, ['timestamp' => TRACE_AT + 3]),
        ]);

        $job = trcJob(trcAnswer(), 'job');

        expect($job['dispatch']['queue'])->toBe('first')
            ->and($job['attempts'][0]['wait_ms'])->toEqual(2875.0);
    });

    it('states each kind of partial lineage once, with the fixed wording', function () {
        ingest([
            trcDispatch('only-dispatch'),
            trcDispatch('also-only-dispatch'),
            trcAttempt('only-attempts', 'attempt', 1),
            trcAttempt('also-only-attempts', 'other-attempt', 1),
        ]);

        $envelope = trcAnswer();

        expect($envelope['notes'])->toBe([
            __('firewatch::messages.trace_partial_no_attempts'),
            __('firewatch::messages.trace_partial_no_dispatch'),
        ]);
    });

    it('does not call a dispatch on an inline connection a partial lineage', function (string $connection) {
        ingest([trcRequest(), trcDispatch('job', ['connection' => $connection])]);

        $envelope = trcAnswer();

        expect($envelope['notes'])->toBe([]);
    })->with('trace inline connections');

    it('has no jobs for a trace that queued none', function () {
        ingest([trcRequest()]);

        $envelope = trcAnswer();

        expect($envelope['result']['jobs'])->toBe([]);
    });
});

describe('by job id', function () {
    it('starts from the dispatch and shows the executions of its trace and the lineage of the job alone', function () {
        ingest([
            trcRequest(),
            trcDispatch('job'),
            trcDispatch('other-job'),
            trcAttempt('job', 'attempt', 1, ['trace_id' => 'worker-trace']),
        ]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect($envelope['result']['trace_id'])->toBe('trace')
            ->and($envelope['result']['job_id'])->toBe('job')
            ->and(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['trace'])
            ->and(array_column($envelope['result']['jobs'], 'job_id'))->toBe(['job'])
            ->and($envelope['result']['jobs'][0]['attempts'][0]['trace_id'])->toBe('worker-trace');
    });

    it('starts from the first attempt, and its trace, when the dispatch is missing', function () {
        ingest([
            trcAttempt('job', 'later', 2, ['timestamp' => TRACE_AT + 5, 'trace_id' => 'later-trace']),
            trcAttempt('job', 'first', 1, ['timestamp' => TRACE_AT + 1, 'trace_id' => 'first-trace']),
        ]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect($envelope['result']['trace_id'])->toBe('first-trace')
            ->and(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['first']);
    });

    it('names no trace for a trace request, and no job for a job request', function () {
        ingest([trcRequest()]);

        $envelope = trcAnswer();

        expect($envelope['result']['trace_id'])->toBe('trace')
            ->and($envelope['result']['job_id'])->toBeNull();
    });

    it('names the trace of an attempt only when it is not the one the dispatch started', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'same', 1, ['status' => 'released']),
            trcAttempt('job', 'elsewhere', 2, ['trace_id' => 'worker-trace', 'timestamp' => TRACE_AT + 5]),
        ]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect(array_column($envelope['result']['jobs'][0]['attempts'], 'trace_id'))->toBe([null, 'worker-trace']);
    });
});

describe('a trace with no execution in the store', function () {
    it('answers with no executions and the counts of the records that carry the trace, not not_found', function () {
        ingest([
            syntheticRecord(RecordType::QUERY)->with(['trace_id' => 'lost', 'execution_id' => 'lost']),
            syntheticRecord(RecordType::QUERY)->with(['trace_id' => 'lost', 'execution_id' => 'lost']),
            syntheticRecord(RecordType::LOG)->with(['trace_id' => 'lost', 'execution_id' => 'lost']),
            trcRequest('elsewhere'),
        ]);

        $envelope = trcAnswer(['trace_id' => 'lost']);

        expect($envelope['empty'])->toBeNull()
            ->and($envelope['result']['executions'])->toBe([])
            ->and($envelope['result']['jobs'])->toBe([])
            ->and($envelope['notes'])->toBe([__('firewatch::messages.trace_no_execution', ['id' => 'lost', 'counts' => '2 query, 1 log'])]);
    });

    it('offers the records that carry the trace', function () {
        ingest([syntheticRecord(RecordType::QUERY)->with(['trace_id' => 'lost', 'execution_id' => 'lost'])]);

        $envelope = trcAnswer(['trace_id' => 'lost']);
        $call = $envelope['next'][0];
        $listed = Envelope::assert(Occurrences::class, $call['arguments']);

        expect($envelope['next'])->toHaveCount(1)
            ->and($call)->toBe(['tool' => 'occurrences', 'arguments' => ['trace_id' => 'lost'], 'why' => __('firewatch::messages.trace_next_occurrences')])
            ->and($listed['result']['rows'])->toHaveCount(1);
    });

    it('answers a job whose dispatching execution is missing with no executions, its lineage and the records of the job', function () {
        ingest([trcDispatch('job', ['connection' => 'redis'])]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect($envelope['result']['executions'])->toBe([])
            ->and($envelope['result']['jobs'][0]['job_id'])->toBe('job')
            ->and($envelope['notes'])->toContain(__('firewatch::messages.trace_no_execution', ['id' => 'trace', 'counts' => '1 queued-job']))
            ->and($envelope['next'])->toBe([['tool' => 'occurrences', 'arguments' => ['job_id' => 'job'], 'why' => __('firewatch::messages.trace_next_occurrences')]]);
    });
});

describe('what is not found', function () {
    it('refuses a trace id nothing in the store holds', function () {
        ingest([trcRequest('other')]);

        $text = trcRefusal(['trace_id' => 'missing']);

        expect($text)->toBe(__('firewatch::messages.not_found', ['id' => 'missing', 'argument' => 'trace_id', 'accepted' => 'a trace id', 'example' => 'trace(trace_id: "<trace id>")']));
    });

    it('refuses a job id nothing in the store holds', function () {
        ingest([trcRequest()]);

        $text = trcRefusal(['job_id' => 'missing']);

        expect($text)->toBe(__('firewatch::messages.not_found', ['id' => 'missing', 'argument' => 'job_id', 'accepted' => 'a job id', 'example' => 'trace(job_id: "<job id>")']));
    });

    it('answers that the store is empty rather than refusing an id it cannot hold', function () {
        app(Writer::class)->transaction(fn () => null);

        $envelope = trcAnswer(['trace_id' => 'abc']);

        expect($envelope['empty']['kind'])->toBe('store_empty')
            ->and($envelope['result'])->toBe([])
            ->and($envelope['next'])->toBe([]);
    });

    it('answers that there is no store, and says what the call read', function () {
        $envelope = trcAnswer(['trace_id' => 'abc']);

        expect($envelope['empty']['kind'])->toBe('no_store')
            ->and($envelope['coverage']['state'])->toBe('absent')
            ->and($envelope['coverage']['types_read'])->toBe(['request', 'command', 'job-attempt', 'scheduled-task', 'queued-job']);
    });

    it('answers that the store is unusable, with why', function () {
        $path = app(Configuration::class)->database;
        mkdir(dirname($path), recursive: true);
        file_put_contents($path, str_repeat('not a database ', 100));

        $envelope = trcAnswer(['trace_id' => 'abc']);

        expect($envelope['empty']['kind'])->toBe('store_unusable');
    });
});

describe('arguments', function () {
    it('refuses a call with neither a trace id nor a job id', function () {
        $text = trcRefusal([]);

        expect($text)->toBe(__('firewatch::messages.missing_argument', ['argument' => 'trace_id', 'accepted' => 'exactly one of `trace_id` or `job_id`', 'example' => 'trace(trace_id: "<trace id>")']));
    });

    it('refuses a call with both', function () {
        $text = trcRefusal(['trace_id' => 'a', 'job_id' => 'b']);

        expect($text)->toBe(__('firewatch::messages.conflicting_arguments', ['argument' => 'job_id', 'with' => 'trace_id', 'accepted' => 'a call with `trace_id` or with `job_id`, not both', 'example' => 'trace(trace_id: "<trace id>")']));
    });

    it('refuses an id that is not a non-empty string', function (string $argument, mixed $id, string $shown) {
        $text = trcRefusal([$argument => $id]);

        expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => $argument, 'expected' => 'a non-empty string', 'value' => $shown, 'accepted' => $argument === 'trace_id' ? 'a trace id' : 'a job id', 'example' => "trace({$argument}: \"<".str_replace('_id', ' id', $argument).'>")']));
    })->with([
        'an empty trace id' => ['trace_id', '', '""'],
        'a numeric trace id' => ['trace_id', 5, '5'],
        'an empty job id' => ['job_id', '', '""'],
        'a list as a job id' => ['job_id', ['a'], '["a"]'],
    ]);

    it('accepts a limit from 1 to 100 and refuses any other', function (mixed $limit, bool $accepted) {
        ingest([trcRequest()]);

        $text = trcRefusal(['trace_id' => 'trace', 'limit' => $limit]);

        expect(str_starts_with($text, 'error: invalid_argument'))->toBe(! $accepted);
    })->with([
        'zero' => [0, false],
        'one' => [1, true],
        'a hundred' => [100, true],
        'a hundred and one' => [101, false],
        'negative' => [-1, false],
        'a fraction' => [5.5, false],
        'a string' => ['5', false],
    ]);

    it('words the refusal of a limit with the range and an example', function () {
        $text = trcRefusal(['trace_id' => 'trace', 'limit' => 500]);

        expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'limit', 'expected' => '1 to 100', 'value' => '500', 'accepted' => 'a whole number from 1 to 100', 'example' => 'trace(trace_id: "<trace id>", limit: 50)']));
    });

    it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
        $text = trcRefusal(['trace_id' => 'trace', $argument => 'now']);

        expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'trace', 'accepted' => 'trace_id, job_id, limit, format', 'example' => 'trace(format: "json")']));
    })->with([
        'a window bound' => ['since', 'inapplicable_argument'],
        'a misspelling' => ['trace', 'unknown_argument'],
    ]);

    it('refuses a format that is none', function () {
        $text = trcRefusal(['trace_id' => 'trace', 'format' => 'xml']);

        expect($text)->toStartWith('error: invalid_argument');
    });
});

describe('the answer', function () {
    it('is not windowed, and says why', function () {
        ingest([trcRequest()]);

        $envelope = trcAnswer();

        expect($envelope['window']['windowed'])->toBeFalse()
            ->and($envelope['window']['reason'])->toBe(__('firewatch::messages.trace_window_reason'));
    });

    it('states what it read and what it cannot see', function () {
        ingest([trcRequest()]);

        $envelope = trcAnswer();

        expect($envelope['coverage']['types_read'])->toBe(['request', 'command', 'job-attempt', 'scheduled-task', 'queued-job'])
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('sync-jobs-unrecorded', 'uninstrumented-dispatcher', 'dead-counters');
    });

    it('summarises a trace by its executions and jobs', function (int $executions, int $jobs, string $shownExecutions, string $shownJobs) {
        ingest([
            ...array_map(fn (int $number) => trcCommand("command-{$number}", ['trace_id' => 'trace']), ($executions === 0 ? [] : range(1, $executions))),
            ...array_map(fn (int $number) => trcDispatch("job-{$number}"), ($jobs === 0 ? [] : range(1, $jobs))),
        ]);

        $envelope = trcAnswer();

        expect($envelope['summary'])->toBe(__('firewatch::messages.trace_summary', ['id' => 'trace', 'executions' => $shownExecutions, 'jobs' => $shownJobs]));
    })->with([
        'one execution and no jobs' => [1, 0, '1 execution', '0 queued jobs'],
        'two executions and one job' => [2, 1, '2 executions', '1 queued job'],
        'jobs and no executions' => [0, 2, '0 executions', '2 queued jobs'],
    ]);

    it('summarises a job by its trace', function () {
        ingest([trcRequest(), trcDispatch('job')]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect($envelope['summary'])->toBe(__('firewatch::messages.trace_job_summary', ['id' => 'job', 'executions' => '1 execution', 'jobs' => '1 queued job']));
    });
});

describe('the next calls', function () {
    it('offers each failing link, then the slowest, and the calls run', function () {
        ingest([
            trcRequest(fields: ['status_code' => 500, 'duration' => 1_000_000]),
            trcDispatch('job'),
            trcAttempt('job', 'attempt-1', 1, ['status' => 'released', 'duration' => 4_000_000]),
            trcAttempt('job', 'attempt-2', 2, ['status' => 'processed', 'timestamp' => TRACE_AT + 8, 'duration' => 9_000_000]),
            trcAttempt('job', 'quick', 3, ['status' => 'processed', 'timestamp' => TRACE_AT + 20, 'duration' => 500_000]),
        ]);

        $envelope = trcAnswer();
        $ran = array_map(fn (array $call) => Envelope::assert(Execution::class, $call['arguments']), $envelope['next']);

        expect($envelope['next'])->toBe([
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'trace'], 'why' => __('firewatch::messages.trace_next_failed')],
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'attempt-1'], 'why' => __('firewatch::messages.trace_next_failed')],
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'attempt-2'], 'why' => __('firewatch::messages.trace_next_slowest')],
        ])
            ->and(array_column(array_column($ran, 'result'), 'header'))->toHaveCount(3);
    });

    it('does not offer the same execution twice', function () {
        ingest([trcRequest(fields: ['status_code' => 500, 'duration' => 9_000_000])]);

        $envelope = trcAnswer();

        expect(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'trace']])
            ->and($envelope['next'][0]['why'])->toBe(__('firewatch::messages.trace_next_failed'));
    });

    it('judges each type by its own failure: a status of 400 or more, a non-zero exit, a failed or released attempt, a failed task', function (RecordType $type, array $fields, bool $fails) {
        ingest([
            syntheticRecord($type)->inExecution('subject')->with(['trace_id' => 'trace', 'timestamp' => TRACE_AT, ...$fields]),
            trcRequest('other-trace'),
        ]);

        $envelope = trcAnswer(['trace_id' => 'trace']);
        $why = array_column($envelope['next'], 'why');

        expect(in_array(__('firewatch::messages.trace_next_failed'), $why, true))->toBe($fails);
    })->with([
        'a request without a status' => [RecordType::REQUEST, ['status_code' => null], false],
        'a request at 399' => [RecordType::REQUEST, ['status_code' => 399], false],
        'a request at 400' => [RecordType::REQUEST, ['status_code' => 400], true],
        'a command without an exit code' => [RecordType::COMMAND, ['exit_code' => null], false],
        'a command that exited 0' => [RecordType::COMMAND, ['exit_code' => 0], false],
        'a command that exited 1' => [RecordType::COMMAND, ['exit_code' => 1], true],
        'a processed attempt' => [RecordType::JOB_ATTEMPT, ['status' => 'processed'], false],
        'a failed attempt' => [RecordType::JOB_ATTEMPT, ['status' => 'failed'], true],
        'a released attempt' => [RecordType::JOB_ATTEMPT, ['status' => 'released'], true],
        'a processed task' => [RecordType::SCHEDULED_TASK, ['status' => 'processed'], false],
        'a skipped task' => [RecordType::SCHEDULED_TASK, ['status' => 'skipped'], false],
        'a failed task' => [RecordType::SCHEDULED_TASK, ['status' => 'failed'], true],
    ]);

    it('offers the slowest link when nothing failed, among attempts that ran under another trace too', function () {
        ingest([
            trcRequest(fields: ['duration' => 1_000_000]),
            trcDispatch('job'),
            trcAttempt('job', 'elsewhere', 1, ['trace_id' => 'worker-trace', 'duration' => 7_000_000]),
        ]);

        $envelope = trcAnswer();

        expect($envelope['next'])->toBe([['tool' => 'execution', 'arguments' => ['execution_id' => 'elsewhere'], 'why' => __('firewatch::messages.trace_next_slowest')]]);
    });

    it('does not offer an execution twice when its id is all digits', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', '42', 1, ['status' => 'failed', 'duration' => 9_000_000]),
        ]);

        $envelope = trcAnswer();

        expect($envelope['next'])->toBe([['tool' => 'execution', 'arguments' => ['execution_id' => '42'], 'why' => __('firewatch::messages.trace_next_failed')]]);
    });

    it('offers no execution for an attempt that has no execution id', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'attempt', 1, ['attempt_id' => null, 'status' => 'failed']),
        ]);

        $envelope = trcAnswer();

        expect($envelope['next'])->toBe([])
            ->and(trcJob($envelope, 'job')['attempts'][0]['execution_id'])->toBeNull();
    });

    it('offers at most five calls', function () {
        ingest(array_map(fn (int $number) => trcAttempt("job-{$number}", "attempt-{$number}", 1, ['status' => 'failed', 'timestamp' => TRACE_AT + $number]), range(1, 8)));

        $envelope = trcAnswer();

        expect($envelope['next'])->toHaveCount(5);
    });

    it('offers the records of the trace when no execution is shown, besides any link of the lineage', function () {
        ingest([
            trcDispatch('job'),
            trcAttempt('job', 'elsewhere', 1, ['trace_id' => 'worker-trace', 'status' => 'failed']),
        ]);

        $envelope = trcAnswer(['job_id' => 'job']);

        expect($envelope['result']['executions'])->toBe([])
            ->and(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences'])
            ->and($envelope['next'][0]['arguments'])->toBe(['execution_id' => 'elsewhere']);
    });
});

test('the tool is listed with its description, arguments and annotations', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tool = collect($listing['tools'])->firstWhere('name', 'trace');

    expect($tool['description'])->toBe(__('firewatch::messages.tools.trace'))
        ->and(str_word_count($tool['description']))->toBeLessThanOrEqual(150)
        ->and(array_keys($tool['inputSchema']['properties']))->toBe(['trace_id', 'job_id', 'limit', 'format'])
        ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false])
        ->and(array_map(fn (array $property) => str_word_count($property['description']), $tool['inputSchema']['properties']))->each->toBeLessThanOrEqual(30);
});
