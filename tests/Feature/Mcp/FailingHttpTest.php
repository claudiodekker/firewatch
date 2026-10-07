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

const FHP_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(FHP_AT + 3600));
});

/**
 * Build one outgoing request a request made to the host, of the group of the host.
 *
 * @param  array<string, mixed>  $fields
 */
function fhpCall(string $execution, ?int $status, string $host = 'api.example.test', array $fields = []): RecordBuilder
{
    $call = syntheticRecord(RecordType::OUTGOING_REQUEST)->inExecution($execution)->with([
        '_group' => md5($host),
        'host' => $host,
        'method' => 'POST',
        'url' => "https://{$host}/charges",
        'execution_source' => 'request',
        'timestamp' => FHP_AT + 1,
        'status_code' => $status,
        ...$fields,
    ]);

    return $status === null ? $call->without('status_code') : $call;
}

/**
 * Build the outgoing requests to one host that were answered with each of the statuses, in order.
 *
 * @param  list<int|null>  $statuses
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function fhpCalls(string $host, array $statuses, array $fields = []): array
{
    return array_map(
        fn (?int $status, int $index) => fhpCall("{$host}-".($index + 1), $status, $host, $fields),
        $statuses,
        array_keys($statuses),
    );
}

/**
 * Build one execution of the type, with the label.
 *
 * @param  array<string, mixed>  $fields
 */
