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

const EXC_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(EXC_AT + 3600));
});

/**
 * Build one exception that escaped a request, of the group of its class.
 *
 * @param  array<string, mixed>  $fields
 */
function excException(string $execution, string $class = 'RuntimeException', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::EXCEPTION)->inExecution($execution)->with([
        '_group' => md5($class),
        'class' => $class,
        'execution_source' => 'request',
        'timestamp' => EXC_AT + 1,
        ...$fields,
    ]);
}

/**
 * Build the exceptions of one group, one in each of the executions, in order.
 *
 * @param  list<string>  $executions
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function excExceptions(string $class, array $executions, array $fields = []): array
{
    return array_map(fn (string $execution) => excException($execution, $class, $fields), $executions);
}

/**
 * Build one fatal error as Nightwatch sends it, without a trace or an execution id, in the trace of the execution.
 *
 * @param  array<string, mixed>  $fields
 */
function excFatal(string $execution, string $class = 'FatalError', array $fields = []): RecordBuilder
{
    return excException('', $class, ['trace_id' => $execution, 'trace' => '', ...$fields]);
}

/**
 * Build one execution of the type, with the label.
 *
 * @param  array<string, mixed>  $fields
 */
function excExecution(string $execution, string $label = '/orders', RecordType $type = RecordType::REQUEST, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->inExecution($execution)->with([
        '_group' => md5("execution {$label}"),
        $type === RecordType::REQUEST ? 'route_path' : 'name' => $label,
        'timestamp' => EXC_AT + 1,
        ...$fields,
    ]);
}

/**
 * Get the trace Nightwatch would send for the files, the frame that threw first.
 *
 * @param  list<mixed>  $files
 */
