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

const NPO_AT = 1790776000.0;

const NPO_USER_SQL = 'select * from users where id = ?';

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(NPO_AT - 3600));
});

/**
 * @param  array<string, mixed>  $fields
 */
function npoExecution(string $id, string $label = '/orders', RecordType $type = RecordType::REQUEST, array $fields = []): RecordBuilder
{
    $labelled = $type === RecordType::REQUEST ? ['route_path' => $label] : ['name' => $label];

    return syntheticRecord($type)->inExecution($id)->with([
        '_group' => md5($label),
        'timestamp' => NPO_AT,
        'duration' => 10_000_000,
        'queries' => 0,
        ...$labelled,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function npoQuery(string $id, string $sql = NPO_USER_SQL, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::QUERY)->inExecution($id)->with([
        '_group' => md5($sql),
        'timestamp' => NPO_AT + 0.1,
        'sql' => $sql,
        'file' => 'app/Orders.php',
        'line' => 10,
        'duration' => 1_000,
        ...$fields,
    ]);
}

/**
 * The records of an execution that ran a query a number of times.
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, mixed>  $execution
 * @return list<RecordBuilder>
 */
function npoRun(string $id, int $runs, string $label = '/orders', string $sql = NPO_USER_SQL, array $fields = [], array $execution = [], RecordType $type = RecordType::REQUEST): array
{
    return [
        npoExecution($id, $label, $type, ['queries' => $runs, ...$execution]),
        ...array_map(fn () => npoQuery($id, $sql, $fields), range(1, $runs)),
    ];
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function npoAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'n-plus-one', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function npoRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'n-plus-one', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when one execution ran a read query shape at least three times', function () {
        ingest(npoRun('one', 3));

        $envelope = npoAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'n-plus-one', 'verdict' => 'findings', 'reason' => null, 'examined' => 1, 'total' => 1, 'saw' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'n-plus-one', 'total' => 1, 'examined' => 1, 'input' => __('firewatch::messages.detect_input.n-plus-one')]))
            ->and($envelope['result']['threshold'])->toBe(['name' => 'runs', 'value' => 3, 'default' => 3, 'unit' => 'runs', 'range' => ['min' => 2, 'max' => null], 'is_default' => true]);
    });

    it('tells two runs from three', function (int $runs, string $verdict) {
        ingest(npoRun('one', $runs));

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        'two runs' => [2, 'clean'],
        'three runs' => [3, 'findings'],
    ]);

    it('is clean over the executions that ran queries, and says how many', function () {
        ingest([
            ...npoRun('one', 1),
            ...npoRun('two', 2),
            npoExecution('quiet'),
        ]);

        $envelope = npoAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 2, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'n-plus-one', 'examined' => 2, 'input' => __('firewatch::messages.detect_input.n-plus-one')]));
    });

    it('examines the executions of all four types', function () {
        ingest([
            ...npoRun('request', 1),
            ...npoRun('command', 1, 'orders:ship', type: RecordType::COMMAND),
            ...npoRun('job', 1, 'ShipOrder', type: RecordType::JOB_ATTEMPT),
            ...npoRun('task', 1, 'prune-orders', type: RecordType::SCHEDULED_TASK),
        ]);

        expect(npoAnswer()['result']['examined'])->toBe(4);
    });

    it('is not evaluated when no execution ran a query', function () {
        ingest([npoExecution('quiet'), npoExecution('also-quiet', 'orders:ship', RecordType::COMMAND)]);

        $envelope = npoAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'n-plus-one', 'reason' => 'no_records']));
    });

    it('judges the executions that started in the window, with their queries wherever those started', function () {
        ingest([
            npoExecution('early', fields: ['timestamp' => NPO_AT - 600, 'queries' => 3]),
            ...array_map(fn () => npoQuery('early', fields: ['timestamp' => NPO_AT + 100]), range(1, 3)),
            ...npoRun('late', 3, '/late', execution: ['timestamp' => NPO_AT + 300]),
        ]);

        $envelope = npoAnswer(['since' => NPO_AT]);

        expect($envelope['result'])->toMatchArray(['examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['name'])->toStartWith('/late');
    });
});

