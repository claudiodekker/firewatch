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

const DBB_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(DBB_AT - 3600));
});

/**
 * Build the records of a request that spent some microseconds in queries.
 *
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function dbbRequest(string $id, int $duration, int $queryMicros, string $route = '/orders', array $fields = [], string $sql = 'select * from orders'): array
{
    return [
        syntheticRecord(RecordType::REQUEST)->inExecution($id)->with([
            '_group' => md5($route),
            'route_path' => $route,
            'timestamp' => DBB_AT,
            'duration' => $duration,
            'queries' => $queryMicros > 0 ? 1 : 0,
            ...$fields,
        ]),
        ...($queryMicros > 0 ? [dbbQuery($id, $queryMicros, $sql)] : []),
    ];
}

/**
 * Build a request's query record.
 */
function dbbQuery(string $id, int $micros, string $sql = 'select * from orders'): RecordBuilder
{
    return syntheticRecord(RecordType::QUERY)->inExecution($id)->with([
        '_group' => md5($sql),
        'timestamp' => DBB_AT + 0.001,
        'sql' => $sql,
        'duration' => $micros,
    ]);
}

/**
 * Build the records of requests of one group with a share in queries.
 *
 * @param  list<int>  $percents
 * @return list<RecordBuilder>
 */
function dbbShares(array $percents, string $route = '/orders'): array
{
    return array_merge(...array_map(
        fn (int $percent, int $index) => dbbRequest("{$route}-{$index}", 100_000, $percent * 1_000, $route, ['timestamp' => DBB_AT + $index]),
        $percents,
        array_keys($percents),
    ));
}

/**
 * Get the `database-bound` answer of the tool.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function dbbAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'database-bound', ...$arguments]);
}

/**
 * Get the text of the tool's refusal.
 *
 * @param  array<string, mixed>  $arguments
 */
function dbbRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'database-bound', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when the typical share of a group is at least the threshold, over every request examined', function () {
        ingest([...dbbShares([80, 80, 80]), ...dbbShares([5, 5, 5], '/health')]);

        $envelope = dbbAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'database-bound', 'verdict' => 'findings', 'reason' => null, 'examined' => 6, 'total' => 1, 'saw' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'database-bound', 'total' => 1, 'examined' => 6, 'input' => __('firewatch::messages.detect_input.database-bound')]))
            ->and($envelope['result']['threshold'])->toBe(['name' => 'percent', 'value' => 60, 'default' => 60, 'unit' => 'percent', 'range' => ['min' => 1, 'max' => 100], 'is_default' => true])
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('/orders'));
    });

    it('tells 59 percent from 60, on the aggregate share of a group of one or two requests', function (array $percents, string $verdict) {
        ingest(dbbShares($percents));

        expect(dbbAnswer()['result']['verdict'])->toBe($verdict);
    })->with([
        'one request at 59' => [[59], 'clean'],
        'one request at 60' => [[60], 'findings'],
        'two requests at 59' => [[59, 59], 'clean'],
        'two requests at 60' => [[60, 60], 'findings'],
    ]);

    it('tells 59 percent from 60, on the median share of a group of three requests', function (array $percents, string $verdict) {
        ingest(dbbShares($percents));

        expect(dbbAnswer()['result']['verdict'])->toBe($verdict);
    })->with([
        'a median of 59' => [[10, 59, 99], 'clean'],
        'a median of 60' => [[10, 60, 99], 'findings'],
    ]);

    it('tells 4.999 milliseconds from 5, in the typical duration of a request that spent all its time in queries', function (int $micros, string $verdict) {
        ingest(dbbRequest('one', $micros, $micros));

        expect(dbbAnswer()['result']['verdict'])->toBe($verdict);
    })->with([
        'just under 5 ms' => [4_999, 'clean'],
        'exactly 5 ms' => [5_000, 'findings'],
    ]);

    it('tells 4.999 milliseconds from 5, in the median duration of three requests, which a long request does not move', function (int $median, string $verdict) {
        ingest(array_merge(...array_map(
            fn (int $duration, int $index) => dbbRequest("r{$index}", $duration, $duration, '/orders', ['timestamp' => DBB_AT + $index]),
            [4_000, $median, 90_000],
            [0, 1, 2],
        )));

        $envelope = dbbAnswer();

        expect($envelope['result']['verdict'])->toBe($verdict);
    })->with([
        'a median of just under 5 ms' => [4_999, 'clean'],
        'a median of exactly 5 ms' => [5_000, 'findings'],
    ]);

    it('tells 4.9995 milliseconds from 5, in the mean duration of two requests', function (int $second, string $verdict) {
        ingest(array_merge(...array_map(
            fn (int $duration, int $index) => dbbRequest("r{$index}", $duration, $duration, '/orders', ['timestamp' => DBB_AT + $index]),
            [4_999, $second],
            [0, 1],
        )));

        $envelope = dbbAnswer();

        expect($envelope['result']['verdict'])->toBe($verdict);
    })->with([
        'a mean of 4,999.5 microseconds' => [5_000, 'clean'],
        'a mean of 5,000 microseconds' => [5_001, 'findings'],
    ]);

    it('is clean over the requests examined, and says how many', function () {
        ingest([...dbbShares([5, 5, 5]), ...dbbRequest('quiet', 50_000, 0, '/quiet')]);

        $envelope = dbbAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 4, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'database-bound', 'examined' => 4, 'input' => __('firewatch::messages.detect_input.database-bound')]));
    });

    it('examines requests, and not commands, jobs or tasks', function () {
        ingest([
            ...dbbShares([80]),
            syntheticRecord(RecordType::COMMAND),
            syntheticRecord(RecordType::JOB_ATTEMPT),
            syntheticRecord(RecordType::SCHEDULED_TASK),
        ]);

        expect(dbbAnswer()['result']['examined'])->toBe(1);
    });

    it('is not evaluated when no request was recorded', function () {
        ingest([syntheticRecord(RecordType::COMMAND)]);

        $envelope = dbbAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'database-bound', 'reason' => 'no_records']));
    });

    it('judges the requests that started in the window', function () {
        ingest([
            ...dbbRequest('early', 100_000, 90_000, '/early', ['timestamp' => DBB_AT - 600]),
            ...dbbRequest('late', 100_000, 90_000, '/late', ['timestamp' => DBB_AT + 300]),
        ]);

        $envelope = dbbAnswer(['since' => DBB_AT]);

        expect($envelope['result'])->toMatchArray(['examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['name'])->toBe('/late');
    });

    it('takes a threshold from the call, and states that it is no default', function () {
        ingest(dbbShares([59, 59, 59]));

        $default = dbbAnswer();
        $lowered = dbbAnswer(['threshold' => 50]);

        expect($default['result']['verdict'])->toBe('clean')
            ->and($lowered['result']['verdict'])->toBe('findings')
            ->and($lowered['result']['threshold'])->toMatchArray(['value' => 50, 'default' => 60, 'is_default' => false]);
    });

    it('accepts a threshold of 100 percent, which only a request that spent all its time in queries reaches', function (int $percent, string $verdict) {
        ingest(dbbShares([$percent, $percent, $percent]));

        $envelope = dbbAnswer(['threshold' => 100]);

        expect($envelope['result']['verdict'])->toBe($verdict);
    })->with([
        'a share of 99' => [99, 'clean'],
        'a share of 100' => [100, 'findings'],
    ]);

    it('takes a fraction of a percent as the threshold', function (int $queryMicros, string $verdict) {
        ingest(dbbRequest('one', 100_000, $queryMicros));

        $envelope = dbbAnswer(['threshold' => 59.5]);

        expect($envelope['result']['verdict'])->toBe($verdict)
            ->and($envelope['result']['threshold'])->toMatchArray(['value' => 59.5, 'is_default' => false]);
    })->with([
        'a share of 59.4' => [59_400, 'clean'],
        'a share of 59.5' => [59_500, 'findings'],
    ]);

    it('refuses a threshold out of range, and states the range', function (mixed $threshold) {
        expect(dbbRefusal(['threshold' => $threshold]))->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a number of 1 to 100');
    })->with([
        'below one' => [0.9],
        'above a hundred' => [100.1],
    ]);
});