function fhpExecution(string $execution, string $label = '/checkout', RecordType $type = RecordType::REQUEST, array $fields = []): RecordBuilder
{
    return syntheticRecord($type)->inExecution($execution)->with([
        '_group' => md5("execution {$label}"),
        $type === RecordType::REQUEST ? 'route_path' : 'name' => $label,
        'timestamp' => FHP_AT + 1,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function fhpAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'failing-http', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function fhpRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'failing-http', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when an outgoing request to a host was answered with an error status, over every one examined, and states the threshold', function () {
        ingest([...fhpCalls('api.example.test', [200, 500]), ...fhpCalls('cdn.example.test', [200])]);

        $envelope = fhpAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'failing-http', 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => []])
            ->and($envelope['result']['threshold'])->toBe(['name' => 'status', 'value' => 400, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => true])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'failing-http', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-http')]))
            ->and($envelope['result']['findings'][0]['group'])->toBe(md5('api.example.test'));
    });

    it('takes a status of 400 or above for a failure by default, and a redirect or no status for none', function (?int $status, string $verdict) {
        ingest([fhpCall('a', $status)]);

        expect(fhpAnswer()['result'])->toMatchArray(['verdict' => $verdict, 'examined' => 1]);
    })->with([
        'a success' => [200, 'clean'],
        'a redirect' => [302, 'clean'],
        'just under' => [399, 'clean'],
        'at the threshold' => [400, 'findings'],
        'just over' => [401, 'findings'],
        'too many requests' => [429, 'findings'],
        'a server error' => [503, 'findings'],
        'no status' => [null, 'clean'],
    ]);

    it('takes the lowest status that counts as failed from the call, and says that it is not the default', function (int $threshold, int $status, string $verdict) {
        ingest([fhpCall('a', $status)]);

        $result = fhpAnswer(['threshold' => $threshold])['result'];

        expect($result)->toMatchArray(['verdict' => $verdict, 'examined' => 1])
            ->and($result['threshold'])->toMatchArray(['value' => $threshold, 'default' => 400, 'is_default' => false]);
    })->with([
        'just under a raised threshold' => [500, 499, 'clean'],
        'at a raised threshold' => [500, 500, 'findings'],
        'just over a raised threshold' => [500, 501, 'findings'],
        'a redirect under a lowered threshold' => [300, 302, 'findings'],
        'the lowest threshold' => [100, 100, 'findings'],
        'the highest threshold' => [599, 598, 'clean'],
    ]);

    it('refuses a threshold that is no whole status from 100 to 599', function (mixed $threshold) {
        ingest([fhpCall('a', 500)]);

        $refusal = fhpRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a whole number of 100 to 599')
            ->toContain('detect(shape: "failing-http", threshold: 400)');
    })->with([
        'just under the range' => [99],
        'just over the range' => [600],
        'zero' => [0],
        'a fraction' => [400.5],
        'text' => ['400'],
    ]);

    it('is clean over the outgoing requests examined when none was answered with an error status, and says how many', function () {
        ingest([...fhpCalls('api.example.test', [200, 204]), ...fhpCalls('cdn.example.test', [301])]);

        $envelope = fhpAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 3, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'failing-http', 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-http')]));
    });

    it('is not evaluated when the window holds no outgoing request, never clean', function () {
        ingest([syntheticRecord(RecordType::REQUEST), syntheticRecord(RecordType::COMMAND)]);

        $envelope = fhpAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'failing-http', 'reason' => 'no_records']));
    });

    it('judges the outgoing requests that started in the window, the start included and the end not', function () {
        ingest([
            fhpCall('before', 500, fields: ['timestamp' => FHP_AT - 1]),
            fhpCall('at-since', 200, fields: ['timestamp' => FHP_AT]),
            fhpCall('at-until', 500, fields: ['timestamp' => FHP_AT + 10]),
        ]);

        $envelope = fhpAnswer(['since' => (string) FHP_AT, 'until' => (string) (FHP_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('finds an outgoing request that failed at the start of the window, and not one that failed at its end', function () {
        ingest([
            fhpCall('at-since', 500, fields: ['timestamp' => FHP_AT]),
            fhpCall('at-until', 500, 'cdn.example.test', ['timestamp' => FHP_AT + 10]),
        ]);

        $envelope = fhpAnswer(['since' => (string) FHP_AT, 'until' => (string) (FHP_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['latest_execution_id'])->toBe('at-since');
    });
});

describe('the finding', function () {
    it('states the host, its failed outgoing requests among all of them, and the latest of those', function () {
        ingest([
            fhpExecution('checkout'),
            fhpCall('checkout', 200, fields: ['timestamp' => FHP_AT]),
            fhpCall('checkout', 500, fields: ['timestamp' => FHP_AT + 60, 'user' => '7']),
            fhpCall('checkout', 429, fields: ['timestamp' => FHP_AT + 120, 'user' => '7']),
            fhpCall('checkout', 500, fields: ['timestamp' => FHP_AT + 180]),
            fhpCall('checkout', 200, fields: ['timestamp' => FHP_AT + 240]),
        ]);

        $envelope = fhpAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => md5('api.example.test'),
            'name' => 'api.example.test',
            'count' => 3,
            'first_seen_at' => FHP_AT + 60,
            'last_seen_at' => FHP_AT + 180,
            'latest_execution_id' => 'checkout',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'host' => 'api.example.test',
                'calls' => 5,
                'failures' => 3,
                'failure_pct' => 60.0,
                'status_counts' => [429 => 1, 500 => 2],
                'top_urls' => [['method' => 'POST', 'url' => 'https://api.example.test/charges', 'failures' => 3]],
                'ran_in' => [['source' => 'request', 'label' => '/checkout', 'calls' => 3]],
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('points at the latest failed outgoing request, and at the one stored last of two that started together', function () {
        ingest([
            fhpCall('a', 500, fields: ['timestamp' => FHP_AT + 5]),
            fhpCall('b', 500, fields: ['timestamp' => FHP_AT + 9]),
            fhpCall('c', 404, fields: ['timestamp' => FHP_AT + 9]),
            fhpCall('d', 500, fields: ['timestamp' => FHP_AT + 7]),
            fhpCall('e', 200, fields: ['timestamp' => FHP_AT + 20]),
            fhpCall('f', null, fields: ['timestamp' => FHP_AT + 30]),
        ]);

        $finding = fhpAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['latest_execution_id' => 'c', 'first_seen_at' => FHP_AT + 5, 'last_seen_at' => FHP_AT + 9]);
    });

    it('points at no execution when the latest failed outgoing request carries none', function () {
        ingest([
            fhpCall('a', 500, fields: ['timestamp' => FHP_AT + 5]),
            fhpCall('', 500, fields: ['timestamp' => FHP_AT + 9]),
        ]);

        expect(fhpAnswer()['result']['findings'][0]['latest_execution_id'])->toBeNull();
    });

    it('reports a host once, however many URLs and statuses failed', function () {
        ingest([
            fhpCall('a', 500, fields: ['url' => 'https://api.example.test/charges']),
            fhpCall('b', 404, fields: ['url' => 'https://api.example.test/refunds', 'method' => 'GET']),
            fhpCall('c', 429, fields: ['url' => 'https://api.example.test/rates', 'method' => 'GET']),
            fhpCall('d', 500, fields: ['url' => 'https://api.example.test/charges']),
        ]);

        $result = fhpAnswer()['result'];

        expect($result)->toMatchArray(['examined' => 4, 'total' => 1])
            ->and(array_column($result['findings'], 'name'))->toBe(['api.example.test'])
            ->and($result['findings'][0]['count'])->toBe(4);
    });

    it('counts an outgoing request without a status among the calls, and not in the share that failed', function () {
        ingest(fhpCalls('api.example.test', [500, null, null, 200]));

        $result = fhpAnswer()['result'];

        expect($result['examined'])->toBe(4)
            ->and($result['findings'][0]['evidence'])->toMatchArray(['calls' => 4, 'failures' => 1, 'failure_pct' => 50.0, 'status_counts' => [500 => 1]]);
    });

    it('reads a status that is no number as no status', function () {
        ingest([
            fhpCall('a', 200),
            fhpCall('b', 200)->with(['status_code' => '500']),
            fhpCall('c', 200)->with(['status_code' => 'teapot']),
        ]);

        expect(fhpAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 3]);
    });

    it('keeps the outgoing requests the wire sent without a group or a host as one finding, with its evidence', function () {
        ingest([
            fhpCall('a', 500)->without('_group', 'host'),
            fhpCall('b', 404)->without('_group', 'host'),
            fhpCall('c', 500),
        ]);

        $findings = fhpAnswer()['result']['findings'];

        expect(array_column($findings, 'group'))->toBe([null, md5('api.example.test')])
            ->and($findings[0])->toMatchArray(['name' => '', 'count' => 2])
            ->and($findings[0]['evidence'])->toMatchArray(['host' => null, 'calls' => 2, 'failures' => 2, 'status_counts' => [404 => 1, 500 => 1]])
            ->and($findings[0]['evidence']['top_urls'])->toBe([['method' => 'POST', 'url' => 'https://api.example.test/charges', 'failures' => 2]])
            ->and($findings[0]['evidence']['ran_in'])->toBe([['source' => 'request', 'label' => null, 'calls' => 2]])
            ->and($findings[1]['evidence'])->toMatchArray(['calls' => 1, 'failures' => 1, 'status_counts' => [500 => 1]]);
    });

    it('rounds the share that failed to one decimal', function () {
        ingest(fhpCalls('api.example.test', [500, 200, 200]));

        expect(fhpAnswer()['result']['findings'][0]['evidence']['failure_pct'])->toBe(33.3);
    });

    it('counts the signed-in users the failed outgoing requests reached, and those without one', function () {
        ingest([
            fhpCall('a', 500, fields: ['user' => '7']),
            fhpCall('b', 500, fields: ['user' => '7']),
            fhpCall('c', 500, fields: ['user' => '9']),
            fhpCall('d', 500),
            fhpCall('e', 200, fields: ['user' => '11']),
        ]);

        expect(fhpAnswer()['result']['findings'][0]['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 1]);
    });
});

describe('the statuses', function () {
    it('counts each status that failed, lowest first, and leaves out those under the threshold', function () {
        ingest(fhpCalls('api.example.test', [503, 200, 429, 302, 503, 404, 429, 503]));

        $counts = fhpAnswer()['result']['findings'][0]['evidence']['status_counts'];

        expect($counts)->toBe([404 => 1, 429 => 2, 503 => 3]);
    });

    it('counts the statuses from the threshold of the call up', function () {
        ingest(fhpCalls('api.example.test', [503, 200, 429, 302]));

        $evidence = fhpAnswer(['threshold' => 300])['result']['findings'][0]['evidence'];

        expect($evidence)->toMatchArray(['failures' => 3, 'failure_pct' => 75.0, 'status_counts' => [302 => 1, 429 => 1, 503 => 1]]);
    });
});

describe('the URLs', function () {
    it('lists a URL without its query string, so that calls that differ by it alone count as one', function () {
        ingest([
            fhpCall('a', 500, fields: ['url' => 'https://api.example.test/charges?order=7&token=secret']),
            fhpCall('b', 500, fields: ['url' => 'https://api.example.test/charges?order=12']),
            fhpCall('c', 500, fields: ['url' => 'https://api.example.test/charges']),
        ]);

        $urls = fhpAnswer()['result']['findings'][0]['evidence']['top_urls'];

        expect($urls)->toBe([['method' => 'POST', 'url' => 'https://api.example.test/charges', 'failures' => 3]]);
    });

    it('tells the methods of one URL apart', function () {
        ingest([
            fhpCall('a', 500, fields: ['method' => 'GET']),
            fhpCall('b', 500, fields: ['method' => 'POST']),
            fhpCall('c', 500, fields: ['method' => 'POST']),
        ]);

        $urls = fhpAnswer()['result']['findings'][0]['evidence']['top_urls'];

        expect($urls)->toBe([
            ['method' => 'POST', 'url' => 'https://api.example.test/charges', 'failures' => 2],
            ['method' => 'GET', 'url' => 'https://api.example.test/charges', 'failures' => 1],
        ]);
    });

    it('lists the three URLs that failed most, those that tie by their text, and none that only worked', function () {
        ingest([
            ...fhpCalls('api.example.test', [500], ['url' => 'https://api.example.test/once']),
            ...fhpCalls('api.example.test', [500, 500, 500], ['url' => 'https://api.example.test/thrice']),
            ...fhpCalls('api.example.test', [500, 500], ['url' => 'https://api.example.test/twice-b']),
            ...fhpCalls('api.example.test', [500, 500], ['url' => 'https://api.example.test/twice-a']),
            ...fhpCalls('api.example.test', [200, 200, 200, 200], ['url' => 'https://api.example.test/works']),
        ]);

        $urls = fhpAnswer()['result']['findings'][0]['evidence']['top_urls'];

        expect($urls)->toBe([
            ['method' => 'POST', 'url' => 'https://api.example.test/thrice', 'failures' => 3],
            ['method' => 'POST', 'url' => 'https://api.example.test/twice-a', 'failures' => 2],
            ['method' => 'POST', 'url' => 'https://api.example.test/twice-b', 'failures' => 2],
        ]);
    });

    it('lists the URLs of each host shown apart', function () {
        ingest([
            fhpCall('a', 500, 'api.example.test'),
            fhpCall('b', 500, 'cdn.example.test'),
            fhpCall('c', 500, 'cdn.example.test'),
        ]);

        $urls = array_column(array_column(fhpAnswer()['result']['findings'], 'evidence'), 'top_urls', 'host');

        expect($urls)->toBe([
            'cdn.example.test' => [['method' => 'POST', 'url' => 'https://cdn.example.test/charges', 'failures' => 2]],
            'api.example.test' => [['method' => 'POST', 'url' => 'https://api.example.test/charges', 'failures' => 1]],
        ]);
    });
});

describe('where it ran', function () {
    it('lists the three execution groups with the most failed outgoing requests, and none of those that only worked', function () {
        ingest([
            fhpExecution('checkout-1', '/checkout'),
            fhpExecution('checkout-2', '/checkout'),
            fhpExecution('sync', 'orders:sync', RecordType::COMMAND),
            fhpExecution('refund', '/refunds'),
            fhpExecution('ship', 'ShipOrder', RecordType::JOB_ATTEMPT),
            fhpExecution('home', '/'),
            fhpCall('checkout-1', 500),
            fhpCall('checkout-2', 500),
            fhpCall('checkout-2', 500),
            fhpCall('sync', 500, fields: ['execution_source' => 'command']),
            fhpCall('sync', 500, fields: ['execution_source' => 'command']),
            fhpCall('refund', 500),
            fhpCall('refund', 200),
            fhpCall('ship', 500, fields: ['execution_source' => 'job']),
            fhpCall('home', 200),
            fhpCall('home', 200),
        ]);

        expect(fhpAnswer()['result']['findings'][0]['evidence']['ran_in'])->toBe([
            ['source' => 'request', 'label' => '/checkout', 'calls' => 3],
            ['source' => 'command', 'label' => 'orders:sync', 'calls' => 2],
            ['source' => 'job', 'label' => 'ShipOrder', 'calls' => 1],
        ]);
    });

    it('lists the execution groups of each host shown apart', function () {
        ingest([
            fhpExecution('checkout', '/checkout'),
            fhpExecution('images', '/images'),
            fhpCall('checkout', 500, 'api.example.test'),
            fhpCall('images', 500, 'cdn.example.test'),
            fhpCall('images', 500, 'cdn.example.test'),
        ]);

        $units = array_column(array_column(fhpAnswer()['result']['findings'], 'evidence'), 'ran_in', 'host');

        expect($units)->toBe([
            'cdn.example.test' => [['source' => 'request', 'label' => '/images', 'calls' => 2]],
            'api.example.test' => [['source' => 'request', 'label' => '/checkout', 'calls' => 1]],
        ]);
    });

    it('reads the label of an execution that started before the window', function () {
        ingest([
            fhpExecution('long', 'reports:build', RecordType::COMMAND, ['timestamp' => FHP_AT - 60]),
            fhpCall('long', 500, fields: ['execution_source' => 'command', 'timestamp' => FHP_AT + 5]),
        ]);

        $result = fhpAnswer(['since' => (string) FHP_AT])['result'];

        expect($result['findings'][0]['evidence']['ran_in'])->toBe([['source' => 'command', 'label' => 'reports:build', 'calls' => 1]]);
    });

    it('states no label when the store holds no execution of the failed outgoing requests', function () {
        ingest([fhpCall('gone', 500), fhpCall('', 500)]);

        expect(fhpAnswer()['result']['findings'][0]['evidence']['ran_in'])->toBe([['source' => 'request', 'label' => null, 'calls' => 2]]);
    });

    it('labels a request that matched no route', function () {
        ingest([fhpExecution('a', ''), fhpCall('a', 500)]);

        expect(fhpAnswer()['result']['findings'][0]['evidence']['ran_in'])->toBe([['source' => 'request', 'label' => __('firewatch::messages.rank_no_route'), 'calls' => 1]]);
    });
});

describe('the order', function () {
    it('lists the host with the most failed outgoing requests first, whatever its share', function () {
        ingest([
            ...fhpCalls('once.example.test', [500]),
            ...fhpCalls('thrice.example.test', [500, 500, 500, 200, 200, 200, 200]),
            ...fhpCalls('twice.example.test', [500, 500]),
            ...fhpCalls('works.example.test', [200]),
        ]);

        expect(array_column(fhpAnswer()['result']['findings'], 'name'))->toBe(['thrice.example.test', 'twice.example.test', 'once.example.test']);
    });

    it('lists hosts with as many failed outgoing requests by the share that failed, then by the one that failed last', function () {
        ingest([
            ...fhpCalls('half.example.test', [500, 500, 200, 200], ['timestamp' => FHP_AT + 50]),
            ...fhpCalls('all-early.example.test', [500, 500], ['timestamp' => FHP_AT + 10]),
            ...fhpCalls('all-late.example.test', [500, 500], ['timestamp' => FHP_AT + 30]),
            ...fhpCalls('most.example.test', [500, 500, 200], ['timestamp' => FHP_AT + 40]),
        ]);

        expect(array_column(fhpAnswer()['result']['findings'], 'name'))->toBe(['all-late.example.test', 'all-early.example.test', 'most.example.test', 'half.example.test']);
    });

    it('orders by the share before it is rounded', function () {
        ingest([
            ...fhpCalls('lower.example.test', [500, ...array_fill(0, 45, 200)], ['timestamp' => FHP_AT + 50]),
            ...fhpCalls('higher.example.test', [500, ...array_fill(0, 44, 200)], ['timestamp' => FHP_AT + 10]),
        ]);

        $findings = fhpAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['higher.example.test', 'lower.example.test'])
            ->and(array_column(array_column($findings, 'evidence'), 'failure_pct'))->toBe([2.2, 2.2]);
    });

    it('lists hosts that tie by their group hash', function () {
        ingest([
            fhpCall('a', 500, 'tied-a.example.test'),
            fhpCall('b', 500, 'tied-b.example.test'),
            fhpCall('c', 500, 'tied-c.example.test'),
        ]);

        $tied = [md5('tied-a.example.test'), md5('tied-b.example.test'), md5('tied-c.example.test')];
        sort($tied, SORT_STRING);

        expect(array_column(fhpAnswer()['result']['findings'], 'group'))->toBe($tied);
    });

    it('orders two group hashes that read as the same number as text', function () {
        $later = '0e'.str_repeat('2', 30);
        $earlier = '0e'.str_repeat('1', 30);

        ingest([
            fhpCall('a', 500, 'later.example.test', ['_group' => $later]),
            fhpCall('b', 500, 'earlier.example.test', ['_group' => $earlier]),
        ]);

        expect(array_column(fhpAnswer()['result']['findings'], 'group'))->toBe([$earlier, $later]);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $host) => fhpCalls("host-{$host}.example.test", array_fill(0, $host, 500)), range(1, 4))));

        $envelope = fhpAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['host-4.example.test', 'host-3.example.test', 'host-2.example.test'])
            ->and($envelope['result'])->toMatchArray(['examined' => 10, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $host) => fhpCalls("host-{$host}.example.test", [500]), range(1, 3))));

        $envelope = fhpAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('one group', function () {
    it('restricts the judgement to the host, and examines only its outgoing requests', function () {
        ingest([...fhpCalls('api.example.test', [404, 200]), ...fhpCalls('cdn.example.test', [500, 500])]);

        $envelope = fhpAnswer(['group' => md5('api.example.test')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['api.example.test'])
            ->and($envelope['result']['findings'][0]['evidence'])->toMatchArray(['calls' => 2, 'failures' => 1, 'status_counts' => [404 => 1]]);
    });

    it('is clean when the host has outgoing requests and none failed', function () {
        ingest([...fhpCalls('api.example.test', [200]), ...fhpCalls('cdn.example.test', [500])]);

        expect(fhpAnswer(['group' => md5('api.example.test')])['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1, 'total' => 0]);
    });

    it('answers that no outgoing request matches a group that holds none', function () {
        ingest([...fhpCalls('api.example.test', [500]), ...fhpCalls('cdn.example.test', [200])]);

        $envelope = fhpAnswer(['group' => md5('missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 2]);
    });
});

describe('the caveats', function () {
    it('says what clean means and that an unanswered outgoing request leaves no record, whatever the verdict', function (array $statuses, array $arguments, string $verdict) {
        ingest([...fhpCalls('api.example.test', $statuses), syntheticRecord(RecordType::QUERY)]);

        $result = fhpAnswer($arguments)['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_unanswered')]);
    })->with([
        'with a failed outgoing request' => [[500], [], 'findings'],
        'when clean' => [[200], [], 'clean'],
        'with nothing examined' => [[], [], 'not_evaluated'],
        'for a group that holds none' => [[500], ['group' => md5('missing')], 'not_evaluated'],
    ]);
});

describe('the blind spots', function () {
    it('states that an unanswered outgoing request leaves no record, also when nothing was examined', function (array $statuses) {
        ingest([...fhpCalls('api.example.test', $statuses), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(fhpAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['unanswered-outgoing-requests', 'actor-partial'])
            ->and($blindSpots['unanswered-outgoing-requests'])->toBe(__('firewatch::messages.blind_spots.unanswered-outgoing-requests'))
            ->and($blindSpots['actor-partial'])->toBe(__('firewatch::messages.blind_spots.actor-partial'));
    })->with([
        'with findings' => [[500]],
        'when clean' => [[200]],
        'with nothing examined' => [[]],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to the execution of its latest failed outgoing request, its records and its group, then the next finding, and the calls run', function () {
        ingest([
            fhpExecution('a'),
            fhpExecution('b'),
            fhpExecution('c'),
            fhpCall('a', 500, fields: ['timestamp' => FHP_AT + 5]),
            fhpCall('b', 502, fields: ['timestamp' => FHP_AT + 10]),
            fhpCall('c', 404, 'cdn.example.test'),
            fhpCall('c', 200, 'cdn.example.test', ['timestamp' => FHP_AT + 50]),
        ]);

        $envelope = fhpAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'b'], ['group' => md5('api.example.test')], ['group' => md5('api.example.test')], ['execution_id' => 'c']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, with its worst finding', function () {
        ingest([
            ...fhpCalls('api.example.test', [404, 200]),
            ...fhpCalls('cdn.example.test', [500, 500]),
            ...fhpCalls('works.example.test', [200]),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'failing-http');

        expect($row)->toBe([
            'detector' => 'failing-http',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 5,
            'total' => 2,
            'worst' => ['name' => 'cdn.example.test', 'group' => md5('cdn.example.test')],
        ]);
    });

    it('is run between error-logs and memory when no shape is named', function () {
        ingest([fhpCall('a', 500)]);

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'memory'])
            ->and($detectors[8])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});