describe('reads', function () {
    it('counts a run only of reads, which begin with select or with, whatever the case and the leading whitespace', function (string $sql, bool $counted) {
        ingest(npoRun('one', 3, sql: $sql));

        expect(npoAnswer()['result']['total'])->toBe($counted ? 1 : 0);
    })->with([
        'select' => ['select * from users where id = ?', true],
        'upper case select' => ['SELECT * FROM users WHERE id = ?', true],
        'whitespace first' => ["  \n\tselect * from users where id = ?", true],
        'with' => ['with recent as (select * from orders) select * from recent', true],
        'insert' => ['insert into users (name) values (?)', false],
        'update' => ['update users set name = ? where id = ?', false],
        'delete' => ['delete from users where id = ?', false],
        'a write that selects first in a comment' => ['/* select */ update users set name = ?', false],
    ]);

    it('says reads are told by the first keyword, with every answer', function () {
        ingest(npoRun('one', 3));

        expect(npoAnswer()['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_reads')]);
    });

    it('says so also when it is not evaluated', function () {
        ingest([npoExecution('quiet')]);

        expect(npoAnswer()['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_reads')]);
    });

    it('does not count repeated writes, which are batching', function () {
        ingest([
            ...npoRun('one', 40, sql: 'insert into logs (message) values (?)'),
            ...npoRun('two', 2),
        ]);

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2]);
    });
});

describe('runs', function () {
    it('counts the runs of one query shape in one execution, never across executions', function () {
        ingest([...npoRun('one', 2), ...npoRun('two', 2), ...npoRun('three', 2)]);

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 3]);
    });

    it('counts a query shape on its own, and not the queries of the execution', function () {
        ingest([
            npoExecution('one', fields: ['queries' => 4]),
            npoQuery('one', 'select * from users where id = ?'),
            npoQuery('one', 'select * from users where id = ?'),
            npoQuery('one', 'select * from orders where id = ?'),
            npoQuery('one', 'select * from orders where id = ?'),
        ]);

        expect(npoAnswer()['result']['verdict'])->toBe('clean');
    });

    it('does not tell a query shape apart by its connection', function () {
        ingest([
            npoExecution('one', fields: ['queries' => 3]),
            npoQuery('one', fields: ['connection' => 'mysql']),
            npoQuery('one', fields: ['connection' => 'replica']),
            npoQuery('one', fields: ['connection' => 'mysql']),
        ]);

        expect(npoAnswer()['result']['findings'][0]['evidence']['worst_runs'])->toBe(3);
    });

    it('makes a finding of each query shape in each execution group', function () {
        ingest([
            ...npoRun('a', 3, '/orders'),
            ...npoRun('b', 4, '/invoices'),
            npoExecution('c', '/both', fields: ['queries' => 6]),
            ...array_map(fn () => npoQuery('c'), range(1, 3)),
            ...array_map(fn () => npoQuery('c', 'select * from posts where id = ?'), range(1, 3)),
        ]);

        $findings = npoAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toHaveCount(4)
            ->and(array_map(fn (array $finding) => $finding['group'], $findings))->toContain(md5('/orders'), md5('/invoices'), md5('/both'))
            ->and(array_count_values(array_column($findings, 'group'))[md5('/both')])->toBe(2);
    });

    it('makes one finding of a group whose executions repeat the same shape', function () {
        ingest([...npoRun('a', 3), ...npoRun('b', 5), ...npoRun('c', 2)]);

        $envelope = npoAnswer();

        expect($envelope['result']['total'])->toBe(1)
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['worst_runs' => 5, 'executions_affected' => 2, 'total_runs' => 8]);
    });

    it('takes the threshold of runs from the call, and refuses one below two', function () {
        ingest(npoRun('one', 2));

        $default = npoAnswer();
        $lowered = npoAnswer(['threshold' => 2]);

        expect($default['result']['verdict'])->toBe('clean')
            ->and($lowered['result'])->toMatchArray(['verdict' => 'findings'])
            ->and($lowered['result']['threshold'])->toMatchArray(['value' => 2, 'default' => 3, 'is_default' => false]);
    });

    it('refuses a threshold that is no whole number of two or more, and states the range', function (mixed $threshold) {
        $refusal = npoRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a whole number of 2 or more');
    })->with([
        'zero' => [0],
        'one' => [1],
        'negative' => [-3],
        'a fraction' => [2.5],
    ]);
});

