<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const ELOG_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(ELOG_AT + 3600));
});

/**
 * Build one log line, at error unless the level says otherwise.
 *
 * @param  array<string, mixed>  $fields
 */
function elogLine(string $message, string $level = 'error', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::LOG)->with([
        'level' => $level,
        'message' => $message,
        'timestamp' => ELOG_AT + 1,
        ...$fields,
    ]);
}

/**
 * Build as many lines of one message as asked for.
 *
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function elogLines(int $times, string $message, string $level = 'error', array $fields = []): array
{
    return array_map(fn () => elogLine($message, $level, $fields), range(1, $times));
}

/**
 * Build the request a log line was written in.
 */
function elogRequest(string $execution): RecordBuilder
{
    return syntheticRecord(RecordType::REQUEST)->inExecution($execution)->with(['timestamp' => ELOG_AT + 1]);
}

/**
 * Build one exception of an execution.
 *
 * @param  array<string, mixed>  $fields
 */
function elogException(string $execution, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::EXCEPTION)->inExecution($execution)->with(['timestamp' => ELOG_AT + 1, ...$fields]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function elogAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'error-logs', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function elogRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'error-logs', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has a finding for a line at error or worse, over the logs of every level, and states its threshold', function (string $level) {
        ingest([
            elogLine('The payment failed.', $level),
            elogLine('The payment is slow.', 'warning'),
            elogLine('The payment was made.', 'info'),
            syntheticRecord(RecordType::QUERY),
        ]);

        $envelope = elogAnswer();

        expect($envelope['result'])->toMatchArray([
            'detector' => 'error-logs',
            'threshold' => ['name' => 'occurrences', 'value' => 1, 'default' => 1, 'unit' => 'occurrences', 'range' => ['min' => 1, 'max' => null], 'is_default' => true],
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 3,
            'total' => 1,
            'saw' => [],
        ])->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'error-logs', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.error-logs')]))
            ->and($envelope['summary'])->not->toContain('firewatch::')
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['The payment failed.'])
            ->and($envelope['result']['findings'][0]['evidence']['level'])->toBe($level);
    })->with(['error', 'critical', 'alert', 'emergency']);

    it('reads a level whatever its case', function () {
        ingest([elogLine('The payment failed.', 'ERROR'), elogLine('The payment failed.', 'Error')]);

        $result = elogAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'total' => 1])
            ->and($result['findings'][0])->toMatchArray(['count' => 2])
            ->and($result['findings'][0]['evidence']['level'])->toBe('error');
    });

    it('is clean over the logs of every level when none is at error or worse, and says how many', function () {
        ingest(array_map(fn (string $level) => elogLine('The payment is slow.', $level), ['debug', 'info', 'notice', 'warning']));

        $envelope = elogAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 4, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'error-logs', 'examined' => 4, 'input' => __('firewatch::messages.detect_input.error-logs')]));
    });

    it('is not evaluated when the window holds no log, never clean', function (Closure $records, array $arguments) {
        ingest($records());

        $envelope = elogAnswer($arguments);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'error-logs', 'reason' => 'no_records']))
            ->and($envelope['next'])->toBe([]);
    })->with([
        'records of other types' => [fn () => [elogRequest('a'), elogException('a'), syntheticRecord(RecordType::QUERY)], []],
        'an error before the window' => [fn () => [elogLine('The payment failed.', fields: ['timestamp' => ELOG_AT - 1])], ['since' => (string) ELOG_AT]],
    ]);

    it('takes a shape with as many occurrences as the threshold for a finding, and one with fewer for none', function (int $threshold, array $names) {
        ingest([
            ...elogLines(2, 'Twice'),
            ...elogLines(3, 'Thrice'),
            elogLine('The payment is slow.', 'warning'),
        ]);

        $result = elogAnswer(['threshold' => $threshold])['result'];

        expect(array_column($result['findings'], 'name'))->toBe($names)
            ->and($result)->toMatchArray(['verdict' => $names === [] ? 'clean' : 'findings', 'examined' => 6, 'total' => count($names)])
            ->and($result['threshold'])->toMatchArray(['value' => $threshold, 'is_default' => $threshold === 1]);
    })->with([
        'the default' => [1, ['Thrice', 'Twice']],
        'two' => [2, ['Thrice', 'Twice']],
        'three' => [3, ['Thrice']],
        'four' => [4, []],
    ]);

    it('refuses a threshold that is no whole number of 1 or more', function (mixed $threshold) {
        ingest([elogLine('The payment failed.')]);

        $refusal = elogRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')->toContain('argument: threshold');
    })->with([
        'zero' => [0],
        'a fraction' => [1.5],
        'text' => ['1'],
    ]);

    it('judges the logs that were written in the window, the start included and the end not', function () {
        ingest([
            elogLine('Before', fields: ['timestamp' => ELOG_AT - 1]),
            elogLine('AtSince', fields: ['timestamp' => ELOG_AT]),
            elogLine('Inside', 'info', ['timestamp' => ELOG_AT + 5]),
            elogLine('AtUntil', fields: ['timestamp' => ELOG_AT + 10]),
        ]);

        $result = elogAnswer(['since' => (string) ELOG_AT, 'until' => (string) (ELOG_AT + 10)])['result'];

        expect(array_column($result['findings'], 'name'))->toBe(['AtSince'])
            ->and($result)->toMatchArray(['examined' => 2, 'total' => 1]);
    });

    it('counts the lines of a shape in the window only', function () {
        ingest([
            elogLine('Order 1 failed', fields: ['timestamp' => ELOG_AT - 1]),
            elogLine('Order 2 failed', fields: ['timestamp' => ELOG_AT + 2]),
            elogLine('Order 3 failed', fields: ['timestamp' => ELOG_AT + 4]),
            elogLine('Order 4 failed', fields: ['timestamp' => ELOG_AT + 10]),
        ]);

        $finding = elogAnswer(['since' => (string) ELOG_AT, 'until' => (string) (ELOG_AT + 10)])['result']['findings'][0];

        expect($finding)->toMatchArray(['count' => 2, 'first_seen_at' => ELOG_AT + 2, 'last_seen_at' => ELOG_AT + 4])
            ->and($finding['evidence']['message'])->toBe('Order 3 failed');
    });
});

