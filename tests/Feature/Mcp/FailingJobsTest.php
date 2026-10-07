<?php

use ClaudioDekker\Firewatch\Mcp\Bounds;
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

const FJB_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(FJB_AT + 3600));
});

/**
 * Build one attempt of a job. Its execution is named after the job and the number of the attempt, and it starts that many seconds in.
 *
 * @param  array<string, mixed>  $fields
 */
function fjbAttempt(string $job, int $number, ?string $status, string $name = 'ShipOrder', array $fields = []): RecordBuilder
{
    $attempt = syntheticRecord(RecordType::JOB_ATTEMPT)->inExecution("{$job}-{$number}")->with([
        '_group' => md5($name),
        'name' => $name,
        'job_id' => $job,
        'attempt' => $number,
        'timestamp' => FJB_AT + $number,
        'status' => $status,
        ...$fields,
    ]);

    return $status === null ? $attempt->without('status') : $attempt;
}

/**
 * Build the attempts of one job that ended with each of the statuses, in order.
 *
 * @param  list<string|null>  $statuses
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function fjbJob(string $job, array $statuses, string $name = 'ShipOrder', array $fields = []): array
{
    return array_map(
        fn (?string $status, int $index) => fjbAttempt($job, $index + 1, $status, $name, $fields),
        $statuses,
        array_keys($statuses),
    );
}

/**
 * Build an exception recorded in the execution of an attempt.
 *
 * @param  array<string, mixed>  $fields
 */
function fjbException(string $execution, string $message = 'The carrier refused.', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::EXCEPTION)->inExecution($execution)->with([
        'class' => 'RuntimeException',
        'message' => $message,
        'file' => 'app/Jobs/ShipOrder.php',
        'line' => 12,
        'timestamp' => FJB_AT + 1,
        ...$fields,
    ]);
}