describe('a finding', function () {
    it('carries the execution group, the label of the execution and the query, the runs and the places it was seen', function () {
        ingest([
            ...npoRun('first', 3, execution: ['timestamp' => NPO_AT, 'user' => 'u1']),
            ...npoRun('second', 5, execution: ['timestamp' => NPO_AT + 60, 'user' => '']),
        ]);

        $finding = npoAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray([
            'group' => md5('/orders'),
            'name' => '/orders: '.NPO_USER_SQL,
            'count' => 5,
            'first_seen_at' => NPO_AT,
            'last_seen_at' => NPO_AT + 60,
            'latest_execution_id' => 'second',
            'worst_execution_id' => 'second',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
        ]);
    });

    it('names an execution that matched no route as such', function () {
        ingest(npoRun('one', 3, label: ''));

        expect(npoAnswer()['result']['findings'][0]['name'])->toStartWith(__('firewatch::messages.rank_no_route').': ');
    });

    it('names an execution that was stored without a route as such', function () {
        ingest(npoRun('one', 3, execution: ['route_path' => null]));

        $finding = npoAnswer()['result']['findings'][0];

        expect($finding['name'])->toStartWith(__('firewatch::messages.rank_no_route').': ')
            ->and($finding['evidence']['unit']['label'])->toBe(__('firewatch::messages.rank_no_route'));
    });

    it('labels a command, a job and a task by their names', function (RecordType $type, string $label, string $source) {
        ingest(npoRun('one', 3, $label, type: $type));

        $finding = npoAnswer()['result']['findings'][0];

        expect($finding['name'])->toBe("{$label}: ".NPO_USER_SQL)
            ->and($finding['evidence']['unit'])->toBe(['source' => $source, 'label' => $label]);
    })->with([
        'a command' => [RecordType::COMMAND, 'orders:ship', 'command'],
        'a job' => [RecordType::JOB_ATTEMPT, 'ShipOrder', 'job'],
        'a task' => [RecordType::SCHEDULED_TASK, 'prune-orders', 'schedule'],
        'a request' => [RecordType::REQUEST, '/orders', 'request'],
    ]);

    it('states the runs, the time they took and their share of the worst execution', function () {
        ingest([
            ...npoRun('small', 3, fields: ['duration' => 1_000], execution: ['duration' => 100_000]),
            ...npoRun('worst', 4, fields: ['duration' => 2_500], execution: ['duration' => 40_000]),
        ]);

        $evidence = npoAnswer()['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray([
            'worst_runs' => 4,
            'executions_affected' => 2,
            'total_runs' => 7,
            'total_ms' => 13.0,
            'worst_share_pct' => 25.0,
            'unit' => ['source' => 'request', 'label' => '/orders'],
        ]);
    });

    it('does not state a share of an execution that has no duration', function () {
        ingest(npoRun('one', 3, execution: ['duration' => 0]));

        expect(npoAnswer()['result']['findings'][0]['evidence']['worst_share_pct'])->toBeNull();
    });

    it('lists up to three call sites, the places with most runs first', function () {
        ingest([
            npoExecution('one', fields: ['queries' => 9]),
            ...array_map(fn () => npoQuery('one', fields: ['file' => 'app/A.php', 'line' => 1]), range(1, 4)),
            ...array_map(fn () => npoQuery('one', fields: ['file' => 'app/B.php', 'line' => 2]), range(1, 3)),
            ...array_map(fn () => npoQuery('one', fields: ['file' => 'app/C.php', 'line' => 3]), range(1, 2)),
            npoQuery('one', fields: ['file' => 'app/D.php', 'line' => 4]),
        ]);

        expect(npoAnswer()['result']['findings'][0]['evidence']['call_sites'])->toBe([
            ['file' => 'app/A.php', 'line' => 1, 'runs' => 4],
            ['file' => 'app/B.php', 'line' => 2, 'runs' => 3],
            ['file' => 'app/C.php', 'line' => 3, 'runs' => 2],
        ]);
    });

    it('takes as the worst execution the one with most runs, then the longest runs, then the latest', function (array $runs, string $worst) {
        ingest(array_merge(...array_map(
            fn (array $run, string $id) => npoRun($id, $run['runs'], fields: ['duration' => $run['micros']], execution: ['timestamp' => NPO_AT + $run['at']]),
            $runs,
            array_keys($runs),
        )));

        expect(npoAnswer()['result']['findings'][0]['worst_execution_id'])->toBe($worst);
    })->with([
        'most runs' => [['few' => ['runs' => 3, 'micros' => 9_000, 'at' => 0], 'many' => ['runs' => 4, 'micros' => 1_000, 'at' => 0]], 'many'],
        'longest runs of as many' => [['short' => ['runs' => 3, 'micros' => 1_000, 'at' => 5], 'long' => ['runs' => 3, 'micros' => 2_000, 'at' => 0]], 'long'],
        'latest of equal ones' => [['older' => ['runs' => 3, 'micros' => 1_000, 'at' => 0], 'newer' => ['runs' => 3, 'micros' => 1_000, 'at' => 5]], 'newer'],
    ]);

    it('names the latest execution that qualified, which need not be the worst', function () {
        ingest([
            ...npoRun('old-but-worst', 6, execution: ['timestamp' => NPO_AT]),
            ...npoRun('recent', 3, execution: ['timestamp' => NPO_AT + 30]),
        ]);

        $finding = npoAnswer()['result']['findings'][0];

        expect($finding['latest_execution_id'])->toBe('recent')
            ->and($finding['worst_execution_id'])->toBe('old-but-worst');
    });

    it('counts the signed-in actors and the executions with none apart, and never adds them', function () {
        ingest([
            ...npoRun('a', 3, execution: ['user' => 'u1']),
            ...npoRun('b', 3, execution: ['user' => 'u1']),
            ...npoRun('c', 3, execution: ['user' => 'u2']),
            ...npoRun('d', 3, execution: ['user' => '']),
        ]);

        expect(npoAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });

    it('does not say how many different bindings there were when a query could not be paired with its bindings', function () {
        ingest(npoRun('one', 3));

        expect(npoAnswer()['result']['findings'][0]['evidence']['distinct_bindings'])->toBeNull();
    });
});