describe('the finding', function () {
    it('has no group, is named by its shape, and states the latest line, the text to find its lines by and whom it reached', function () {
        ingest([
            elogRequest('first'),
            elogRequest('last'),
            elogException('first'),
            elogLine('Payment 3fa85f64-5717-4562-b3fc-2c963f66afa6 declined for order 4471', fields: ['execution_id' => 'first', 'timestamp' => ELOG_AT + 5, 'user' => '7']),
            elogLine('Payment 0b5d8f1c-0a51-4c5e-9d39-6f0f4a8f6e21 declined for order 12', fields: ['execution_id' => 'last', 'timestamp' => ELOG_AT + 9]),
        ]);

        $envelope = elogAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => null,
            'name' => 'Payment <id> declined for order <n>',
            'count' => 2,
            'first_seen_at' => ELOG_AT + 5,
            'last_seen_at' => ELOG_AT + 9,
            'latest_execution_id' => 'last',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'level' => 'error',
                'message' => 'Payment 0b5d8f1c-0a51-4c5e-9d39-6f0f4a8f6e21 declined for order 12',
                'shape' => 'Payment <id> declined for order <n>',
                'fragment' => 'declined for order',
                'occurrences' => 2,
                'in_executions_with_exception' => 1,
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('points at the line stored last of two that were written together', function () {
        ingest([
            elogLine('Order 1 failed', fields: ['execution_id' => 'a', 'timestamp' => ELOG_AT + 9]),
            elogLine('Order 2 failed', fields: ['execution_id' => 'b', 'timestamp' => ELOG_AT + 9]),
            elogLine('Order 3 failed', fields: ['execution_id' => 'c', 'timestamp' => ELOG_AT + 7]),
        ]);

        $finding = elogAnswer()['result']['findings'][0];

        expect($finding['latest_execution_id'])->toBe('b')
            ->and($finding['evidence']['message'])->toBe('Order 2 failed');
    });

    it('has no latest execution when the latest line carries none', function () {
        ingest([
            elogLine('Order 1 failed', fields: ['execution_id' => 'a', 'timestamp' => ELOG_AT + 1]),
            elogLine('Order 2 failed', fields: ['execution_id' => '', 'timestamp' => ELOG_AT + 2]),
        ]);

        $envelope = elogAnswer();

        expect($envelope['result']['findings'][0])->toMatchArray(['count' => 2, 'latest_execution_id' => null])
            ->and(array_column($envelope['next'], 'tool'))->toBe(['occurrences']);
    });

    it('counts the distinct actors its lines reached, and the lines without one', function () {
        ingest([
            elogLine('Order 1 failed', fields: ['user' => '7']),
            elogLine('Order 2 failed', fields: ['user' => '7']),
            elogLine('Order 3 failed', fields: ['user' => '8']),
            elogLine('Order 4 failed', fields: ['user' => '']),
            elogLine('Order 5 failed', 'critical', ['user' => '9']),
        ]);

        expect(elogAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });

    it('keeps the latest line as it was stored and offers the text of the shape, cut to what the occurrences tool takes', function () {
        $message = str_repeat('é', 250).' in order 7';

        ingest([elogLine($message)]);

        $envelope = elogAnswer();
        $listing = collect($envelope['next'])->firstWhere('tool', 'occurrences');

        expect($envelope['result']['findings'][0]['evidence'])->toMatchArray(['message' => $message, 'shape' => str_repeat('é', 250).' in order <n>', 'fragment' => str_repeat('é', 200)])
            ->and(Envelope::assert(Occurrences::class, $listing['arguments'])['result']['rows'])->toHaveCount(1);
    });
});

describe('the shape', function () {
    it('takes messages that differ only in ids and digits for one shape, and messages that differ in a word for two', function () {
        ingest([
            elogLine('Job deadbeefcafe of order 7 failed after 3 tries'),
            elogLine('Job 0123456789abcdef of order 12345 failed after 10 tries'),
            elogLine('Job 3fa85f64-5717-4562-b3fc-2c963f66afa6 of order 1234567 failed after 1 tries'),
            elogLine('Job deadbeefcafe of order 7 stalled after 3 tries'),
        ]);

        $result = elogAnswer()['result'];

        expect(array_column($result['findings'], 'count', 'name'))->toBe([
            'Job <id> of order <n> failed after <n> tries' => 3,
            'Job <id> of order <n> stalled after <n> tries' => 1,
        ])->and($result['total'])->toBe(2);
    });

    it('takes a run of eight digits for an id, and so for another shape than a shorter number', function () {
        ingest([elogLine('Order 1234567 failed'), elogLine('Order 12345678 failed')]);

        expect(array_column(elogAnswer()['result']['findings'], 'name'))->toBe(['Order <id> failed', 'Order <n> failed']);
    });

    it('takes one shape at two levels for two findings', function () {
        ingest([
            ...elogLines(2, 'Order 1 failed'),
            elogLine('Order 2 failed', 'critical'),
        ]);

        $findings = elogAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Order <n> failed', 'Order <n> failed'])
            ->and(array_column($findings, 'count'))->toBe([2, 1])
            ->and(array_column(array_column($findings, 'evidence'), 'level'))->toBe(['error', 'critical']);
    });

    it('has no fragment for a message that is only ids and numbers, and one of no text for a message that is no text', function (mixed $message, string $shape) {
        ingest([elogLine('placeholder', fields: ['message' => $message])]);

        $finding = elogAnswer()['result']['findings'][0];

        expect($finding['name'])->toBe($shape)
            ->and($finding['evidence'])->toMatchArray(['message' => $message, 'shape' => $shape, 'fragment' => null]);
    })->with([
        'an id and a number' => ['3fa85f64-5717-4562-b3fc-2c963f66afa6 42', '<id> <n>'],
        'an empty message' => ['', ''],
        'a number' => [42, ''],
    ]);
});

describe('the executions with an exception', function () {
    it('counts the lines whose execution also has an exception, whenever it was thrown', function () {
        ingest([
            elogException('thrown'),
            elogException('thrown'),
            elogException('thrown-before', ['timestamp' => ELOG_AT - 60]),
            elogException(''),
            elogLine('Order 1 failed', fields: ['execution_id' => 'thrown']),
            elogLine('Order 2 failed', fields: ['execution_id' => 'thrown']),
            elogLine('Order 3 failed', fields: ['execution_id' => 'thrown-before']),
            elogLine('Order 4 failed', fields: ['execution_id' => 'quiet']),
            elogLine('Order 5 failed', fields: ['execution_id' => '']),
        ]);

        $finding = elogAnswer(['since' => (string) ELOG_AT])['result']['findings'][0];

        expect($finding['count'])->toBe(5)
            ->and($finding['evidence'])->toMatchArray(['occurrences' => 5, 'in_executions_with_exception' => 3]);
    });

    it('does not order the findings by them', function () {
        ingest([
            elogException('thrown'),
            elogLine('With an exception', fields: ['execution_id' => 'thrown', 'timestamp' => ELOG_AT + 1]),
            elogLine('Without one', fields: ['timestamp' => ELOG_AT + 2]),
        ]);

        $findings = elogAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Without one', 'With an exception'])
            ->and(array_column(array_column($findings, 'evidence'), 'in_executions_with_exception'))->toBe([0, 1]);
    });
});

describe('the order', function () {
    it('lists the shape with the most occurrences first, whatever its level and however late the others were seen', function () {
        ingest([
            elogLine('Once', 'emergency', ['timestamp' => ELOG_AT + 30]),
            ...elogLines(2, 'Twice', 'critical', ['timestamp' => ELOG_AT + 20]),
            ...elogLines(3, 'Thrice', 'error', ['timestamp' => ELOG_AT + 10]),
        ]);

        expect(array_column(elogAnswer()['result']['findings'], 'name'))->toBe(['Thrice', 'Twice', 'Once']);
    });

    it('lists the shape seen last first among those with as many occurrences', function () {
        ingest([
            elogLine('Early', fields: ['timestamp' => ELOG_AT + 10]),
            elogLine('Late', fields: ['timestamp' => ELOG_AT + 30]),
            elogLine('Middle', fields: ['timestamp' => ELOG_AT + 20]),
        ]);

        expect(array_column(elogAnswer()['result']['findings'], 'name'))->toBe(['Late', 'Middle', 'Early']);
    });

    it('lists shapes that tie by their text, byte by byte, before their level', function () {
        ingest([
            elogLine('apple', 'alert'),
            elogLine('Zebra', 'error'),
            elogLine('Tied B', 'critical'),
            elogLine('Tied A', 'error'),
        ]);

        $findings = elogAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Tied A', 'Tied B', 'Zebra', 'apple'])
            ->and(array_column(array_column($findings, 'evidence'), 'level'))->toBe(['error', 'critical', 'error', 'alert']);
    });

    it('lists one shape that ties at several levels by the name of the level', function () {
        ingest([
            elogLine('Tied', 'error'),
            elogLine('Tied', 'emergency'),
            elogLine('Tied', 'alert'),
            elogLine('Tied', 'critical'),
        ]);

        expect(array_column(array_column(elogAnswer()['result']['findings'], 'evidence'), 'level'))->toBe(['alert', 'critical', 'emergency', 'error']);
    });

    it('shows the findings up to the limit, the exact total and a note of the cut', function (int $limit, array $names) {
        ingest([
            elogLine('The payment is slow.', 'warning'),
            ...elogLines(1, 'Once'),
            ...elogLines(2, 'Twice'),
            ...elogLines(3, 'Thrice'),
            ...elogLines(4, 'Four times'),
        ]);

        $envelope = elogAnswer(['limit' => $limit]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe($names)
            ->and($envelope['result'])->toMatchArray(['examined' => 11, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => $limit, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how_ungrouped')]]);
    })->with([
        'one' => [1, ['Four times']],
        'three' => [3, ['Four times', 'Thrice', 'Twice']],
    ]);

    it('is complete when exactly the limit is shown', function () {
        ingest([elogLine('One'), elogLine('Two'), elogLine('Three')]);

        $envelope = elogAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)
            ->and($envelope['result']['total'])->toBe(3)
            ->and($envelope['truncated'])->toBe([]);
    });
});

describe('a group', function () {
    it('is refused whatever it holds, as a finding has none', function (string $group) {
        ingest([elogLine('The payment failed.')]);

        $refusal = elogRefusal(['group' => $group]);

        expect($refusal)->toStartWith('error: conflicting_arguments')
            ->toContain('argument: group')
            ->toContain('shape: error-logs')
            ->toContain('detect(shape: "error-logs")');
    })->with([
        'a group id' => [md5('The payment failed.')],
        'text that is no group id' => ['payments'],
    ]);

    it('is refused before the store is read', function () {
        $refusal = elogRefusal(['group' => md5('The payment failed.')]);

        expect($refusal)->toStartWith('error: conflicting_arguments')->toContain('argument: group');
    });
});

describe('the caveat', function () {
    it('says that logs exist only while log capture is on, whatever the verdict', function (Closure $records, string $verdict) {
        ingest($records());

        $result = elogAnswer()['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_log_capture')]);
    })->with([
        'with findings' => [fn () => [elogLine('The payment failed.')], 'findings'],
        'when clean' => [fn () => [elogLine('The payment is slow.', 'warning')], 'clean'],
        'with nothing examined' => [fn () => [syntheticRecord(RecordType::QUERY)], 'not_evaluated'],
    ]);
});

describe('the blind spots', function () {
    it('states that a named channel is not captured, also when nothing was examined', function (Closure $records) {
        ingest([...$records(), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(elogAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['named-log-channels', 'actor-partial'])
            ->and($blindSpots['named-log-channels'])->toBe(__('firewatch::messages.blind_spots.named-log-channels'))
            ->and($blindSpots['actor-partial'])->toBe(__('firewatch::messages.blind_spots.actor-partial'));
    })->with([
        'with findings' => [fn () => [elogLine('The payment failed.')]],
        'when clean' => [fn () => [elogLine('The payment is slow.', 'warning')]],
        'with nothing examined' => [fn () => []],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to its latest execution and to its lines by its fragment and level, then the next finding', function () {
        ingest([
            elogRequest('a'),
            elogRequest('b'),
            elogRequest('c'),
            elogLine('Payment 7 declined for order 4471', 'critical', ['execution_id' => 'a', 'timestamp' => ELOG_AT + 5]),
            elogLine('Payment 8 declined for order 12', 'critical', ['execution_id' => 'b', 'timestamp' => ELOG_AT + 10]),
            elogLine('Payment 9 declined for order 13', 'error', ['execution_id' => 'c']),
            elogLine('Payment 9 refused for order 13', 'emergency', ['execution_id' => '']),
            elogLine('Payment 9 declined for order 13', 'warning', ['execution_id' => 'c']),
        ]);

        $envelope = elogAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class];

        expect(array_column(array_column($envelope['result']['findings'], 'evidence'), 'level'))->toBe(['critical', 'error', 'emergency'])
            ->and($envelope['next'])->toBe([
                ['tool' => 'execution', 'arguments' => ['execution_id' => 'b'], 'why' => __('firewatch::messages.detect_next_execution')],
                ['tool' => 'occurrences', 'arguments' => ['type' => 'log', 'level' => 'critical', 'matching' => 'declined for order'], 'why' => __('firewatch::messages.detect_next_log_lines')],
                ['tool' => 'execution', 'arguments' => ['execution_id' => 'c'], 'why' => __('firewatch::messages.detect_next_execution')],
            ]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }

        $lines = Envelope::assert(Occurrences::class, $envelope['next'][1]['arguments'])['result']['rows'];

        expect(array_column(array_column($lines, 'detail'), 'message'))->toEqualCanonicalizing(['Payment 7 declined for order 4471', 'Payment 8 declined for order 12']);
    });

    it('follows a finding without a fragment to the lines at its level and worse', function () {
        ingest([
            elogLine('3fa85f64-5717-4562-b3fc-2c963f66afa6', 'alert', ['execution_id' => '']),
            elogLine('The payment is slow.', 'warning'),
        ]);

        $envelope = elogAnswer();

        expect($envelope['next'])->toBe([
            ['tool' => 'occurrences', 'arguments' => ['type' => 'log', 'level' => 'alert'], 'why' => __('firewatch::messages.detect_next_log_level')],
        ]);

        $lines = Envelope::assert(Occurrences::class, $envelope['next'][0]['arguments'])['result']['rows'];

        expect(array_column(array_column($lines, 'detail'), 'message'))->toBe(['3fa85f64-5717-4562-b3fc-2c963f66afa6']);
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, with its worst finding and no group', function () {
        ingest([
            elogLine('The payment is slow.', 'warning'),
            ...elogLines(2, 'Order 7 failed'),
            elogLine('The card was declined.', 'critical'),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'error-logs');

        expect($row)->toBe([
            'detector' => 'error-logs',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 4,
            'total' => 2,
            'worst' => ['name' => 'Order <n> failed', 'group' => null],
        ]);
    });

    it('is run between exception-clusters and memory when no shape is named', function () {
        ingest([elogLine('The payment failed.')]);

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'memory'])
            ->and($detectors[7])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1]);
    });
});