function excTrace(array $files): string
{
    return json_encode(array_map(fn (mixed $file) => ['file' => $file, 'source' => 'App\\Thing->run()', 'code' => null], $files), JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function excAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'exception-clusters', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function excRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'exception-clusters', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when an exception occurred, over the executions of every type, and states its threshold', function () {
        ingest([
            excExecution('a'),
            excExecution('b', 'inventory:sync', RecordType::COMMAND),
            excExecution('c', 'App\\Jobs\\BuildReport', RecordType::JOB_ATTEMPT),
            excExecution('d', 'prune-orders', RecordType::SCHEDULED_TASK),
            excException('a'),
            syntheticRecord(RecordType::QUERY),
        ]);

        $envelope = excAnswer();

        expect($envelope['result'])->toMatchArray([
            'detector' => 'exception-clusters',
            'threshold' => ['name' => 'occurrences', 'value' => 1, 'default' => 1, 'unit' => 'occurrences', 'range' => ['min' => 1, 'max' => null], 'is_default' => true],
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 4,
            'total' => 1,
            'saw' => [],
            'caveats' => [],
        ])->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'exception-clusters', 'total' => 1, 'examined' => 4, 'input' => __('firewatch::messages.detect_input.exception-clusters')]))
            ->and($envelope['summary'])->not->toContain('firewatch::')
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('RuntimeException'));
    });

    it('is clean over the executions examined when no exception occurred, and says how many', function () {
        ingest([excExecution('a'), excExecution('b', 'inventory:sync', RecordType::COMMAND)]);

        $envelope = excAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'exception-clusters', 'examined' => 2, 'input' => __('firewatch::messages.detect_input.exception-clusters')]));
    });

    it('is not evaluated when the window holds no execution and no exception, never clean', function () {
        ingest([syntheticRecord(RecordType::QUERY), syntheticRecord(RecordType::LOG)]);

        $envelope = excAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'exception-clusters', 'reason' => 'no_records']));
    });

    it('has findings over no execution when an exception occurred and its execution is not stored', function () {
        ingest([excException('gone')]);

        expect(excAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 0, 'total' => 1]);
    });

    it('takes a group with as many occurrences as the threshold for a finding, and one with fewer for none', function (int $threshold, array $names) {
        ingest([
            excExecution('a'),
            ...excExceptions('Twice', ['a', 'a']),
            ...excExceptions('Thrice', ['a', 'a', 'a']),
        ]);

        $result = excAnswer(['threshold' => $threshold])['result'];

        expect(array_column($result['findings'], 'name'))->toBe($names)
            ->and($result)->toMatchArray(['verdict' => $names === [] ? 'clean' : 'findings', 'examined' => 1, 'total' => count($names)])
            ->and($result['threshold'])->toMatchArray(['value' => $threshold, 'is_default' => $threshold === 1]);
    })->with([
        'the default' => [1, ['Thrice', 'Twice']],
        'two' => [2, ['Thrice', 'Twice']],
        'three' => [3, ['Thrice']],
        'four' => [4, []],
    ]);

    it('refuses a threshold that is no whole number of 1 or more', function (mixed $threshold) {
        ingest([excExecution('a'), excException('a')]);

        $refusal = excRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')->toContain('argument: threshold');
    })->with([
        'zero' => [0],
        'a fraction' => [1.5],
        'text' => ['1'],
    ]);

    it('judges the exceptions that occurred in the window, the start included and the end not', function () {
        ingest([
            excExecution('a'),
            excException('a', 'Before', ['timestamp' => EXC_AT - 1]),
            excException('a', 'AtSince', ['timestamp' => EXC_AT]),
            excException('a', 'AtUntil', ['timestamp' => EXC_AT + 10]),
        ]);

        $result = excAnswer(['since' => (string) EXC_AT, 'until' => (string) (EXC_AT + 10)])['result'];

        expect(array_column($result['findings'], 'name'))->toBe(['AtSince'])
            ->and($result)->toMatchArray(['examined' => 1, 'total' => 1]);
    });

    it('examines the executions that started in the window, whenever their exceptions occurred', function () {
        ingest([
            excExecution('before', fields: ['timestamp' => EXC_AT - 1]),
            excExecution('at-since', fields: ['timestamp' => EXC_AT]),
            excExecution('at-until', fields: ['timestamp' => EXC_AT + 10]),
        ]);

        $result = excAnswer(['since' => (string) EXC_AT, 'until' => (string) (EXC_AT + 10)])['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });
});

describe('the finding', function () {
    it('states the group, how its occurrences ended, where they occurred and what the latest one says', function () {
        ingest([
            excExecution('first', '/invoices/{invoice}'),
            excExecution('last', '/invoices/{invoice}'),
            excException('first', fields: [
                'timestamp' => EXC_AT + 5,
                'message' => 'Invoice [7] could not be rendered.',
                'file' => 'app/Old.php',
                'line' => 3,
                'user' => '7',
            ]),
            excException('last', fields: [
                'timestamp' => EXC_AT + 9,
                'message' => 'Invoice [8] could not be rendered.',
                'file' => 'app/Http/Controllers/InvoiceController.php',
                'line' => 27,
                'trace' => excTrace(['vendor/laravel/framework/src/Illuminate/Support/helpers.php:99', 'app/Http/Controllers/InvoiceController.php:27']),
            ]),
        ]);

        $envelope = excAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('RuntimeException'),
            'name' => 'RuntimeException',
            'count' => 2,
            'first_seen_at' => EXC_AT + 5,
            'last_seen_at' => EXC_AT + 9,
            'latest_execution_id' => 'last',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'class' => 'RuntimeException',
                'message' => 'Invoice [8] could not be rendered.',
                'file' => 'app/Http/Controllers/InvoiceController.php',
                'line' => 27,
                'app_frame' => 'app/Http/Controllers/InvoiceController.php:27',
                'escaped' => 2,
                'reported' => 0,
                'fatal' => false,
                'units' => [
                    ['source' => 'request', 'label' => '/invoices/{invoice}', 'occurrences' => 2],
                ],
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('points at the occurrence stored last of two that occurred together', function () {
        ingest([
            excException('a', fields: ['timestamp' => EXC_AT + 9, 'message' => 'first']),
            excException('b', fields: ['timestamp' => EXC_AT + 9, 'message' => 'second']),
            excException('c', fields: ['timestamp' => EXC_AT + 7, 'message' => 'earlier']),
        ]);

        $finding = excAnswer()['result']['findings'][0];

        expect($finding['latest_execution_id'])->toBe('b')
            ->and($finding['evidence']['message'])->toBe('second');
    });

    it('counts the distinct actors its occurrences reached, and the occurrences without one', function () {
        ingest([
            excException('a', fields: ['user' => '7']),
            excException('b', fields: ['user' => '7']),
            excException('c', fields: ['user' => '8']),
            excException('d', fields: ['user' => '']),
        ]);

        expect(excAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });
});

describe('escaped and reported', function () {
    it('counts an occurrence that reached the handler as escaped, one passed to report() as reported, and one that says neither as neither', function () {
        ingest([
            excException('a', fields: ['handled' => false]),
            excException('b', fields: ['handled' => false]),
            excException('c', fields: ['handled' => true]),
            excException('d')->without('handled'),
        ]);

        $finding = excAnswer()['result']['findings'][0];

        expect($finding['count'])->toBe(4)
            ->and($finding['evidence'])->toMatchArray(['escaped' => 2, 'reported' => 1]);
    });
});

describe('fatal', function () {
    it('is fatal when one of its occurrences was stored without a trace', function () {
        ingest([
            excException('a', fields: ['timestamp' => EXC_AT + 1]),
            excFatal('b', 'RuntimeException', ['timestamp' => EXC_AT + 2]),
            excException('c', fields: ['timestamp' => EXC_AT + 3]),
        ]);

        $finding = excAnswer()['result']['findings'][0];

        expect($finding['count'])->toBe(3)
            ->and($finding['evidence']['fatal'])->toBeTrue();
    });

    it('is not fatal for a trace Nightwatch sent, whatever it holds and whatever the class', function (string $trace) {
        ingest([excException('a', 'Symfony\\Component\\ErrorHandler\\Error\\FatalError', ['trace' => $trace])]);

        expect(excAnswer()['result']['findings'][0]['evidence']['fatal'])->toBeFalse();
    })->with([
        'no frames' => ['[]'],
        'frames' => [excTrace(['app/Jobs/BuildReport.php:44'])],
        'text that is not JSON' => ['#0 {main}'],
    ]);

    it('gives a fatal error of a job no execution, and one of a request the execution of its trace', function (string $source, ?string $execution) {
        ingest([excFatal('the-trace', fields: ['execution_source' => $source])]);

        $finding = excAnswer()['result']['findings'][0];

        expect($finding['latest_execution_id'])->toBe($execution)
            ->and($finding['evidence'])->toMatchArray(['fatal' => true, 'app_frame' => null]);
    })->with([
        'a job' => ['job', null],
        'a request' => ['request', 'the-trace'],
    ]);
});

describe('the application frame', function () {
    it('is the first frame of the trace whose file is the application\'s', function (array $files, ?string $frame) {
        ingest([excException('a', fields: ['trace' => excTrace($files)])]);

        expect(excAnswer()['result']['findings'][0]['evidence']['app_frame'])->toBe($frame);
    })->with([
        'the frame that threw' => [['app/Models/Order.php:12', 'app/Http/Controllers/OrderController.php:30'], 'app/Models/Order.php:12'],
        'below vendor frames' => [['vendor/laravel/framework/src/Illuminate/Database/Connection.php:838', 'vendor/laravel/framework/src/Illuminate/Database/Connection.php:794', 'app/Models/Order.php:12'], 'app/Models/Order.php:12'],
        'below an absolute vendor path' => [['/srv/app/vendor/laravel/framework/src/Illuminate/Routing/Router.php:10', '/srv/app/routes/web.php:27'], '/srv/app/routes/web.php:27'],
        'below an internal function' => [['[internal function]', 'app/Models/Order.php:12'], 'app/Models/Order.php:12'],
        'below an unknown file' => [['[unknown file]', 'app/Models/Order.php:12'], 'app/Models/Order.php:12'],
        'below a frame without a file' => [[null, '', 'app/Models/Order.php:12'], 'app/Models/Order.php:12'],
        'none, when every frame is a vendor\'s' => [['vendor/laravel/framework/src/Illuminate/Routing/Router.php:10', '[internal function]'], null],
        'none, without frames' => [[], null],
    ]);

    it('is none for a trace that is not a list of frames, and the call still answers', function (string $trace) {
        ingest([excException('a', fields: ['trace' => $trace])]);

        expect(excAnswer()['result']['findings'][0]['evidence'])->toMatchArray(['app_frame' => null, 'fatal' => false]);
    })->with([
        'text that is not JSON' => ['#0 {main}'],
        'a JSON string' => ['"app/Models/Order.php:12"'],
        'a list of strings' => ['["app/Models/Order.php:12"]'],
    ]);

    it('is read from the latest occurrence that has a trace, when a later one has none', function () {
        ingest([
            excException('a', fields: ['timestamp' => EXC_AT + 1, 'trace' => excTrace(['app/Old.php:1'])]),
            excException('b', fields: ['timestamp' => EXC_AT + 2, 'trace' => excTrace(['app/New.php:2'])]),
            excFatal('c', 'RuntimeException', ['timestamp' => EXC_AT + 3, 'message' => 'Allowed memory size exhausted']),
        ]);

        $finding = excAnswer()['result']['findings'][0];

        expect($finding['evidence'])->toMatchArray(['app_frame' => 'app/New.php:2', 'fatal' => true, 'message' => 'Allowed memory size exhausted']);
    });

    it('is read from the occurrences in the window only', function () {
        ingest([
            excException('a', fields: ['timestamp' => EXC_AT + 1, 'trace' => excTrace(['app/Inside.php:1'])]),
            excException('b', fields: ['timestamp' => EXC_AT + 20, 'trace' => excTrace(['app/Outside.php:2'])]),
        ]);

        $finding = excAnswer(['until' => (string) (EXC_AT + 10)])['result']['findings'][0];

        expect($finding['evidence']['app_frame'])->toBe('app/Inside.php:1');
    });
});

describe('the units', function () {
    it('lists the three places with the most occurrences, then by source and label', function () {
        ingest([
            excExecution('orders', '/orders'),
            excExecution('carts', '/carts'),
            excExecution('sync', 'inventory:sync', RecordType::COMMAND),
            excExecution('audit', 'audit:run', RecordType::COMMAND),
            ...excExceptions('RuntimeException', ['orders', 'orders', 'orders']),
            ...excExceptions('RuntimeException', ['carts', 'carts']),
            ...excExceptions('RuntimeException', ['sync', 'sync'], ['execution_source' => 'command']),
            ...excExceptions('RuntimeException', ['audit'], ['execution_source' => 'command']),
        ]);

        expect(excAnswer()['result']['findings'][0]['evidence']['units'])->toBe([
            ['source' => 'request', 'label' => '/orders', 'occurrences' => 3],
            ['source' => 'command', 'label' => 'inventory:sync', 'occurrences' => 2],
            ['source' => 'request', 'label' => '/carts', 'occurrences' => 2],
        ]);
    });

    it('lists the units of each group shown apart', function () {
        ingest([
            excExecution('orders', '/orders'),
            excExecution('sync', 'inventory:sync', RecordType::COMMAND),
            ...excExceptions('InRequests', ['orders', 'orders']),
            ...excExceptions('InCommands', ['sync'], ['execution_source' => 'command']),
        ]);

        $units = array_column(array_column(excAnswer()['result']['findings'], 'evidence'), 'units', 'class');

        expect($units)->toBe([
            'InRequests' => [['source' => 'request', 'label' => '/orders', 'occurrences' => 2]],
            'InCommands' => [['source' => 'command', 'label' => 'inventory:sync', 'occurrences' => 1]],
        ]);
    });

    it('has no label for an occurrence whose execution is not stored, and for a fatal error of a job', function () {
        ingest([
            ...excExceptions('RuntimeException', ['gone', 'gone']),
            excFatal('the-trace', 'RuntimeException', ['execution_source' => 'job']),
        ]);

        expect(excAnswer()['result']['findings'][0]['evidence']['units'])->toBe([
            ['source' => 'request', 'label' => null, 'occurrences' => 2],
            ['source' => 'job', 'label' => null, 'occurrences' => 1],
        ]);
    });

    it('counts each occurrence once when several executions share its id, under the label of the latest', function () {
        ingest([
            excExecution('shared', '/first', fields: ['timestamp' => EXC_AT + 1]),
            excExecution('shared', '/second', fields: ['timestamp' => EXC_AT + 2]),
            ...excExceptions('RuntimeException', ['shared', 'shared']),
        ]);

        $result = excAnswer()['result'];

        expect($result['findings'][0]['count'])->toBe(2)
            ->and($result['findings'][0]['evidence']['units'])->toBe([['source' => 'request', 'label' => '/second', 'occurrences' => 2]]);
    });

    it('labels an occurrence of a request that matched no route, or that states none', function (Closure $request) {
        ingest([$request(), excException('a')]);

        expect(excAnswer()['result']['findings'][0]['evidence']['units'])->toBe([
            ['source' => 'request', 'label' => __('firewatch::messages.rank_no_route'), 'occurrences' => 1],
        ]);
    })->with([
        'an empty route path' => [fn () => excExecution('a', '')],
        'no route path' => [fn () => excExecution('a')->without('route_path')],
    ]);

    it('labels an occurrence in the window by its execution that started before it', function () {
        ingest([
            excExecution('long', 'reports:build', RecordType::COMMAND, ['timestamp' => EXC_AT - 60]),
            excException('long', fields: ['execution_source' => 'command', 'timestamp' => EXC_AT + 5]),
        ]);

        $result = excAnswer(['since' => (string) EXC_AT])['result'];

        expect($result)->toMatchArray(['verdict' => 'findings', 'examined' => 0])
            ->and($result['findings'][0]['evidence']['units'])->toBe([['source' => 'command', 'label' => 'reports:build', 'occurrences' => 1]]);
    });
});

describe('the order', function () {
    it('lists a group with an escaped occurrence before one that was only reported, however often', function () {
        ingest([
            ...excExceptions('Reported', ['a', 'b', 'c', 'd', 'e'], ['handled' => true]),
            ...excExceptions('Escaped', ['a'], ['handled' => false]),
            ...excExceptions('Unknown', ['a', 'b', 'c'], ['handled' => null]),
        ]);

        $findings = excAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['Escaped', 'Reported', 'Unknown'])
            ->and(array_column(array_column($findings, 'evidence'), 'escaped'))->toBe([1, 0, 0]);
    });

    it('lists the most occurrences first among the groups with an escaped occurrence, however many of them escaped', function () {
        ingest([
            ...excExceptions('TwoEscaped', ['a', 'b'], ['handled' => false]),
            ...excExceptions('OneEscapedOfThree', ['a'], ['handled' => false]),
            ...excExceptions('OneEscapedOfThree', ['b', 'c'], ['handled' => true]),
            ...excExceptions('OneEscaped', ['a'], ['handled' => false]),
        ]);

        expect(array_column(excAnswer()['result']['findings'], 'name'))->toBe(['OneEscapedOfThree', 'TwoEscaped', 'OneEscaped']);
    });

    it('lists the group seen last first among those with as many occurrences', function () {
        ingest([
            excException('a', 'Early', ['timestamp' => EXC_AT + 10]),
            excException('b', 'Late', ['timestamp' => EXC_AT + 30]),
            excException('c', 'Middle', ['timestamp' => EXC_AT + 20]),
        ]);

        expect(array_column(excAnswer()['result']['findings'], 'name'))->toBe(['Late', 'Middle', 'Early']);
    });

    it('lists groups that tie by their group hash', function () {
        ingest([excException('a', 'TiedA'), excException('b', 'TiedB'), excException('c', 'TiedC')]);

        $tied = [md5('TiedA'), md5('TiedB'), md5('TiedC')];
        sort($tied, SORT_STRING);

        expect(array_column(excAnswer()['result']['findings'], 'group'))->toBe($tied);
    });

    it('orders two group hashes that read as the same number as text', function () {
        $later = '0e'.str_repeat('2', 30);
        $earlier = '0e'.str_repeat('1', 30);

        ingest([
            excException('a', 'Later', ['_group' => $later]),
            excException('b', 'Earlier', ['_group' => $earlier]),
        ]);

        expect(array_column(excAnswer()['result']['findings'], 'group'))->toBe([$earlier, $later]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest([
            excExecution('a'),
            ...array_merge(...array_map(fn (int $times) => excExceptions("Group{$times}", array_fill(0, $times, 'a')), range(1, 4))),
        ]);

        $envelope = excAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['Group4', 'Group3', 'Group2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 1, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest([excException('a', 'One'), excException('a', 'Two'), excException('a', 'Three')]);

        $envelope = excAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('one group', function () {
    it('restricts the findings to the group, and still examines every execution of the window', function () {
        ingest([
            excExecution('a'),
            excExecution('b', '/carts'),
            ...excExceptions('Wanted', ['a']),
            ...excExceptions('Other', ['b', 'b']),
        ]);

        $envelope = excAnswer(['group' => md5('Wanted')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['Wanted'])
            ->and($envelope['result']['findings'][0]['evidence']['units'])->toBe([['source' => 'request', 'label' => '/orders', 'occurrences' => 1]])
            ->and($envelope['empty'])->toBeNull();
    });

    it('is clean when the group has fewer occurrences than the threshold', function () {
        ingest([excExecution('a'), ...excExceptions('Wanted', ['a', 'a'])]);

        $envelope = excAnswer(['group' => md5('Wanted'), 'threshold' => 3]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0, 'findings' => []])
            ->and($envelope['empty'])->toBeNull();
    });

    it('answers that no record matches a group that holds no exception, whatever else it holds', function (string $group) {
        ingest([excExecution('a'), excExecution('b', '/carts'), excException('a')]);

        $envelope = excAnswer(['group' => $group]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 3])
            ->and($envelope['next'])->toBe([]);
    })->with([
        'a group of nothing' => [md5('missing')],
        'the group of a route' => [md5('execution /orders')],
    ]);

    it('has findings, and matched something, for a group whose only occurrence is a fatal error of a job in a window without an execution', function () {
        ingest([excFatal('the-trace', fields: ['execution_source' => 'job'])]);

        $envelope = excAnswer(['group' => md5('FatalError')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'reason' => null, 'examined' => 0, 'total' => 1])
            ->and($envelope['result']['findings'][0]['latest_execution_id'])->toBeNull()
            ->and($envelope['empty'])->toBeNull();
    });
});

describe('the blind spots', function () {
    it('states what exceptions and executions do not carry, also when nothing was examined', function (Closure $records) {
        ingest([...$records(), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(excAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['exceptions-unreported', 'console-requests', 'dead-counters', 'sync-jobs-unrecorded', 'vendor-defaults-unrecorded', 'memory-is-process-peak', 'actor-partial'])
            ->and($blindSpots['exceptions-unreported'])->toBe(__('firewatch::messages.blind_spots.exceptions-unreported'))
            ->and($blindSpots['actor-partial'])->toBe(__('firewatch::messages.blind_spots.actor-partial'));
    })->with([
        'with findings' => [fn () => [excExecution('a'), excException('a')]],
        'when clean' => [fn () => [excExecution('a')]],
        'with nothing examined' => [fn () => []],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to its latest execution, its records and its group, then the next finding, and skips one without an execution', function () {
        ingest([
            excExecution('a'),
            excExecution('b'),
            excExecution('c', 'inventory:sync', RecordType::COMMAND),
            excException('a', fields: ['timestamp' => EXC_AT + 5]),
            excException('b', fields: ['timestamp' => EXC_AT + 10]),
            excFatal('the-trace', fields: ['execution_source' => 'job']),
            excException('c', 'Reported', ['execution_source' => 'command', 'handled' => true]),
        ]);

        $envelope = excAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['RuntimeException', 'FatalError', 'Reported'])
            ->and(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'b'], ['group' => md5('RuntimeException')], ['group' => md5('RuntimeException')], ['execution_id' => 'c']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, with its worst finding', function () {
        ingest([
            excExecution('a'),
            excExecution('b', 'inventory:sync', RecordType::COMMAND),
            ...excExceptions('Reported', ['a', 'a', 'a'], ['handled' => true]),
            ...excExceptions('Escaped', ['b'], ['execution_source' => 'command']),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'exception-clusters');

        expect($row)->toBe([
            'detector' => 'exception-clusters',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 2,
            'total' => 2,
            'worst' => ['name' => 'Escaped', 'group' => md5('Escaped')],
        ]);
    });

    it('is run between failing-tasks and memory when no shape is named', function () {
        ingest([excExecution('a'), excException('a')]);

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'memory'])
            ->and($detectors[6])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1]);
    });
});