describe('the order', function () {
    it('lists the most runs first, then the most time, then the group', function () {
        ingest([
            ...npoRun('few', 3, '/few'),
            ...npoRun('many', 7, '/many'),
            ...npoRun('slow', 3, '/slow', fields: ['duration' => 9_000]),
        ]);

        expect(array_map(fn (array $finding) => $finding['group'], npoAnswer()['result']['findings']))->toBe([md5('/many'), md5('/slow'), md5('/few')]);
    });

    it('settles a tie by the group hash, then the query group hash', function () {
        $groups = [md5('/b'), md5('/a')];
        sort($groups);
        ingest([...npoRun('b', 3, '/b'), ...npoRun('a', 3, '/a')]);

        expect(array_map(fn (array $finding) => $finding['group'], npoAnswer()['result']['findings']))->toBe($groups);
    });

    it('lists no more findings than the limit, and says how many there are', function () {
        ingest([...npoRun('a', 5, '/a'), ...npoRun('b', 4, '/b'), ...npoRun('c', 3, '/c')]);

        $envelope = npoAnswer(['limit' => 2]);

        expect($envelope['result']['total'])->toBe(3)
            ->and($envelope['result']['findings'])->toHaveCount(2)
            ->and($envelope['truncated'][0])->toMatchArray(['section' => 'findings', 'shown' => 2, 'matched' => 3]);
    });
});