/**
 * Get the evidence of the one finding of the answer.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function fjbEvidence(array $arguments = []): array
{
    $findings = fjbAnswer($arguments)['result']['findings'];

    expect($findings)->toHaveCount(1);

    return $findings[0]['evidence'];
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function fjbAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'failing-jobs', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function fjbRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'failing-jobs', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when an attempt of a group failed or was released, over every attempt examined', function () {
        ingest([...fjbJob('a', ['released', 'processed']), ...fjbJob('b', ['processed'], 'SendInvoice')]);

        $envelope = fjbAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'failing-jobs', 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => [], 'caveats' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'failing-jobs', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-jobs')]))
            ->and($envelope['result']['threshold'])->toBe(['name' => 'attempts', 'value' => 1, 'default' => 1, 'unit' => 'attempts', 'range' => ['min' => 1, 'max' => null], 'is_default' => true])
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('ShipOrder'));
    });

    it('tells one attempt under the threshold from the threshold, counting the failed and the released attempts together', function (?int $threshold, array $statuses, string $verdict) {
        ingest(fjbJob('a', $statuses));

        $arguments = $threshold === null ? [] : ['threshold' => $threshold];

        expect(fjbAnswer($arguments)['result'])->toMatchArray(['verdict' => $verdict, 'examined' => count($statuses)]);
    })->with([
        'none under the default of 1' => [null, ['processed'], 'clean'],
        'one failed at the default of 1' => [null, ['failed'], 'findings'],
        'one released at the default of 1' => [null, ['released'], 'findings'],
        'two under 3' => [3, ['released', 'released', 'processed'], 'clean'],
        'exactly 3, two released and one failed' => [3, ['released', 'released', 'failed'], 'findings'],
        'exactly 3 released' => [3, ['released', 'released', 'released'], 'findings'],
        'one under 2' => [2, ['processed', 'failed'], 'clean'],
    ]);

    it('counts the attempts of a group over all its jobs', function () {
        ingest([...fjbJob('a', ['failed']), ...fjbJob('b', ['failed']), ...fjbJob('c', ['failed'], 'SendInvoice')]);

        $result = fjbAnswer(['threshold' => 2])['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 1])
            ->and($result['findings'][0])->toMatchArray(['group' => md5('ShipOrder'), 'count' => 2]);
    });

    it('states the threshold a call passed, and that it is not the default', function () {
        ingest(fjbJob('a', ['processed']));

        $threshold = fjbAnswer(['threshold' => 5])['result']['threshold'];

        expect($threshold)->toBe(['name' => 'attempts', 'value' => 5, 'default' => 1, 'unit' => 'attempts', 'range' => ['min' => 1, 'max' => null], 'is_default' => false]);
    });

    it('refuses a threshold below one attempt or that is no whole number, naming what it accepts', function (mixed $threshold) {
        $refusal = fjbRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a whole number of 1 or more');
    })->with([
        'zero' => [0],
        'a negative number' => [-1],
        'a fraction' => [1.5],
        'text' => ['1'],
    ]);

    it('is clean over the attempts examined when none failed or was released, and says how many', function () {
        ingest([...fjbJob('a', ['processed']), ...fjbJob('b', ['processed'])]);

        $envelope = fjbAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'failing-jobs', 'examined' => 2, 'input' => __('firewatch::messages.detect_input.failing-jobs')]));
    });

    it('is not evaluated when the window holds no attempt, never clean', function () {
        ingest([syntheticRecord(RecordType::REQUEST), syntheticRecord(RecordType::QUEUED_JOB)]);

        $envelope = fjbAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'failing-jobs', 'reason' => 'no_records']));
    });

    it('examines an attempt that has no status, and counts it as neither failed nor released', function () {
        ingest([...fjbJob('a', [null]), ...fjbJob('b', [null, 'failed'], 'SendInvoice')]);

        $result = fjbAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'examined' => 3, 'total' => 1])
            ->and($result['findings'][0])->toMatchArray(['group' => md5('SendInvoice'), 'count' => 1]);
    });

    it('judges the attempts that started in the window, the start included and the end not', function () {
        ingest([
            fjbAttempt('before', 1, 'failed', fields: ['timestamp' => FJB_AT - 1]),
            fjbAttempt('at-since', 1, 'processed', fields: ['timestamp' => FJB_AT]),
            fjbAttempt('at-until', 1, 'failed', fields: ['timestamp' => FJB_AT + 10]),
        ]);

        $envelope = fjbAnswer(['since' => (string) FJB_AT, 'until' => (string) (FJB_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });
});

describe('the jobs', function () {
    it('says how each job ended, from its last attempt and whether it was ever released', function (array $statuses, array $expected) {
        ingest([...fjbJob('a', $statuses), ...fjbJob('seed', ['failed'], 'Seed')]);

        $evidence = fjbEvidence(['group' => md5('ShipOrder')]);

        expect($evidence)->toMatchArray(['jobs' => 1, 'jobs_failed' => 0, 'jobs_recovered' => 0, 'jobs_retrying' => 0, ...$expected]);
    })->with([
        'failed at once' => [['failed'], ['jobs_failed' => 1]],
        'failed after a release' => [['released', 'failed'], ['jobs_failed' => 1]],
        'recovered after a release' => [['released', 'processed'], ['jobs_recovered' => 1]],
        'recovered after two releases' => [['released', 'released', 'processed'], ['jobs_recovered' => 1]],
        'still retrying' => [['released'], ['jobs_retrying' => 1]],
        'still retrying after a second release' => [['released', 'released'], ['jobs_retrying' => 1]],
        'processed after a failure and no release is neither' => [['failed', 'processed'], []],
        'released after a failure is retrying' => [['failed', 'released'], ['jobs_retrying' => 1]],
    ]);

    it('counts a job whose attempts were processed only as a job and as none of the outcomes', function () {
        ingest([...fjbJob('worked', ['processed']), ...fjbJob('broken', ['failed'])]);

        expect(fjbEvidence())->toMatchArray(['failed_attempts' => 1, 'retried_attempts' => 0, 'jobs' => 2, 'jobs_failed' => 1, 'jobs_recovered' => 0, 'jobs_retrying' => 0]);
    });

    it('counts the jobs of a group apart, each by its own attempts', function () {
        ingest([
            ...fjbJob('broken-a', ['released', 'failed']),
            ...fjbJob('broken-b', ['failed']),
            ...fjbJob('recovered', ['released', 'processed']),
            ...fjbJob('retrying', ['released']),
            ...fjbJob('worked', ['processed']),
        ]);

        expect(fjbEvidence())->toBe([
            'failed_attempts' => 2,
            'retried_attempts' => 3,
            'jobs_failed' => 2,
            'jobs_recovered' => 1,
            'jobs_retrying' => 1,
            'jobs' => 5,
            'max_attempt' => 2,
            'last_exception' => null,
        ]);
    });

    it('takes as the last attempt the one that started last, whatever its number', function () {
        ingest([
            fjbAttempt('a', 2, 'processed', fields: ['timestamp' => FJB_AT + 1]),
            fjbAttempt('a', 1, 'failed', fields: ['timestamp' => FJB_AT + 5]),
        ]);

        expect(fjbEvidence())->toMatchArray(['jobs_failed' => 1, 'jobs_recovered' => 0, 'jobs_retrying' => 0]);
    });

    it('takes the higher attempt number as the last of two that started together', function () {
        ingest([
            fjbAttempt('a', 2, 'released', fields: ['timestamp' => FJB_AT]),
            fjbAttempt('a', 1, 'failed', fields: ['timestamp' => FJB_AT]),
        ]);

        expect(fjbEvidence())->toMatchArray(['jobs_failed' => 0, 'jobs_retrying' => 1]);
    });

    it('takes the attempt stored last as the last of two that started together under one number', function (array $statuses, array $expected) {
        ingest(array_map(fn (string $status) => fjbAttempt('a', 1, $status, fields: ['timestamp' => FJB_AT]), $statuses));

        expect(fjbEvidence())->toMatchArray($expected);
    })->with([
        'the release stored last' => [['failed', 'released'], ['jobs_failed' => 0, 'jobs_retrying' => 1]],
        'the failure stored last' => [['released', 'failed'], ['jobs_failed' => 1, 'jobs_retrying' => 0]],
    ]);

    it('gives no outcome to a job whose last attempt has no status', function () {
        ingest(fjbJob('a', ['released', null]));

        expect(fjbEvidence())->toMatchArray(['retried_attempts' => 1, 'jobs' => 1, 'jobs_failed' => 0, 'jobs_recovered' => 0, 'jobs_retrying' => 0]);
    });

    it('does not take an attempt with no status for a release', function () {
        ingest([...fjbJob('a', [null, 'processed']), ...fjbJob('b', ['failed'])]);

        expect(fjbEvidence())->toMatchArray(['jobs' => 2, 'jobs_failed' => 1, 'jobs_recovered' => 0]);
    });

    it('reads how a job ended from its attempts in the window alone, so a release before it does not make a recovery', function () {
        ingest([
            fjbAttempt('a', 1, 'released', fields: ['timestamp' => FJB_AT - 10]),
            fjbAttempt('a', 2, 'processed', fields: ['timestamp' => FJB_AT + 2]),
            fjbAttempt('b', 1, 'released', fields: ['timestamp' => FJB_AT + 3]),
        ]);

        $envelope = fjbAnswer(['since' => (string) FJB_AT]);

        expect($envelope['result'])->toMatchArray(['examined' => 2, 'total' => 1])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['retried_attempts' => 1, 'jobs' => 2, 'jobs_recovered' => 0, 'jobs_retrying' => 1, 'max_attempt' => 2]);
    });

    it('does not take a failure after the window for how a job ended', function () {
        ingest([
            fjbAttempt('a', 1, 'released', fields: ['timestamp' => FJB_AT + 1]),
            fjbAttempt('a', 2, 'failed', fields: ['timestamp' => FJB_AT + 20]),
        ]);

        $evidence = fjbEvidence(['until' => (string) (FJB_AT + 10)]);

        expect($evidence)->toMatchArray(['failed_attempts' => 0, 'jobs_failed' => 0, 'jobs_retrying' => 1, 'max_attempt' => 1]);
    });

    it('counts an attempt with no job id as an attempt and never as a job', function () {
        ingest([
            fjbAttempt('', 1, 'failed'),
            fjbAttempt('', 1, 'released'),
            fjbAttempt('', 2, 'processed', fields: ['timestamp' => FJB_AT + 9]),
            fjbAttempt('gone', 1, 'failed')->without('job_id'),
            fjbAttempt('gone', 1, 'released')->without('job_id'),
        ]);

        $envelope = fjbAnswer();

        expect($envelope['result'])->toMatchArray(['examined' => 5, 'total' => 1])
            ->and($envelope['result']['findings'][0]['count'])->toBe(4)
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['failed_attempts' => 2, 'retried_attempts' => 2, 'jobs' => 0, 'jobs_failed' => 0, 'jobs_recovered' => 0, 'jobs_retrying' => 0]);
    });

    it('judges a job id whose attempts sit in two groups within each group', function () {
        ingest([
            fjbAttempt('shared', 1, 'released'),
            fjbAttempt('shared', 2, 'processed', 'SendInvoice'),
            fjbAttempt('other', 1, 'failed', 'SendInvoice'),
        ]);

        $findings = collect(fjbAnswer()['result']['findings'])->keyBy('name');

        expect($findings)->toHaveCount(2)
            ->and($findings['ShipOrder']['evidence'])->toMatchArray(['jobs' => 1, 'jobs_failed' => 0, 'jobs_recovered' => 0, 'jobs_retrying' => 1, 'max_attempt' => 1])
            ->and($findings['SendInvoice']['evidence'])->toMatchArray(['jobs' => 2, 'jobs_failed' => 1, 'jobs_recovered' => 0, 'jobs_retrying' => 0, 'max_attempt' => 2]);
    });

    it('states the highest attempt number over every attempt of the group, the processed ones too', function () {
        ingest([
            ...fjbJob('a', ['released', 'released', 'released', 'processed']),
            ...fjbJob('b', ['failed']),
            fjbAttempt('c', 1, 'processed', fields: ['attempt' => 'ninth']),
        ]);

        expect(fjbEvidence())->toMatchArray(['max_attempt' => 4, 'jobs' => 3]);
    });
});

describe('the finding', function () {
    it('states the group, its failed and released attempts, how its jobs ended, its latest failure and whom it reached', function () {
        ingest([
            fjbAttempt('worked', 1, 'processed', fields: ['timestamp' => FJB_AT, 'user' => 'u0']),
            fjbAttempt('recovered', 1, 'released', fields: ['timestamp' => FJB_AT + 60, 'user' => 'u1']),
            fjbAttempt('recovered', 2, 'processed', fields: ['timestamp' => FJB_AT + 70, 'user' => 'u1']),
            fjbAttempt('broken', 1, 'released', fields: ['timestamp' => FJB_AT + 120, 'user' => 'u1']),
            fjbAttempt('broken', 2, 'released', fields: ['timestamp' => FJB_AT + 130, 'user' => 'u1']),
            fjbAttempt('broken', 3, 'failed', fields: ['timestamp' => FJB_AT + 140, 'user' => 'u1']),
            fjbAttempt('retrying', 1, 'released', fields: ['timestamp' => FJB_AT + 180, 'user' => '']),
            fjbAttempt('late', 1, 'processed', fields: ['timestamp' => FJB_AT + 240, 'user' => 'u2']),
            fjbException('broken-3', 'The carrier refused.', ['timestamp' => FJB_AT + 141]),
        ]);

        $envelope = fjbAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('ShipOrder'),
            'name' => 'ShipOrder',
            'count' => 5,
            'first_seen_at' => FJB_AT + 60,
            'last_seen_at' => FJB_AT + 180,
            'latest_execution_id' => 'retrying-1',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'failed_attempts' => 1,
                'retried_attempts' => 4,
                'jobs_failed' => 1,
                'jobs_recovered' => 1,
                'jobs_retrying' => 1,
                'jobs' => 5,
                'max_attempt' => 3,
                'last_exception' => [
                    'class' => 'RuntimeException',
                    'message' => 'The carrier refused.',
                    'location' => 'app/Jobs/ShipOrder.php:12',
                ],
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('points at the latest attempt that failed or was released, and at the one stored last of two that started together', function () {
        ingest([
            fjbAttempt('a', 1, 'failed', fields: ['timestamp' => FJB_AT + 5]),
            fjbAttempt('b', 1, 'released', fields: ['timestamp' => FJB_AT + 9]),
            fjbAttempt('c', 1, 'failed', fields: ['timestamp' => FJB_AT + 9]),
            fjbAttempt('d', 1, 'failed', fields: ['timestamp' => FJB_AT + 7]),
            fjbAttempt('e', 1, 'processed', fields: ['timestamp' => FJB_AT + 20]),
            fjbAttempt('f', 1, null, fields: ['timestamp' => FJB_AT + 30]),
        ]);

        $finding = fjbAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['latest_execution_id' => 'c-1', 'first_seen_at' => FJB_AT + 5, 'last_seen_at' => FJB_AT + 9]);
    });

    it('names the group after its latest attempt that failed or was released', function () {
        ingest([
            fjbAttempt('a', 1, 'failed', fields: ['name' => 'OldName', 'timestamp' => FJB_AT + 1]),
            fjbAttempt('b', 1, 'failed', fields: ['name' => 'NewName', 'timestamp' => FJB_AT + 2]),
            fjbAttempt('c', 1, 'processed', fields: ['name' => 'Zebra', 'timestamp' => FJB_AT + 3]),
        ]);

        expect(fjbAnswer()['result']['findings'][0])->toMatchArray(['group' => md5('ShipOrder'), 'name' => 'NewName']);
    });

    it('labels a group whose attempt has no name', function () {
        ingest([fjbAttempt('a', 1, 'failed', fields: ['name' => ''])]);

        expect(fjbAnswer()['result']['findings'][0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
    });

    it('counts the distinct users of the attempts that failed or were released, and those without one apart', function () {
        ingest([
            fjbAttempt('a', 1, 'failed', fields: ['user' => 'u1']),
            fjbAttempt('b', 1, 'released', fields: ['user' => 'u1']),
            fjbAttempt('c', 1, 'released', fields: ['user' => 'u2']),
            fjbAttempt('d', 1, 'failed', fields: ['user' => '']),
            fjbAttempt('e', 1, 'processed', fields: ['user' => 'u3']),
            fjbAttempt('f', 1, 'processed', fields: ['user' => '']),
            fjbAttempt('g', 1, null, fields: ['user' => 'u4']),
            fjbAttempt('h', 1, null, fields: ['user' => '']),
        ]);

        expect(fjbAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });
});

describe('the last exception', function () {
    it('is null when no failed or released attempt recorded one, and the key stays', function () {
        ingest([
            ...fjbJob('a', ['failed']),
            ...fjbJob('b', ['processed']),
            fjbException('b-1'),
            fjbException('elsewhere'),
        ]);

        $evidence = fjbEvidence();

        expect($evidence)->toHaveKey('last_exception')
            ->and($evidence['last_exception'])->toBeNull();
    });

    it('is the exception of a released attempt as well as of a failed one', function (string $status) {
        ingest([fjbAttempt('a', 1, $status), fjbException('a-1', 'Timed out.')]);

        expect(fjbEvidence()['last_exception'])->toBe(['class' => 'RuntimeException', 'message' => 'Timed out.', 'location' => 'app/Jobs/ShipOrder.php:12']);
    })->with(['failed', 'released']);

    it('is the latest of several, across the attempts of the group', function () {
        ingest([
            ...fjbJob('a', ['released', 'failed']),
            ...fjbJob('b', ['failed']),
            fjbException('a-2', 'Second.', ['timestamp' => FJB_AT + 30]),
            fjbException('b-1', 'Latest.', ['timestamp' => FJB_AT + 40]),
            fjbException('a-1', 'First.', ['timestamp' => FJB_AT + 20]),
        ]);

        expect(fjbEvidence()['last_exception']['message'])->toBe('Latest.');
    });

    it('is the one stored last of two recorded together', function () {
        ingest([
            ...fjbJob('a', ['failed']),
            fjbException('a-1', 'Stored first.', ['timestamp' => FJB_AT + 30]),
            fjbException('a-1', 'Stored last.', ['timestamp' => FJB_AT + 30]),
        ]);

        expect(fjbEvidence()['last_exception']['message'])->toBe('Stored last.');
    });

    it('is read by the execution of an attempt in the window, wherever the exception itself falls', function () {
        ingest([
            fjbAttempt('early', 1, 'failed', fields: ['timestamp' => FJB_AT - 50]),
            fjbAttempt('a', 1, 'failed', fields: ['timestamp' => FJB_AT + 5]),
            fjbException('a-1', 'After the window.', ['timestamp' => FJB_AT + 500]),
            fjbException('early-1', 'Of an attempt before the window.', ['timestamp' => FJB_AT + 900]),
        ]);

        $evidence = fjbEvidence(['since' => (string) FJB_AT, 'until' => (string) (FJB_AT + 10)]);

        expect($evidence['last_exception']['message'])->toBe('After the window.');
    });

    it('belongs to its own group', function () {
        ingest([
            ...fjbJob('a', ['failed']),
            ...fjbJob('b', ['failed', 'failed'], 'SendInvoice'),
            fjbException('a-1', 'Of the shipment.', ['timestamp' => FJB_AT + 30]),
            fjbException('b-2', 'Of the invoice.', ['timestamp' => FJB_AT + 40, 'class' => 'LogicException', 'file' => 'app/Jobs/SendInvoice.php', 'line' => 40]),
        ]);

        $exceptions = array_column(array_column(fjbAnswer()['result']['findings'], 'evidence'), 'last_exception');

        expect($exceptions)->toBe([
            ['class' => 'LogicException', 'message' => 'Of the invoice.', 'location' => 'app/Jobs/SendInvoice.php:40'],
            ['class' => 'RuntimeException', 'message' => 'Of the shipment.', 'location' => 'app/Jobs/ShipOrder.php:12'],
        ]);
    });

    it('is found for the attempts that carry no group, which are judged as one', function () {
        ingest([
            fjbAttempt('a', 1, 'failed')->without('_group'),
            fjbAttempt('b', 1, 'released')->without('_group'),
            fjbException('a-1', 'Of no group.'),
        ]);

        $finding = fjbAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['group' => null, 'count' => 2])
            ->and($finding['evidence'])->toMatchArray(['jobs' => 2, 'jobs_failed' => 1, 'jobs_retrying' => 1])
            ->and($finding['evidence']['last_exception']['message'])->toBe('Of no group.');
    });

    it('has no place when the exception names no file', function () {
        ingest([...fjbJob('a', ['failed']), fjbException('a-1', fields: ['file' => ''])]);

        expect(fjbEvidence()['last_exception'])->toBe(['class' => 'RuntimeException', 'message' => 'The carrier refused.', 'location' => null]);
    });

    it('comes back with a long message cut to the cell cap, and the answer says so', function () {
        ingest([...fjbJob('a', ['failed']), fjbException('a-1', str_repeat('x', Bounds::CELL_CHARACTERS + 50))]);

        $envelope = fjbAnswer();
        $message = $envelope['result']['findings'][0]['evidence']['last_exception']['message'];

        expect($message)->toBe(str_repeat('x', 2000).__('firewatch::messages.cell_truncated', ['count' => 50]))
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 1, 'matched' => null, 'reason' => 'cap', 'how' => __('firewatch::messages.cap_how', ['characters' => '2,000'])]]);
    });
});

describe('the order', function () {
    it('lists the group with the most failed jobs first, before one with more failed and released attempts', function () {
        ingest([
            ...fjbJob('a', ['released', 'released', 'released', 'processed'], 'Recovers'),
            ...fjbJob('b', ['failed'], 'Breaks'),
            ...fjbJob('c', ['released', 'released', 'released', 'released'], 'Retries'),
        ]);

        $findings = fjbAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Breaks', 'Retries', 'Recovers'])
            ->and(array_column($findings, 'count'))->toBe([1, 4, 3]);
    });

    it('lists the most failed and released attempts first among groups with as many failed jobs, whichever failed last', function () {
        ingest([
            ...fjbJob('a', ['failed'], 'Once', ['timestamp' => FJB_AT + 100]),
            ...fjbJob('b', ['released', 'released', 'failed'], 'Thrice'),
            ...fjbJob('c', ['released', 'failed'], 'Twice'),
        ]);

        expect(array_column(fjbAnswer()['result']['findings'], 'name'))->toBe(['Thrice', 'Twice', 'Once']);
    });

    it('lists the latest failure first among groups that tie, whichever failed first, then the group hash', function () {
        ingest([
            fjbAttempt('a', 1, 'failed', 'Earlier', ['timestamp' => FJB_AT + 5]),
            fjbAttempt('b', 1, 'failed', 'Earlier', ['timestamp' => FJB_AT + 10]),
            fjbAttempt('c', 1, 'failed', 'Latest', ['timestamp' => FJB_AT + 1]),
            fjbAttempt('d', 1, 'failed', 'Latest', ['timestamp' => FJB_AT + 30]),
            fjbAttempt('e', 1, 'failed', 'Tied-b', ['timestamp' => FJB_AT + 3]),
            fjbAttempt('f', 1, 'failed', 'Tied-b', ['timestamp' => FJB_AT + 20]),
            fjbAttempt('g', 1, 'failed', 'Tied-a', ['timestamp' => FJB_AT + 2]),
            fjbAttempt('h', 1, 'failed', 'Tied-a', ['timestamp' => FJB_AT + 20]),
        ]);

        $tied = [md5('Tied-a'), md5('Tied-b')];
        sort($tied);

        expect(array_column(fjbAnswer()['result']['findings'], 'group'))->toBe([md5('Latest'), ...$tied, md5('Earlier')]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $job) => fjbJob("job-{$job}", array_fill(0, $job, 'released'), "Job{$job}"), range(1, 4))));

        $envelope = fjbAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['Job4', 'Job3', 'Job2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 10, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $job) => fjbJob("job-{$job}", ['failed'], "Job{$job}"), range(1, 3))));

        $envelope = fjbAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });

    it('gives the last exception of each finding shown, also of the one a limit of one shows', function () {
        ingest([
            ...fjbJob('a', ['failed', 'failed'], 'Worst'),
            ...fjbJob('b', ['failed'], 'Other'),
            fjbException('a-2', 'Of the worst.'),
            fjbException('b-1', 'Of the other.'),
        ]);

        $envelope = fjbAnswer(['limit' => 1]);

        expect($envelope['result'])->toMatchArray(['total' => 2])
            ->and($envelope['result']['findings'])->toHaveCount(1)
            ->and($envelope['result']['findings'][0]['evidence']['last_exception']['message'])->toBe('Of the worst.');
    });
});

describe('one group', function () {
    it('restricts the judgement to the group, and examines only its attempts', function () {
        ingest([
            ...fjbJob('a', ['released', 'processed']),
            ...fjbJob('b', ['failed', 'failed'], 'SendInvoice'),
            fjbException('a-1', 'Of the shipment.'),
            fjbException('b-2', 'Of the invoice.'),
        ]);

        $envelope = fjbAnswer(['group' => md5('ShipOrder')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['ShipOrder'])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['retried_attempts' => 1, 'jobs' => 1, 'jobs_recovered' => 1])
            ->and($envelope['result']['findings'][0]['evidence']['last_exception']['message'])->toBe('Of the shipment.');
    });

    it('is clean when the group has attempts and none failed or was released', function () {
        ingest([...fjbJob('a', ['processed']), ...fjbJob('b', ['failed'], 'SendInvoice')]);

        expect(fjbAnswer(['group' => md5('ShipOrder')])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
    });

    it('answers that no attempt matches a group that holds none', function () {
        ingest([...fjbJob('a', ['failed']), ...fjbJob('b', ['processed'], 'SendInvoice')]);

        $envelope = fjbAnswer(['group' => md5('Missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 2]);
    });
});

describe('the blind spots', function () {
    it('states that sync jobs have no attempt and that an uninstrumented dispatcher leaves no dispatch, also when nothing was examined', function (array $statuses) {
        ingest([...fjbJob('a', $statuses), syntheticRecord(RecordType::QUERY)]);

        $envelope = fjbAnswer();
        $blindSpots = array_column($envelope['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['sync-jobs-unrecorded', 'uninstrumented-dispatcher'])
            ->and($blindSpots['sync-jobs-unrecorded'])->toBe(__('firewatch::messages.blind_spots.sync-jobs-unrecorded'))
            ->and($blindSpots['uninstrumented-dispatcher'])->toBe(__('firewatch::messages.blind_spots.uninstrumented-dispatcher'))
            ->and($envelope['result']['caveats'])->toBe([]);
    })->with([
        'with findings' => [['failed']],
        'when clean' => [['processed']],
        'with nothing examined' => [[]],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to its latest failed attempt, its records and its group, then the next finding, and the calls run', function () {
        ingest([
            ...fjbJob('a', ['released', 'failed']),
            ...fjbJob('b', ['released'], 'SendInvoice'),
            fjbAttempt('c', 1, 'processed', fields: ['timestamp' => FJB_AT + 50]),
        ]);

        $envelope = fjbAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'a-2'], ['group' => md5('ShipOrder')], ['group' => md5('ShipOrder')], ['execution_id' => 'b-1']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, at the default threshold, with its worst finding', function () {
        ingest([
            ...fjbJob('a', ['released', 'released', 'processed']),
            ...fjbJob('b', ['failed'], 'SendInvoice'),
            ...fjbJob('c', ['processed'], 'Works'),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'failing-jobs');

        expect($row)->toBe([
            'detector' => 'failing-jobs',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 5,
            'total' => 2,
            'worst' => ['name' => 'SendInvoice', 'group' => md5('SendInvoice')],
        ]);
    });

    it('is run between failing-routes and memory when no shape is named', function () {
        ingest(fjbJob('a', ['failed']));

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'memory'])
            ->and($detectors[3])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});