describe('the typical share', function () {
    it('is the median of the shares from three requests, which a long request does not move', function () {
        ingest([
            ...dbbRequest('long', 1_000_000, 1_000_000, '/orders', ['timestamp' => DBB_AT]),
            ...dbbRequest('a', 100_000, 10_000, '/orders', ['timestamp' => DBB_AT + 1]),
            ...dbbRequest('b', 100_000, 10_000, '/orders', ['timestamp' => DBB_AT + 2]),
        ]);

        $default = dbbAnswer();
        $lowered = dbbAnswer(['threshold' => 10]);

        expect($default['result'])->toMatchArray(['verdict' => 'clean'])
            ->and($lowered['result']['findings'][0]['evidence'])->toMatchArray(['share_pct' => 10.0, 'basis' => 'median', 'aggregate_share_pct' => 85.0]);
    });

    it('is the aggregate share below three requests, which a long request moves', function () {
        ingest([
            ...dbbRequest('long', 1_000_000, 1_000_000, '/orders', ['timestamp' => DBB_AT]),
            ...dbbRequest('a', 100_000, 10_000, '/orders', ['timestamp' => DBB_AT + 1]),
        ]);

        $evidence = dbbAnswer()['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['share_pct' => 91.8, 'basis' => 'aggregate', 'aggregate_share_pct' => 91.8]);
    });

    it('takes the observed share at the nearest rank of the median', function () {
        ingest(dbbShares([90, 20, 70, 30]));

        expect(dbbAnswer(['threshold' => 1])['result']['findings'][0]['evidence']['share_pct'])->toEqual(30);
    });

    it('is the median duration from three requests and the mean below', function (array $durations, float $typicalMs, string $basis) {
        ingest(array_merge(...array_map(
            fn (int $duration, int $index) => dbbRequest("r{$index}", $duration, $duration, '/orders', ['timestamp' => DBB_AT + $index]),
            $durations,
            array_keys($durations),
        )));

        $evidence = dbbAnswer()['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['typical_ms' => $typicalMs, 'basis' => $basis]);
    })->with([
        'three requests' => [[6_000, 10_000, 90_000], 10.0, 'median'],
        'two requests' => [[6_000, 10_000], 8.0, 'aggregate'],
        'one request' => [[7_000], 7.0, 'aggregate'],
    ]);

    it('leaves out the requests with no duration, or none to speak of, and counts them apart', function (bool $recorded) {
        $without = dbbRequest('without', 0, 0, '/orders', ['timestamp' => DBB_AT + 10]);

        if (! $recorded) {
            $without[0]->without('duration');
        }

        ingest([...dbbShares([80, 80, 80]), ...$without]);

        $envelope = dbbAnswer();

        expect($envelope['result']['examined'])->toBe(4)
            ->and($envelope['result']['saw'])->toBe(['without_duration' => 1])
            ->and($envelope['result']['findings'][0]['evidence']['basis'])->toBe('median');
    })->with([
        'a duration of zero' => [true],
        'no duration' => [false],
    ]);

    it('is clean when every request examined lacks a duration', function () {
        ingest(dbbRequest('without', 0, 0));

        expect(dbbAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'saw' => ['without_duration' => 1]]);
    });

    it('counts a request that ran no query as a share of nothing', function () {
        ingest(dbbShares([0, 0, 90]));

        expect(dbbAnswer()['result']['verdict'])->toBe('clean');
    });
});

describe('a finding', function () {
    it('names the route, and the requests it was seen in', function () {
        ingest([
            ...dbbRequest('first', 100_000, 90_000, '/orders', ['timestamp' => DBB_AT, 'user' => 'u1']),
            ...dbbRequest('second', 100_000, 90_000, '/orders', ['timestamp' => DBB_AT + 60, 'user' => '']),
            ...dbbRequest('third', 100_000, 90_000, '/orders', ['timestamp' => DBB_AT + 30, 'user' => 'u2']),
        ]);

        $finding = dbbAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray([
            'group' => md5('/orders'),
            'name' => '/orders',
            'count' => 90.0,
            'first_seen_at' => DBB_AT,
            'last_seen_at' => DBB_AT + 60,
            'latest_execution_id' => 'second',
            'reaches' => ['signed_in_actors' => 2, 'without_actor' => 1],
        ])->and($finding)->not->toHaveKey('worst_execution_id');
    });

    it('names a request that matched no route as such', function () {
        ingest(dbbRequest('one', 100_000, 90_000, ''));

        expect(dbbAnswer()['result']['findings'][0]['name'])->toBe(__('firewatch::messages.rank_no_route'));
    });

    it('takes the request stored last as the latest of two that started together', function () {
        ingest([
            ...dbbRequest('first', 100_000, 90_000),
            ...dbbRequest('second', 100_000, 90_000),
        ]);

        expect(dbbAnswer()['result']['findings'][0]['latest_execution_id'])->toBe('second');
    });

    it('states the share, the typical duration, the requests and the three queries that took longest', function () {
        ingest([
            ...dbbRequest('a', 100_000, 40_000, sql: 'select * from orders'),
            ...dbbRequest('b', 100_000, 40_000, sql: 'select * from orders'),
            ...dbbRequest('c', 100_000, 10_000, sql: 'select * from users'),
            dbbQuery('a', 8_000, 'select * from posts'),
            dbbQuery('a', 6_000, 'select * from tags'),
            dbbQuery('a', 1_000, 'select * from jobs'),
        ]);

        $evidence = dbbAnswer(['threshold' => 1])['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['requests' => 3, 'typical_ms' => 100.0])
            ->and($evidence['top_queries'])->toEqual([
                ['group' => md5('select * from orders'), 'sql' => 'select * from orders', 'calls' => 2, 'total_ms' => 80.0],
                ['group' => md5('select * from users'), 'sql' => 'select * from users', 'calls' => 1, 'total_ms' => 10.0],
                ['group' => md5('select * from posts'), 'sql' => 'select * from posts', 'calls' => 1, 'total_ms' => 8.0],
            ]);
    });

    it('lists the queries of a group of requests, and of no other', function () {
        ingest([...dbbRequest('mine', 100_000, 90_000, '/mine', sql: 'select 1'), ...dbbRequest('other', 100_000, 90_000, '/other', sql: 'select 2')]);

        $evidence = dbbAnswer(['group' => md5('/mine')])['result']['findings'][0]['evidence'];

        expect(array_column($evidence['top_queries'], 'sql'))->toBe(['select 1']);
    });

    it('counts writes too, as they take the time of a request as well', function () {
        ingest(dbbRequest('one', 100_000, 90_000, sql: 'insert into logs (message) values (?)'));

        expect(dbbAnswer()['result']['findings'][0]['evidence']['top_queries'][0]['sql'])->toBe('insert into logs (message) values (?)');
    });
});

describe('the order', function () {
    it('lists the highest share first, then the longest typical duration, then the group', function () {
        ingest([
            ...dbbRequest('low', 100_000, 70_000, '/low'),
            ...dbbRequest('high', 100_000, 95_000, '/high'),
            ...dbbRequest('long', 200_000, 140_000, '/long'),
            ...dbbRequest('b', 100_000, 70_000, '/b'),
            ...dbbRequest('a', 100_000, 70_000, '/a'),
        ]);

        $tied = ['/a', '/b', '/low'];
        usort($tied, fn (string $a, string $b) => md5($a) <=> md5($b));

        expect(array_column(dbbAnswer()['result']['findings'], 'name'))->toBe(['/high', '/long', ...$tied]);
    });

    it('lists no more findings than the limit, and says how many there are', function () {
        ingest([...dbbRequest('a', 100_000, 90_000, '/a'), ...dbbRequest('b', 100_000, 80_000, '/b'), ...dbbRequest('c', 100_000, 70_000, '/c')]);

        $envelope = dbbAnswer(['limit' => 2]);

        expect($envelope['result']['total'])->toBe(3)
            ->and($envelope['result']['findings'])->toHaveCount(2)
            ->and($envelope['truncated'][0])->toMatchArray(['section' => 'findings', 'shown' => 2, 'matched' => 3]);
    });
});

describe('incomplete executions', function () {
    it('says how many examined requests have fewer captured than counted queries', function () {
        ingest([
            ...dbbRequest('one', 100_000, 90_000, fields: ['queries' => 9]),
            ...dbbRequest('two', 100_000, 90_000, fields: ['queries' => 1]),
            ...dbbRequest('three', 100_000, 0, fields: ['queries' => 5]),
        ]);

        expect(dbbAnswer()['result']['caveats'])->toBe([trans_choice('firewatch::messages.detect_caveat_incomplete', 2, ['count' => 2])]);
    });

    it('does not leave an incomplete request out, as it is judged on the queries captured', function () {
        ingest(dbbRequest('one', 100_000, 90_000, fields: ['queries' => 90]));

        expect(dbbAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1]);
    });

    it('says nothing of a request whose counter is met, or that has none', function () {
        ingest([
            ...dbbRequest('met', 100_000, 90_000),
            ...dbbRequest('none', 100_000, 90_000, fields: ['queries' => null]),
        ]);

        expect(dbbAnswer()['result']['caveats'])->toBe([]);
    });
});

describe('the history', function () {
    /**
     * Clear the queries at an instant.
     */
    function dbbClearAt(float $clearedAt): void
    {
        test()->travelTo(Date::createFromTimestamp($clearedAt));
        test()->artisan('firewatch:clear', ['--type' => 'query', '--force' => true])->assertSuccessful();
    }

    it('leaves out the requests that started before the queries were last cleared', function (float $clearedAt, int $examined) {
        ingest([...dbbRequest('first', 100_000, 0), ...dbbRequest('second', 100_000, 0, fields: ['timestamp' => DBB_AT + 60])]);
        dbbClearAt($clearedAt);

        expect(dbbAnswer()['result']['examined'])->toBe($examined);
    })->with([
        'before both started' => [DBB_AT - 1, 2],
        'as the first started' => [DBB_AT, 2],
        'after the first started' => [DBB_AT + 1, 1],
        'as the second started' => [DBB_AT + 60, 1],
        'after both started' => [DBB_AT + 61, 0],
    ]);

    it('is not evaluated, as outside the coverage, when history left out every request of the window', function () {
        ingest(dbbRequest('one', 100_000, 90_000));
        dbbClearAt(DBB_AT + 600);

        $envelope = dbbAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'outside_coverage', 'examined' => 0, 'total' => 0])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'database-bound', 'reason' => 'outside_coverage']))
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('history-cleared');
    });

    it('is not evaluated for want of records, when no request is left out and none was recorded', function () {
        ingest([syntheticRecord(RecordType::COMMAND)]);
        dbbClearAt(DBB_AT + 600);

        expect(dbbAnswer()['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records']);
    });

    it('judges a request that started before the store was created, as the creation removed none of its queries', function () {
        $this->travelTo(Date::createFromTimestamp(DBB_AT + 3600));
        ingest(dbbRequest('one', 100_000, 90_000));

        expect(dbbAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1]);
    });
});