describe('incomplete executions', function () {
    it('says how many examined executions have fewer captured than counted queries', function () {
        ingest([
            ...npoRun('one', 3, execution: ['queries' => 9]),
            ...npoRun('two', 3, execution: ['queries' => 3]),
            ...npoRun('three', 1, execution: ['queries' => 5]),
        ]);

        expect(npoAnswer()['result']['caveats'])->toBe([
            __('firewatch::messages.detect_caveat_reads'),
            trans_choice('firewatch::messages.detect_caveat_incomplete', 2, ['count' => 2]),
        ]);
    });

    it('says so for one execution in the singular', function () {
        ingest(npoRun('one', 3, execution: ['queries' => 4]));

        expect(npoAnswer()['result']['caveats'][1])->toBe(trans_choice('firewatch::messages.detect_caveat_incomplete', 1, ['count' => 1]));
    });

    it('says nothing of an execution whose counter is met, or that has no counter', function () {
        ingest([
            ...npoRun('met', 3),
            ...npoRun('more-captured', 3, execution: ['queries' => 1]),
            ...npoRun('no-counter', 3, execution: ['queries' => null]),
        ]);

        expect(npoAnswer()['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_reads')]);
    });

    it('does not leave an incomplete execution out, as it is judged on the queries captured', function () {
        ingest(npoRun('one', 3, execution: ['queries' => 90]));

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1]);
    });
});

describe('the history', function () {
    /**
     * Clear the queries at an instant, then store the queries that ran after it for executions that started before: what is left of a run that a clear cut in two.
     *
     * @param  list<string>  $ids
     */
    function npoClearThenRun(float $clearedAt, array $ids): void
    {
        test()->travelTo(Date::createFromTimestamp($clearedAt));
        test()->artisan('firewatch:clear', ['--type' => 'query', '--force' => true])->assertSuccessful();
        ingest(array_merge(...array_map(fn (string $id) => array_map(fn () => npoQuery($id), range(1, 3)), $ids)));
    }

    it('leaves out the executions that started before the queries were last cleared', function (float $clearedAt, int $examined) {
        ingest([npoExecution('first', fields: ['queries' => 3]), npoExecution('second', '/second', fields: ['timestamp' => NPO_AT + 60, 'queries' => 3])]);
        npoClearThenRun($clearedAt, ['first', 'second']);

        expect(npoAnswer()['result']['examined'])->toBe($examined);
    })->with([
        'before both started' => [NPO_AT - 1, 2],
        'as the first started' => [NPO_AT, 2],
        'after the first started' => [NPO_AT + 1, 1],
        'as the second started' => [NPO_AT + 60, 1],
        'after both started' => [NPO_AT + 61, 0],
    ]);

    it('judges an execution that started before the store was created, as the creation removed none of its queries', function () {
        $this->travelTo(Date::createFromTimestamp(NPO_AT + 3600));
        ingest(npoRun('one', 3));

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1]);
    });

    it('is not evaluated, as outside the coverage, when history left out every execution of the window', function () {
        ingest([npoExecution('one', fields: ['queries' => 3])]);
        npoClearThenRun(NPO_AT + 600, ['one']);

        $envelope = npoAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'outside_coverage', 'examined' => 0, 'total' => 0])
            ->and($envelope['result']['caveats'])->toBe([__('firewatch::messages.detect_caveat_reads')])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'n-plus-one', 'reason' => 'outside_coverage']))
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('history-cleared');
    });

    it('is not evaluated for want of records, not outside the coverage, when an execution after the clear ran no query', function () {
        ingest([npoExecution('before', fields: ['queries' => 3])]);
        npoClearThenRun(NPO_AT + 600, ['before']);
        ingest([npoExecution('after', fields: ['timestamp' => NPO_AT + 700])]);

        expect(npoAnswer()['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records']);
    });
});

