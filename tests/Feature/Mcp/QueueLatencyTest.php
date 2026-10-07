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

const QLT_AT = 1790776000.0;

const QLT_NOW = QLT_AT + 3600;

const QLT_SAW = ['inline_excluded' => 0, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0];

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(QLT_NOW));
});

/**
 * Build the dispatch of a job, queued in no time at the start of the test's hour, by the execution named after the job.
 *
 * @param  array<string, mixed>  $fields
 */
function qltDispatch(string $job, string $name = 'ShipOrder', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::QUEUED_JOB)->inExecution("dispatch-{$job}")->with([
        '_group' => md5($name),
        'name' => $name,
        'job_id' => $job,
        'timestamp' => QLT_AT,
        'duration' => 0,
        ...$fields,
    ]);
}

/**
 * Build one attempt of a job that starts the given milliseconds into the test's hour.
 *
 * @param  array<string, mixed>  $fields
 */
function qltAttempt(string $job, int|float $afterMs, int $number = 1, string $name = 'ShipOrder', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::JOB_ATTEMPT)->inExecution("{$job}-{$number}")->with([
        '_group' => md5($name),
        'name' => $name,
        'job_id' => $job,
        'attempt' => $number,
        'timestamp' => QLT_AT + $afterMs / 1000,
        ...$fields,
    ]);
}

/**
 * Build a job that waited the given milliseconds for its first attempt.
 *
 * @param  array<string, mixed>  $dispatchFields
 * @return list<RecordBuilder>
 */
function qltWaited(string $job, int|float $waitMs, string $name = 'ShipOrder', array $dispatchFields = []): array
{
    $queuedAfterMs = (($dispatchFields['timestamp'] ?? QLT_AT) - QLT_AT) * 1000;

    return [qltDispatch($job, $name, $dispatchFields), qltAttempt($job, $queuedAfterMs + $waitMs, name: $name)];
}

/**
 * Build a dispatch that no attempt followed, queued the given milliseconds before the store clock.
 *
 * @param  array<string, mixed>  $fields
 */
function qltPending(string $job, int|float $ageMs, string $name = 'ShipOrder', array $fields = []): RecordBuilder
{
    return qltDispatch($job, $name, ['timestamp' => QLT_NOW - $ageMs / 1000, ...$fields]);
}

/**
 * Get the evidence of the one finding of the answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function qltEvidence(array $arguments = []): array
{
    $findings = qltAnswer($arguments)['result']['findings'];

    expect($findings)->toHaveCount(1);

    return $findings[0]['evidence'];
}

/**
 * Get the answer of the shape to the arguments.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function qltAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'queue-latency', ...$arguments]);
}

/**
 * Get the text of the refusal of the arguments.
 *
 * @param  array<string, mixed>  $arguments
 */
function qltRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'queue-latency', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when a job of a group waited the threshold for its first attempt, over every dispatch examined', function () {
        ingest([...qltWaited('a', 6000), ...qltWaited('b', 20), ...qltWaited('c', 30, 'SendInvoice')]);

        $envelope = qltAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'queue-latency', 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => QLT_SAW])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'queue-latency', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.queue-latency')]))
            ->and($envelope['result']['threshold'])->toBe(['name' => 'ms', 'value' => 5000, 'default' => 5000, 'unit' => 'ms', 'range' => ['min' => 1, 'max' => null], 'is_default' => true])
            ->and($envelope['result']['findings'][0])->toMatchArray(['group' => md5('ShipOrder'), 'count' => 6000.0]);
    });

    it('tells a wait just under the threshold from one that reaches it', function (?int $threshold, int|float $waitMs, string $verdict) {
        ingest(qltWaited('a', $waitMs));

        $arguments = $threshold === null ? [] : ['threshold' => $threshold];

        expect(qltAnswer($arguments)['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        '4999 ms under the default of 5000' => [null, 4999, 'clean'],
        'a microsecond under the default of 5000' => [null, 4999.999, 'clean'],
        'exactly the default of 5000' => [null, 5000, 'findings'],
        '99 ms under 100' => [100, 99, 'clean'],
        'exactly 100' => [100, 100, 'findings'],
        'exactly the least threshold of 1' => [1, 1, 'findings'],
    ]);

    it('tells a pending dispatch just younger than the threshold from one that reaches it', function (?int $threshold, int|float $ageMs, string $verdict) {
        ingest([qltPending('a', $ageMs)]);

        $arguments = $threshold === null ? [] : ['threshold' => $threshold];

        expect(qltAnswer($arguments)['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        '4999 ms under the default of 5000' => [null, 4999, 'clean'],
        'exactly the default of 5000' => [null, 5000, 'findings'],
        '99 ms under 100' => [100, 99, 'clean'],
        'exactly 100' => [100, 100, 'findings'],
    ]);

    it('states the threshold a call passed, and that it is not the default', function () {
        ingest(qltWaited('a', 10));

        $threshold = qltAnswer(['threshold' => 250])['result']['threshold'];

        expect($threshold)->toBe(['name' => 'ms', 'value' => 250, 'default' => 5000, 'unit' => 'ms', 'range' => ['min' => 1, 'max' => null], 'is_default' => false]);
    });

    it('refuses a threshold below one millisecond or that is no whole number, naming what it accepts', function (mixed $threshold) {
        $refusal = qltRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a whole number of 1 or more');
    })->with([
        'zero' => [0],
        'a negative number' => [-1],
        'a fraction' => [1.5],
        'text' => ['5000'],
    ]);

    it('is clean over the dispatches examined when none waited or is pending that long, and says how many', function () {
        ingest([...qltWaited('a', 10), ...qltWaited('b', 4000)]);

        $envelope = qltAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => [], 'saw' => QLT_SAW])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'queue-latency', 'examined' => 2, 'input' => __('firewatch::messages.detect_input.queue-latency')]));
    });

    it('is not evaluated when the window holds no dispatch and no attempt, never clean', function () {
        ingest([syntheticRecord(RecordType::REQUEST)]);

        $envelope = qltAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => [], 'saw' => QLT_SAW])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'queue-latency', 'reason' => 'no_records']));
    });

    it('is not evaluated for want of its prerequisite when attempts ran and no dispatch is stored, and says how many', function () {
        ingest([qltAttempt('a', 100), qltAttempt('a', 900, 2), qltAttempt('b', 200, name: 'SendInvoice')]);

        $envelope = qltAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'prerequisite_missing', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 3, 'without_first_attempt' => 0])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'queue-latency', 'reason' => 'prerequisite_missing']));
    });

    it('is not evaluated for want of records when every dispatch ran inline, and says how many it set aside', function (string $connection) {
        ingest([qltDispatch('a', fields: ['connection' => $connection]), qltDispatch('b', fields: ['connection' => $connection])]);

        $envelope = qltAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 2, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0]);
    })->with(['sync' => 'sync', 'deferred' => 'deferred', 'background' => 'background', 'null' => 'null']);

    it('wants records, not its prerequisite, when only inline dispatches sit next to attempts without one', function () {
        ingest([qltDispatch('a', fields: ['connection' => 'sync']), qltAttempt('b', 100)]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($result['saw'])->toBe(['inline_excluded' => 1, 'attempts_without_dispatch' => 1, 'without_first_attempt' => 0]);
    });

    it('wants records, not its prerequisite, when the attempts of the window have a dispatch stored before it', function () {
        ingest([qltDispatch('a', fields: ['timestamp' => QLT_AT - 60]), qltAttempt('a', 100)]);

        $result = qltAnswer(['since' => (string) QLT_AT])['result'];

        expect($result)->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'saw' => QLT_SAW]);
    });

    it('judges the dispatches that started in the window, the start included and the end not', function () {
        ingest([
            ...qltWaited('before', 9000, dispatchFields: ['timestamp' => QLT_AT - 1]),
            ...qltWaited('at-since', 10),
            ...qltWaited('at-until', 9000, dispatchFields: ['timestamp' => QLT_AT + 10]),
            ...qltWaited('ended-inside', 9000, dispatchFields: ['timestamp' => QLT_AT + 1, 'duration' => 2_000_000]),
        ]);

        $envelope = qltAnswer(['since' => (string) QLT_AT, 'until' => (string) (QLT_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('reads the attempts of a job whole, also the first attempt that started after the window', function () {
        ingest(qltWaited('a', 60_000));

        $envelope = qltAnswer(['until' => (string) (QLT_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'saw' => QLT_SAW])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['waits_over_threshold' => 1, 'pending' => 0, 'worst_wait_ms' => 60000.0]);
    });
});

describe('the wait', function () {
    it('runs from the end of the dispatch to the start of the first attempt', function () {
        ingest([qltDispatch('a', fields: ['timestamp' => QLT_AT + 2, 'duration' => 1_500_000]), qltAttempt('a', 8250)]);

        $envelope = qltAnswer();

        expect($envelope['result']['findings'][0])->toMatchArray(['count' => 6250.0, 'first_seen_at' => QLT_AT + 0.5, 'last_seen_at' => QLT_AT + 0.5])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['worst_wait_ms' => 6250.0, 'waits_over_threshold' => 1]);
    });

    it('runs from the instant a dispatch is stamped with when it carries no duration', function () {
        ingest([qltDispatch('a')->without('duration'), qltAttempt('a', 7000)]);

        expect(qltEvidence())->toMatchArray(['jobs' => 1, 'worst_wait_ms' => 7000.0, 'pending' => 0]);
    });

    it('is of the first attempt only, so the gap before a retry is no wait', function () {
        ingest([...qltWaited('a', 100), qltAttempt('a', 90_000, 2), qltAttempt('a', 900_000, 3)]);

        $result = qltAnswer(['threshold' => 100])['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'saw' => QLT_SAW])
            ->and($result['findings'][0]['evidence'])->toMatchArray(['jobs' => 1, 'waits_over_threshold' => 1, 'worst_wait_ms' => 100.0]);
    });

    it('is never below zero, for a first attempt that started before its dispatch ended', function () {
        ingest([...qltWaited('early', -3000), ...qltWaited('earlier', -9000), ...qltWaited('late', 6000)]);

        expect(qltEvidence())->toMatchArray(['jobs' => 3, 'waits_over_threshold' => 1, 'worst_wait_ms' => 6000.0, 'median_wait_ms' => 0.0]);
    });

    it('is of the first attempt that started first when two are stored', function (array $afterMs) {
        ingest([qltDispatch('a'), ...array_map(fn (int $after) => qltAttempt('a', $after, fields: ['attempt_id' => "after-{$after}"]), $afterMs)]);

        $result = qltAnswer(['threshold' => 200])['result'];

        expect($result['findings'][0])->toMatchArray(['count' => 200.0, 'latest_execution_id' => 'after-200']);
    })->with([
        'the one that started first stored first' => [[200, 9000]],
        'the one that started first stored last' => [[9000, 200]],
    ]);

    it('is of the first attempt stored first when two started together', function () {
        ingest([qltDispatch('a'), qltAttempt('a', 200, fields: ['attempt_id' => 'stored-first']), qltAttempt('a', 200, fields: ['attempt_id' => 'stored-last'])]);

        expect(qltAnswer(['threshold' => 200])['result']['findings'][0]['latest_execution_id'])->toBe('stored-first');
    });

    it('is not known for a job whose first attempt is not stored, which is examined and set aside', function () {
        ingest([qltDispatch('a'), qltAttempt('a', 60_000, 2), qltAttempt('a', 90_000, 3)]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0])
            ->and($result['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 1])
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')]);
    });

    it('does not take an attempt whose number is no number for the first', function () {
        ingest([qltDispatch('a'), qltAttempt('a', 60_000, fields: ['attempt' => 'first'])]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 1])
            ->and($result['saw']['without_first_attempt'])->toBe(1);
    });

    it('is told apart from the jobs of the group that have none, which count as jobs', function () {
        ingest([
            ...qltWaited('waited', 7000),
            qltDispatch('partial'),
            qltAttempt('partial', 80_000, 2),
            qltPending('pending', 1000),
        ]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['examined' => 3, 'total' => 1])
            ->and($result['saw']['without_first_attempt'])->toBe(1)
            ->and($result['findings'][0]['evidence'])->toMatchArray(['jobs' => 3, 'waits_over_threshold' => 1, 'pending' => 1, 'worst_wait_ms' => 7000.0, 'oldest_pending_age_ms' => 1000.0]);
    });
});

describe('the pending dispatches', function () {
    it('ages a dispatch that no attempt followed by the store clock', function () {
        ingest([qltPending('old', 90_000), qltPending('young', 2000), ...qltWaited('worked', 10)]);

        $envelope = qltAnswer();

        expect($envelope['now'])->toEqual(QLT_NOW)
            ->and($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 1])
            ->and($envelope['result']['findings'][0])->toMatchArray(['count' => 90000.0, 'latest_execution_id' => 'dispatch-old'])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['jobs' => 3, 'waits_over_threshold' => 0, 'pending' => 2, 'worst_wait_ms' => 10.0, 'oldest_pending_age_ms' => 90000.0]);
    });

    it('ages a dispatch from its end, not its start', function () {
        ingest([qltPending('a', 6000, fields: ['duration' => 4_000_000])]);

        expect(qltEvidence())->toMatchArray(['pending' => 1, 'oldest_pending_age_ms' => 6000.0]);
    });

    it('never ages a dispatch below zero, for one stamped after the store clock', function () {
        ingest([qltPending('ahead', -4000), ...qltWaited('late', 7000)]);

        expect(qltEvidence())->toMatchArray(['pending' => 1, 'oldest_pending_age_ms' => 0.0, 'worst_wait_ms' => 7000.0]);
    });

    it('takes a dispatch with no job id as pending, whatever attempts carry none either', function () {
        ingest([qltDispatch('', fields: ['timestamp' => QLT_NOW - 8]), qltAttempt('', 100)]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'examined' => 1])
            ->and($result['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 1, 'without_first_attempt' => 0])
            ->and($result['findings'][0]['evidence'])->toMatchArray(['pending' => 1, 'oldest_pending_age_ms' => 8000.0]);
    });

    it('does not take a dispatch that ran inline for pending, however old', function () {
        ingest([qltPending('inline', 90_000, fields: ['connection' => 'sync']), ...qltWaited('worked', 10)]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0])
            ->and($result['saw'])->toBe(['inline_excluded' => 1, 'attempts_without_dispatch' => 0, 'without_first_attempt' => 0])
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')]);
    });
});

describe('what it set aside', function () {
    it('sets an inline dispatch aside whatever attempts its job has, and keeps it out of the jobs of its group', function () {
        ingest([
            ...qltWaited('inline', 9000, dispatchFields: ['connection' => 'sync']),
            ...qltWaited('queued', 6000),
        ]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['examined' => 1, 'total' => 1])
            ->and($result['saw']['inline_excluded'])->toBe(1)
            ->and($result['findings'][0]['evidence'])->toMatchArray(['jobs' => 1, 'worst_wait_ms' => 6000.0, 'connections' => ['database']]);
    });

    it('counts the attempts of the window whose job has no dispatch, next to the dispatches it examined', function () {
        ingest([
            ...qltWaited('known', 10),
            qltAttempt('unknown', 50),
            qltAttempt('unknown', 900, 2),
            qltAttempt('gone', 60)->without('job_id'),
            qltAttempt('before', -5000),
        ]);

        $result = qltAnswer(['since' => (string) QLT_AT])['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 1])
            ->and($result['saw'])->toBe(['inline_excluded' => 0, 'attempts_without_dispatch' => 3, 'without_first_attempt' => 0]);
    });

    it('does not count an attempt whose dispatch is stored outside the window as one without a dispatch', function () {
        ingest([qltDispatch('a', fields: ['timestamp' => QLT_AT - 60]), qltAttempt('a', 100), ...qltWaited('b', 10)]);

        $result = qltAnswer(['since' => (string) QLT_AT])['result'];

        expect($result)->toMatchArray(['examined' => 1, 'saw' => QLT_SAW]);
    });
});

describe('the finding', function () {
    it('states the group, its worst wait or oldest pending age, its jobs, where they were queued and whom the late ones reached', function () {
        ingest([
            qltDispatch('quick', fields: ['timestamp' => QLT_AT, 'user' => 'u0', 'queue' => 'low']),
            qltAttempt('quick', 20),
            qltDispatch('slow', fields: ['timestamp' => QLT_AT + 60, 'user' => 'u1', 'queue' => 'default']),
            qltAttempt('slow', 60_000 + 7000),
            qltDispatch('slower', fields: ['timestamp' => QLT_AT + 120, 'user' => 'u1', 'queue' => 'default', 'connection' => 'redis']),
            qltAttempt('slower', 120_000 + 9000),
            qltDispatch('stuck', fields: ['timestamp' => QLT_AT + 180, 'user' => '', 'queue' => 'default']),
            qltDispatch('fresh', fields: ['timestamp' => QLT_NOW - 1, 'user' => 'u2', 'queue' => 'high']),
        ]);

        $envelope = qltAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('ShipOrder'),
            'name' => 'ShipOrder',
            'count' => 3_420_000.0,
            'first_seen_at' => QLT_AT + 60,
            'last_seen_at' => QLT_AT + 180,
            'latest_execution_id' => 'dispatch-stuck',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'jobs' => 5,
                'waits_over_threshold' => 2,
                'pending' => 2,
                'worst_wait_ms' => 9000.0,
                'median_wait_ms' => 7000.0,
                'oldest_pending_age_ms' => 3_420_000.0,
                'queues' => ['default', 'high', 'low'],
                'connections' => ['database', 'redis'],
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('is one for a group whose jobs waited long and are pending, counted by the greater of the two', function (int $waitMs, int $ageMs, float $count) {
        ingest([...qltWaited('waited', $waitMs), qltPending('pending', $ageMs)]);

        $result = qltAnswer()['result'];

        expect($result)->toMatchArray(['examined' => 2, 'total' => 1])
            ->and($result['findings'][0]['count'])->toEqual($count)
            ->and($result['findings'][0]['evidence'])->toMatchArray(['worst_wait_ms' => (float) $waitMs, 'oldest_pending_age_ms' => (float) $ageMs]);
    })->with([
        'the wait is the greater' => [9000, 6000, 9000.0],
        'the pending age is the greater' => [6000, 9000, 9000.0],
        'only the pending age reaches the threshold' => [100, 5000, 5000.0],
        'only the wait reaches the threshold' => [5000, 100, 5000.0],
    ]);

    it('points at the first attempt of the latest job that waited long', function () {
        ingest([
            ...qltWaited('a', 9000, dispatchFields: ['timestamp' => QLT_AT + 5]),
            ...qltWaited('b', 9000, dispatchFields: ['timestamp' => QLT_AT + 9]),
            ...qltWaited('c', 9000, dispatchFields: ['timestamp' => QLT_AT + 9]),
            ...qltWaited('d', 9000, dispatchFields: ['timestamp' => QLT_AT + 7]),
            ...qltWaited('e', 100, dispatchFields: ['timestamp' => QLT_AT + 30]),
        ]);

        $finding = qltAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['latest_execution_id' => 'c-1', 'first_seen_at' => QLT_AT + 5, 'last_seen_at' => QLT_AT + 9]);
    });

    it('points at the execution that queued the latest late job when that job is pending', function () {
        ingest([...qltWaited('a', 9000), qltPending('b', 7000), qltPending('c', 1000)]);

        expect(qltAnswer()['result']['findings'][0]['latest_execution_id'])->toBe('dispatch-b');
    });

    it('points at nothing when the execution that queued a pending job is not named', function () {
        ingest([qltPending('a', 7000, fields: ['execution_id' => ''])]);

        expect(qltAnswer()['result']['findings'][0]['latest_execution_id'])->toBeNull();
    });

    it('withholds the median wait below three jobs that have a wait', function (array $waits, ?float $median) {
        ingest([
            ...array_merge(...array_map(fn (int $waitMs, int $job) => qltWaited("job-{$job}", $waitMs), $waits, array_keys($waits))),
            qltPending('pending-a', 9000),
            qltPending('pending-b', 9000),
        ]);

        $evidence = qltEvidence();

        expect($evidence['median_wait_ms'] === null)->toBe($median === null)
            ->and($evidence['median_wait_ms'])->toEqual($median)
            ->and($evidence['jobs'])->toBe(count($waits) + 2);
    })->with([
        'no wait' => [[], null],
        'two waits' => [[100, 300], null],
        'three waits' => [[300, 100, 200], 200.0],
        'four waits, at the nearest rank' => [[400, 100, 300, 200], 200.0],
        'five waits' => [[500, 100, 400, 200, 300], 300.0],
    ]);

    it('names the group after its latest late dispatch', function () {
        ingest([
            ...qltWaited('a', 9000, dispatchFields: ['name' => 'OldName', 'timestamp' => QLT_AT + 1]),
            ...qltWaited('b', 9000, dispatchFields: ['name' => 'NewName', 'timestamp' => QLT_AT + 2]),
            ...qltWaited('c', 10, dispatchFields: ['name' => 'Zebra', 'timestamp' => QLT_AT + 3]),
        ]);

        expect(qltAnswer()['result']['findings'][0])->toMatchArray(['group' => md5('ShipOrder'), 'name' => 'NewName']);
    });

    it('labels a group whose dispatch has no name', function () {
        ingest([qltPending('a', 9000, fields: ['name' => ''])]);

        expect(qltAnswer()['result']['findings'][0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
    });

    it('counts the distinct users of the late dispatches, and those without one apart', function () {
        ingest([
            ...qltWaited('a', 9000, dispatchFields: ['user' => 'u1']),
            qltPending('b', 9000, fields: ['user' => 'u1']),
            qltPending('c', 9000, fields: ['user' => 'u2']),
            ...qltWaited('d', 9000, dispatchFields: ['user' => '']),
            ...qltWaited('e', 10, dispatchFields: ['user' => 'u3']),
            qltPending('f', 10, fields: ['user' => '']),
        ]);

        expect(qltAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });

    it('judges the dispatches that carry no group as one', function () {
        ingest([qltPending('a', 9000)->without('_group'), ...array_map(fn (RecordBuilder $record) => $record->without('_group'), qltWaited('b', 10))]);

        $finding = qltAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['group' => null, 'count' => 9000.0])
            ->and($finding['evidence'])->toMatchArray(['jobs' => 2, 'pending' => 1]);
    });

    it('leaves a queue or a connection that a dispatch does not name out of those it saw', function () {
        ingest([qltPending('a', 9000, fields: ['queue' => '', 'connection' => '']), qltPending('b', 9000)->without('queue', 'connection')]);

        expect(qltEvidence())->toMatchArray(['jobs' => 2, 'queues' => [], 'connections' => []]);
    });
});

describe('the caveats', function () {
    it('always says that a delayed job counts as waiting', function (array $records, string $verdict) {
        ingest([syntheticRecord(RecordType::REQUEST), ...$records]);

        $result = qltAnswer()['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')]);
    })->with([
        'with findings' => [fn () => qltWaited('a', 9000), 'findings'],
        'when clean' => [fn () => qltWaited('a', 10), 'clean'],
        'with nothing examined' => [fn () => [], 'not_evaluated'],
        'without its prerequisite' => [fn () => [qltAttempt('a', 10)], 'not_evaluated'],
    ]);

    it('says that the store cannot tell why no attempt is recorded, whenever a dispatch is pending', function (int $ageMs, string $verdict) {
        ingest([qltPending('a', $ageMs), ...qltWaited('b', 10)]);

        $result = qltAnswer()['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait'), __('firewatch::messages.detect_caveat_pending')]);
    })->with([
        'one older than the threshold' => [9000, 'findings'],
        'one younger than the threshold' => [100, 'clean'],
    ]);
});

describe('the order', function () {
    it('lists the group with the greatest wait or pending age first, whichever of the two it is', function () {
        ingest([
            ...qltWaited('a', 8000, 'Waited'),
            qltPending('b', 9000, 'Pending'),
            ...qltWaited('c', 7000, 'Both'),
            qltPending('d', 10_000, 'Both'),
            ...qltWaited('e', 6000, 'Least'),
        ]);

        $findings = qltAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Both', 'Pending', 'Waited', 'Least'])
            ->and(array_column($findings, 'count'))->toEqual([10000.0, 9000.0, 8000.0, 6000.0]);
    });

    it('lists the groups that tie by their group hash', function () {
        ingest([
            ...qltWaited('a', 8000, 'Tied-a'),
            ...qltWaited('b', 8000, 'Tied-b'),
            ...qltWaited('c', 8000, 'Tied-c'),
            ...qltWaited('d', 9000, 'Worst'),
        ]);

        $tied = [md5('Tied-a'), md5('Tied-b'), md5('Tied-c')];
        sort($tied);

        expect(array_column(qltAnswer()['result']['findings'], 'group'))->toBe([md5('Worst'), ...$tied]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $job) => qltWaited("job-{$job}", 5000 + $job, "Job{$job}"), range(1, 4))));

        $envelope = qltAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['Job4', 'Job3', 'Job2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 4, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $job) => qltWaited("job-{$job}", 9000, "Job{$job}"), range(1, 3))));

        $envelope = qltAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('one group', function () {
    it('restricts the judgement to the group, and examines and sets aside only its records', function () {
        ingest([
            ...qltWaited('a', 9000),
            qltDispatch('inline', fields: ['connection' => 'sync']),
            qltAttempt('lost', 10),
            ...qltWaited('b', 20_000, 'SendInvoice'),
            qltPending('c', 9000, 'SendInvoice'),
            qltDispatch('inline-b', 'SendInvoice', ['connection' => 'sync']),
            qltAttempt('lost-b', 10, name: 'SendInvoice'),
            qltDispatch('partial-b', 'SendInvoice'),
            qltAttempt('partial-b', 10, 2, 'SendInvoice'),
        ]);

        $envelope = qltAnswer(['group' => md5('ShipOrder')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['saw'])->toBe(['inline_excluded' => 1, 'attempts_without_dispatch' => 1, 'without_first_attempt' => 0])
            ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_wait')])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['ShipOrder'])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['jobs' => 1, 'pending' => 0, 'worst_wait_ms' => 9000.0]);
    });

    it('is clean when the group has dispatches and none waited or is pending that long', function () {
        ingest([...qltWaited('a', 10), ...qltWaited('b', 9000, 'SendInvoice')]);

        expect(qltAnswer(['group' => md5('ShipOrder')])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
    });

    it('answers that no dispatch matches a group that holds none', function () {
        ingest([...qltWaited('a', 9000), ...qltWaited('b', 10, 'SendInvoice')]);

        $envelope = qltAnswer(['group' => md5('Missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'saw' => QLT_SAW])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 4]);
    });

    it('does not answer that nothing matches a group whose records it set aside', function (RecordBuilder $record, string $reason) {
        ingest([$record, ...qltWaited('b', 10, 'SendInvoice')]);

        $envelope = qltAnswer(['group' => md5('ShipOrder')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => $reason, 'examined' => 0])
            ->and($envelope['empty'])->toBeNull();
    })->with([
        'attempts without a dispatch' => [fn () => qltAttempt('lost', 10), 'no_records'],
        'inline dispatches' => [fn () => qltDispatch('inline', fields: ['connection' => 'sync']), 'no_records'],
    ]);

    it('misses its prerequisite for a group only when no group has a dispatch in the window', function () {
        ingest([qltAttempt('lost', 10), qltAttempt('other', 10, name: 'SendInvoice')]);

        $envelope = qltAnswer(['group' => md5('ShipOrder')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'prerequisite_missing', 'examined' => 0])
            ->and($envelope['result']['saw']['attempts_without_dispatch'])->toBe(1)
            ->and($envelope['empty'])->toBeNull();
    });
});

describe('the blind spots', function () {
    it('states that an uninstrumented dispatcher leaves no dispatch and that sync jobs have no attempt, also when nothing was examined', function (Closure $records) {
        ingest([...$records(), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(qltAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['sync-jobs-unrecorded', 'uninstrumented-dispatcher'])
            ->and($blindSpots['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
            ->and($blindSpots['uninstrumented-dispatcher'])->toBe(__('firewatch::messages.blind_spots.uninstrumented-dispatcher'));
    })->with([
        'with findings' => [fn () => qltWaited('a', 9000)],
        'when clean' => [fn () => qltWaited('a', 10)],
        'without its prerequisite' => [fn () => [qltAttempt('a', 10)]],
        'with nothing examined' => [fn () => []],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to the first attempt of its latest late job, its records and its group, then the next finding to the execution that queued its pending job, and the calls run', function () {
        ingest([
            ...qltWaited('a', 9000),
            qltPending('b', 7000, 'SendInvoice'),
            syntheticRecord(RecordType::COMMAND)->inExecution('dispatch-b'),
            ...qltWaited('c', 10, 'Works'),
        ]);

        $envelope = qltAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'a-1'], ['group' => md5('ShipOrder')], ['group' => md5('ShipOrder')], ['execution_id' => 'dispatch-b']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, at the default threshold, with its worst finding', function () {
        ingest([
            ...qltWaited('a', 9000),
            qltPending('b', 60_000, 'SendInvoice'),
            ...qltWaited('c', 10, 'Works'),
            qltDispatch('inline', 'Works', ['connection' => 'sync']),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'queue-latency');

        expect($row)->toBe([
            'detector' => 'queue-latency',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 3,
            'total' => 2,
            'worst' => ['name' => 'SendInvoice', 'group' => md5('SendInvoice')],
        ]);
    });

    it('is run between failing-jobs and memory when no shape is named', function () {
        ingest(qltWaited('a', 9000));

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'memory'])
            ->and($detectors[4])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});