describe('the group', function () {
    it('restricts the judgement to the requests of one group', function () {
        ingest([...dbbRequest('a', 100_000, 90_000, '/a'), ...dbbRequest('b', 100_000, 90_000, '/b'), ...dbbRequest('c', 100_000, 80_000, '/b')]);

        $envelope = dbbAnswer(['group' => md5('/b')]);

        expect($envelope['result'])->toMatchArray(['examined' => 2, 'total' => 1])
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('/b'));
    });

    it('answers a group that holds no requests as no match, not as an error', function () {
        ingest(dbbRequest('a', 100_000, 90_000, '/a'));

        $envelope = dbbAnswer(['group' => md5('/elsewhere')]);

        expect($envelope['result']['verdict'])->toBe('not_evaluated')
            ->and($envelope['empty']['kind'])->toBe('no_match');
    });
});

describe('what it points at', function () {
    it('offers the latest request, the records of the group and its ranking, and every call runs', function () {
        ingest([...dbbRequest('a', 100_000, 90_000, '/a'), ...dbbRequest('b', 100_000, 80_000, '/b')]);

        $envelope = dbbAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect($envelope['next'][0])->toMatchArray(['tool' => 'execution', 'arguments' => ['execution_id' => 'a']])
            ->and(array_column(array_slice($envelope['next'], 1, 2), 'tool'))->toBe(['occurrences', 'rank']);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is one row of the overview, after n-plus-one, with its worst finding', function () {
        ingest(dbbRequest('one', 100_000, 90_000));

        $rows = Envelope::assert(Overview::class)['result']['detectors'];

        expect(array_column($rows, 'detector'))->toBe(['database-bound', 'n-plus-one', 'failing-routes', 'exception-clusters', 'memory', 'failing-jobs', 'queue-latency', 'failing-tasks', 'error-logs'])
            ->and($rows[0])->toBe([
                'detector' => 'database-bound',
                'verdict' => 'findings',
                'reason' => null,
                'examined' => 1,
                'total' => 1,
                'worst' => ['name' => '/orders', 'group' => md5('/orders')],
            ]);
    });

    it('is run with the others when no shape is named', function () {
        ingest(dbbRequest('one', 100_000, 90_000));

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'memory'])
            ->and($detectors[1])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});

describe('the blind spots', function () {
    it('states that query bindings may be unpaired and that console requests are not seen', function () {
        ingest(dbbRequest('one', 100_000, 90_000));

        expect(array_column(dbbAnswer()['blind_spots'], 'id'))->toContain('query-bindings-unpaired', 'console-requests');
    });
});