describe('the group', function () {
    it('restricts the judgement to the executions of one group', function () {
        ingest([...npoRun('a', 3, '/a'), ...npoRun('b', 3, '/b'), ...npoRun('c', 1, '/b')]);

        $envelope = npoAnswer(['group' => md5('/b')]);

        expect($envelope['result'])->toMatchArray(['examined' => 2, 'total' => 1])
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('/b'));
    });

    it('answers a group that holds no executions as no match, not as an error', function () {
        ingest(npoRun('a', 3, '/a'));

        $envelope = npoAnswer(['group' => md5('/elsewhere')]);

        expect($envelope['result']['verdict'])->toBe('not_evaluated')
            ->and($envelope['empty']['kind'])->toBe('no_match');
    });

    it('does not call a group no match when history left out its executions', function () {
        ingest([npoExecution('one', fields: ['queries' => 3])]);
        npoClearThenRun(NPO_AT + 600, ['one']);

        $envelope = npoAnswer(['group' => md5('/orders')]);

        expect($envelope['result']['reason'])->toBe('outside_coverage')
            ->and($envelope['empty'])->toBeNull();
    });

    it('refuses the hash of a query group, which is a different question', function () {
        ingest(npoRun('a', 3));

        $refusal = npoRefusal(['group' => md5(NPO_USER_SQL)]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: group')
            ->toContain('query group');
    });
});

describe('what it points at', function () {
    it('offers the worst execution, the records of the group and its ranking, and every call runs', function () {
        ingest([...npoRun('old-but-worst', 6), ...npoRun('recent', 3, execution: ['timestamp' => NPO_AT + 30]), ...npoRun('other', 3, '/other')]);

        $envelope = npoAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect($envelope['next'][0])->toMatchArray(['tool' => 'execution', 'arguments' => ['execution_id' => 'old-but-worst']])
            ->and(array_column(array_slice($envelope['next'], 1, 2), 'tool'))->toBe(['occurrences', 'rank']);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is one row of the overview, first in the catalogue, with its worst finding', function () {
        ingest([...npoRun('one', 3), npoExecution('plain', '/plain', fields: ['status_code' => 200])]);

        $envelope = Envelope::assert(Overview::class);
        $row = $envelope['result']['detectors'][0];

        expect($row)->toBe([
            'detector' => 'n-plus-one',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 1,
            'total' => 1,
            'worst' => ['name' => '/orders: '.NPO_USER_SQL, 'group' => md5('/orders')],
        ])->and($envelope['next'][0])->toBe(['tool' => 'detect', 'arguments' => ['shape' => 'n-plus-one'], 'why' => __('firewatch::messages.detect_next_shape')]);
    });

    it('is run with the others when no shape is named', function () {
        ingest(npoRun('one', 3));

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'memory'])
            ->and($detectors[0])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });

    it('is not given a threshold or a group when the others are run too', function (string $argument, mixed $value) {
        $response = FirewatchServer::tool(Detect::class, [$argument => $value]);

        expect((fn () => $this->content())->call($response)[0])->toStartWith('error: conflicting_arguments');
    })->with([
        'a threshold' => ['threshold', 3],
        'a group' => ['group', 'a'],
    ]);
});

describe('the blind spots', function () {
    it('states that bindings may be unpaired and that the lazy-load counter is dead', function () {
        ingest(npoRun('one', 3));

        expect(array_column(npoAnswer()['blind_spots'], 'id'))->toContain('query-bindings-unpaired', 'dead-counters');
    });
});
