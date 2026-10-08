<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Detectors\DetectorName;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\FakeDetector;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const DETECT_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(DETECT_AT + 3600));
});

function dtcGroup(string $route): string
{
    return md5($route);
}

/**
 * @param  array<string, mixed>  $fields
 */
function dtcRequest(string $route = '/orders', ?int $status = 200, array $fields = []): RecordBuilder
{
    $request = syntheticRecord(RecordType::REQUEST)->with([
        '_group' => dtcGroup($route),
        'route_path' => $route,
        'timestamp' => DETECT_AT,
        'duration' => 2_000_000,
        ...$fields,
    ]);

    return $status === null ? $request->without('status_code') : $request->with(['status_code' => $status]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function dtcAnswer(array $arguments = ['shape' => 'failing-routes']): array
{
    return Envelope::assert(Detect::class, $arguments);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function dtcRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

/**
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function dtcRun(array $call): array
{
    $tools = [
        'execution' => Execution::class,
        'occurrences' => Occurrences::class,
        'rank' => Rank::class,
        'detect' => Detect::class,
    ];

    return Envelope::assert($tools[$call['tool']], $call['arguments']);
}

/**
 * @param  array<string, mixed>  $envelope
 * @return array<string, mixed>
 */
function dtcFinding(array $envelope, string $route): array
{
    $findings = array_values(array_filter($envelope['result']['findings'], fn (array $finding) => $finding['group'] === dtcGroup($route)));

    return $findings[0];
}

describe('the verdict', function () {
    it('has findings when a request of a group ended at or above the status, over every request examined', function () {
        ingest([
            dtcRequest('/orders', 200),
            dtcRequest('/orders', 500),
            dtcRequest('/health', 200),
        ]);

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'failing-routes', 'verdict' => 'findings', 'reason' => null, 'examined' => 3, 'total' => 1, 'saw' => [], 'caveats' => []])
            ->and($envelope['result']['findings'])->toHaveCount(1)
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'failing-routes', 'total' => 1, 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-routes')]))
            ->and($envelope['empty'])->toBeNull();
    });

    it('states what it set aside as an empty object, as it sets nothing aside', function () {
        ingest([dtcRequest('/orders', 500)]);

        $text = (fn () => $this->content())->call(FirewatchServer::tool(Detect::class, ['shape' => 'failing-routes', 'format' => 'json']))[0];

        expect($text)->toContain('"saw":{}');
    });

    it('is clean over the requests examined when none failed', function () {
        ingest([dtcRequest('/orders', 200), dtcRequest('/orders', 204), dtcRequest('/health', 301)]);

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 3, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'failing-routes', 'examined' => 3, 'input' => __('firewatch::messages.detect_input.failing-routes')]));
    });

    it('is not evaluated when the store holds no request, even if it holds other records', function () {
        ingest([syntheticRecord(RecordType::COMMAND)]);

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'failing-routes', 'reason' => 'no_records']))
            ->and($envelope['empty'])->toBeNull()
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('console-requests');
    });

    it('counts a request without a status as examined and leaves it out of the judgement', function () {
        ingest([dtcRequest('/orders', null), dtcRequest('/orders', 200)]);

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 2, 'total' => 0]);
    });

    it('is not evaluated when the store holds no records, and says the store is empty', function () {
        app(Writer::class)->transaction(fn () => null);

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty']['kind'])->toBe('store_empty');
    });

    it('is not evaluated when the window holds none of the records, and says so', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'since' => (string) (DETECT_AT + 100)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'window_empty', 'population' => 1]);
    });

    it('is not evaluated when there is no store, with the store unavailable', function () {
        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'store_unavailable', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['empty']['kind'])->toBe('no_store')
            ->and($envelope['next'])->toBe([]);
    });

    it('is not evaluated when the store cannot be read, and says why', function () {
        $path = app(Configuration::class)->database;
        mkdir(dirname($path), recursive: true);
        file_put_contents($path, str_repeat('not a database ', 100));

        $envelope = dtcAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'store_unavailable', 'examined' => 0])
            ->and($envelope['empty']['kind'])->toBe('store_unusable')
            ->and($envelope['coverage']['state'])->toBe('unusable')
            ->and($envelope['next'])->toBe([]);
    });

    it('selects requests by the window on their own start', function () {
        ingest([
            dtcRequest('/orders', 500, ['timestamp' => DETECT_AT - 10]),
            dtcRequest('/orders', 200, ['timestamp' => DETECT_AT]),
        ]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'since' => (string) (DETECT_AT - 1), 'until' => (string) (DETECT_AT + 1)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('attaches the blind spots of requests, the actor and what the findings reach', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer();

        expect(array_column($envelope['blind_spots'], 'id'))->toContain('console-requests', 'payload-on-server-error-only', 'octane-bootstrap', 'actor-partial')
            ->and($envelope['coverage']['types_read'])->toBe(['request']);
    });
});

describe('the threshold', function () {
    it('is stated on every result, with the default applied', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer();

        expect($envelope['result']['threshold'])->toBe(['name' => 'status', 'value' => 400, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => true]);
    });

    it('is stated with the value asked for, and says the default did not apply', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'threshold' => 500]);

        expect($envelope['result']['threshold'])->toBe(['name' => 'status', 'value' => 500, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => false]);
    });

    it('states the default again when it is asked for', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'threshold' => 400]);

        expect($envelope['result']['threshold']['is_default'])->toBeTrue();
    });

    it('counts a status as failed from the threshold on, with 399 and 400 either side of the default', function (int $status, bool $failed) {
        ingest([dtcRequest('/orders', $status)]);

        $envelope = dtcAnswer();

        expect($envelope['result']['total'])->toBe($failed ? 1 : 0);
    })->with([
        'one below' => [399, false],
        'at it' => [400, true],
        'validation' => [422, true],
        'not found' => [404, true],
        'server error' => [500, true],
    ]);

    it('counts a status as failed from the threshold asked for on, and no lower', function () {
        ingest([dtcRequest('/orders', 404), dtcRequest('/health', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'threshold' => 500]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['/health']);
    });

    it('accepts the ends of the range', function (int $threshold) {
        ingest([dtcRequest('/orders', 200)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'threshold' => $threshold]);

        expect($envelope['result']['threshold']['value'])->toBe($threshold);
    })->with([100, 599]);

    it('refuses what is not a whole number from 100 to 599, and never clamps it', function (mixed $threshold, string $shown) {
        $refusal = dtcRefusal(['shape' => 'failing-routes', 'threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain("got {$shown}")
            ->toContain('a whole number of 100 to 599');
    })->with([
        'below' => [99, '99'],
        'above' => [600, '600'],
        'zero' => [0, '0'],
        'negative' => [-1, '-1'],
        'a fraction' => [400.5, '400.5'],
        'text' => ['400', '"400"'],
        'a flag' => [true, 'true'],
    ]);

    it('refuses a threshold without a shape, as it belongs to one shape', function () {
        $refusal = dtcRefusal(['threshold' => 400]);

        expect($refusal)->toStartWith('error: conflicting_arguments')
            ->toContain('argument: threshold')
            ->toContain('shape');
    });
});

describe('the shape', function () {
    it('refuses a shape that is none, naming those that ship', function (string $shape) {
        $refusal = dtcRefusal(['shape' => $shape]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: shape')
            ->toContain('n-plus-one, database-bound, failing-routes, failing-jobs, queue-latency, failing-tasks, exception-clusters, error-logs, failing-http, cache, memory');
    })->with(['nonsense', '']);

    it('runs every shape that ships when none is named, one result each', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer([]);

        expect(array_keys($envelope['result']))->toBe(['detectors'])
            ->and(array_column($envelope['result']['detectors'], 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'cache', 'memory'])
            ->and($envelope['result']['detectors'][2])->toMatchArray(['verdict' => 'findings', 'total' => 1])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_all_summary', ['parts' => implode('; ', [
                __('firewatch::messages.detect_part_findings', ['shapes' => 'failing-routes (1)']),
                __('firewatch::messages.detect_part_not_evaluated', ['reason' => 'no_records', 'shapes' => 'n-plus-one, failing-jobs, queue-latency, failing-tasks, error-logs, failing-http, cache']),
                __('firewatch::messages.detect_part_clean', ['shapes' => 'database-bound, exception-clusters, memory']),
            ])]));
    });

    it('says all the shapes are clean only when each one is', function () {
        ingest([
            dtcRequest('/orders', 200, ['trace_id' => 'one']),
            syntheticRecord(RecordType::QUERY)->inExecution('one'),
            syntheticRecord(RecordType::QUEUED_JOB)->with(['job_id' => 'shipment', 'timestamp' => DETECT_AT]),
            syntheticRecord(RecordType::JOB_ATTEMPT)->with(['job_id' => 'shipment', 'timestamp' => DETECT_AT]),
            syntheticRecord(RecordType::SCHEDULED_TASK)->with(['status' => 'processed']),
            syntheticRecord(RecordType::LOG),
            syntheticRecord(RecordType::OUTGOING_REQUEST),
            syntheticRecord(RecordType::CACHE_EVENT),
        ]);

        $envelope = dtcAnswer([]);

        expect($envelope['summary'])->toBe(trans_choice('firewatch::messages.detect_all_clean_summary', 11, ['count' => 11]));
    });

    it('names the shapes that were not evaluated, and why', function () {
        ingest([syntheticRecord(RecordType::COMMAND)]);

        $envelope = dtcAnswer([]);

        expect($envelope['summary'])->toBe(__('firewatch::messages.detect_all_summary', ['parts' => implode('; ', [
            __('firewatch::messages.detect_part_not_evaluated', ['reason' => 'no_records', 'shapes' => 'n-plus-one, database-bound, failing-routes, failing-jobs, queue-latency, failing-tasks, error-logs, failing-http, cache']),
            __('firewatch::messages.detect_part_clean', ['shapes' => 'exception-clusters, memory']),
        ])]))->not->toContain('firewatch::');
    });

    it('names every shape of the catalogue in a summary that is not cut', function (int $total) {
        FakeDetector::ship(...array_map(fn (DetectorName $name) => new FakeDetector($name, total: $total), DetectorName::cases()));
        ingest([dtcRequest('/orders', 200)]);

        $envelope = dtcAnswer([]);

        expect($envelope['summary'])->toEndWith('.')
            ->and(array_filter(DetectorName::cases(), fn (DetectorName $name) => ! str_contains($envelope['summary'], $name->value)))->toBe([]);
    })->with(['not evaluated' => 0, 'with findings' => 100]);

    it('refuses a threshold for a shape that takes none, and states none for it', function () {
        FakeDetector::ship(new FakeDetector(DetectorName::FAILING_TASKS, examined: 1));
        ingest([dtcRequest('/orders', 200)]);

        $refusal = dtcRefusal(['shape' => 'failing-tasks', 'threshold' => 3]);
        $envelope = dtcAnswer(['shape' => 'failing-tasks']);

        expect($refusal)->toStartWith('error: conflicting_arguments')->toContain('argument: threshold')->toContain('failing-tasks')
            ->and($envelope['result']['threshold'])->toBeNull();
    });

    it('refuses a group without a shape, as a group belongs to the population of one shape', function () {
        $refusal = dtcRefusal(['group' => dtcGroup('/orders')]);

        expect($refusal)->toStartWith('error: conflicting_arguments')->toContain('argument: group');
    });

    it('refuses an argument the tool does not take, and one that other tools take', function (string $argument, string $code) {
        $refusal = dtcRefusal(['shape' => 'failing-routes', $argument => 'x']);

        expect($refusal)->toStartWith("error: {$code}")->toContain("argument: {$argument}");
    })->with([
        'unknown' => ['colour', 'invalid_argument'],
        'of another tool' => ['deploy', 'conflicting_arguments'],
        'a cursor' => ['cursor', 'conflicting_arguments'],
    ]);
});

describe('the findings', function () {
    it('describes a group by what failed, with the status of each failure', function () {
        ingest([
            dtcRequest('/orders', 422, ['method' => 'POST']),
            dtcRequest('/orders', 422, ['method' => 'POST']),
            dtcRequest('/orders', 500, ['method' => 'POST']),
            dtcRequest('/orders', 200, ['method' => 'POST']),
            dtcRequest('/orders', null, ['method' => 'POST']),
        ]);

        $finding = dtcFinding(dtcAnswer(), '/orders');

        expect($finding)->toMatchArray(['group' => dtcGroup('/orders'), 'name' => '/orders', 'count' => 3])
            ->and($finding['evidence'])->toEqual([
                'failed' => 3,
                'requests' => 5,
                'failure_pct' => 75.0,
                'server_errors' => 1,
                'status_counts' => [422 => 2, 500 => 1],
                'with_exception' => 0,
                'unmatched' => false,
                'method' => 'POST',
            ]);
    });

    it('rounds the failure share to one decimal over the requests that have a status', function () {
        ingest([dtcRequest('/orders', 500), dtcRequest('/orders', 200), dtcRequest('/orders', 200)]);

        $finding = dtcFinding(dtcAnswer(), '/orders');

        expect($finding['evidence']['failure_pct'])->toEqual(33.3);
    });

    it('lists the failing statuses at the threshold asked for', function () {
        ingest([dtcRequest('/orders', 404), dtcRequest('/orders', 500), dtcRequest('/orders', 503)]);

        $finding = dtcFinding(dtcAnswer(['shape' => 'failing-routes', 'threshold' => 500]), '/orders');

        expect($finding['evidence'])->toMatchArray(['failed' => 2, 'server_errors' => 2, 'requests' => 3])
            ->and($finding['evidence']['status_counts'])->toEqual([500 => 1, 503 => 1]);
    });

    it('counts a failure that is not a server error by what is at or above the threshold', function () {
        ingest([dtcRequest('/orders', 404)]);

        $finding = dtcFinding(dtcAnswer(), '/orders');

        expect($finding['evidence'])->toMatchArray(['failed' => 1, 'server_errors' => 0]);
    });

    it('counts the failed requests whose execution has an exception record', function () {
        ingest([
            dtcRequest('/orders', 500, ['trace_id' => 'with']),
            dtcRequest('/orders', 500, ['trace_id' => 'without']),
            dtcRequest('/orders', 200, ['trace_id' => 'fine']),
            syntheticRecord(RecordType::EXCEPTION)->with(['trace_id' => 'with', 'execution_source' => 'request', 'execution_id' => 'with', 'timestamp' => DETECT_AT]),
            syntheticRecord(RecordType::EXCEPTION)->with(['trace_id' => 'with', 'execution_source' => 'request', 'execution_id' => 'with', 'timestamp' => DETECT_AT]),
            syntheticRecord(RecordType::EXCEPTION)->with(['trace_id' => 'fine', 'execution_source' => 'request', 'execution_id' => 'fine', 'timestamp' => DETECT_AT]),
        ]);

        $finding = dtcFinding(dtcAnswer(), '/orders');

        expect($finding['evidence']['with_exception'])->toBe(1);
    });

    it('marks the group of requests that matched no route, and labels it', function () {
        ingest([dtcRequest('', 404, ['route_path' => ''])]);

        $finding = dtcAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['name' => __('firewatch::messages.rank_no_route')])
            ->and($finding['evidence']['unmatched'])->toBeTrue();
    });

    it('gives the first and last failure of the group, and the execution of the latest', function () {
        ingest([
            dtcRequest('/orders', 500, ['timestamp' => DETECT_AT + 10, 'trace_id' => 'middle']),
            dtcRequest('/orders', 500, ['timestamp' => DETECT_AT + 20, 'trace_id' => 'latest']),
            dtcRequest('/orders', 500, ['timestamp' => DETECT_AT, 'trace_id' => 'first']),
            dtcRequest('/orders', 200, ['timestamp' => DETECT_AT + 30, 'trace_id' => 'fine']),
        ]);

        $finding = dtcFinding(dtcAnswer(), '/orders');

        expect($finding)->toMatchArray(['first_seen_at' => DETECT_AT, 'last_seen_at' => DETECT_AT + 20, 'latest_execution_id' => 'latest'])
            ->and($finding)->not->toHaveKey('worst_execution_id');
    });

    it('breaks a tie on the latest failure by the record stored last', function () {
        ingest([
            dtcRequest('/orders', 500, ['trace_id' => 'earlier']),
            dtcRequest('/orders', 500, ['trace_id' => 'later']),
        ]);

        expect(dtcFinding(dtcAnswer(), '/orders')['latest_execution_id'])->toBe('later');
    });

    it('says how many signed-in actors the failures reached, and how many had none, never added', function () {
        ingest([
            dtcRequest('/orders', 500, ['user' => '7']),
            dtcRequest('/orders', 500, ['user' => '7']),
            dtcRequest('/orders', 500, ['user' => '8']),
            dtcRequest('/orders', 500, ['user' => '']),
            dtcRequest('/orders', 500)->without('user'),
            dtcRequest('/orders', 200, ['user' => '9']),
        ]);

        expect(dtcFinding(dtcAnswer(), '/orders')['reaches'])->toBe(['signed_in_actors' => 2, 'without_actor' => 2]);
    });

    it('keeps apart groups whose hashes read as the same number', function () {
        ingest([
            dtcRequest('/first', 500, ['_group' => str_repeat('0', 28).'1e03', 'trace_id' => 'first']),
            dtcRequest('/second', 404, ['_group' => str_repeat('0', 28).'1000', 'trace_id' => 'second']),
        ]);

        $findings = array_column(dtcAnswer()['result']['findings'], null, 'name');

        expect($findings['/first']['latest_execution_id'])->toBe('first')
            ->and($findings['/first']['evidence']['status_counts'])->toEqual([500 => 1])
            ->and($findings['/second']['latest_execution_id'])->toBe('second')
            ->and($findings['/second']['evidence']['status_counts'])->toEqual([404 => 1]);
    });

    it('lists the failures of requests that carry no group, with no group to follow', function () {
        ingest([
            dtcRequest('/orders', 500, ['trace_id' => 'grouped']),
            dtcRequest('/orders', 500, ['trace_id' => 'loose'])->without('_group'),
        ]);

        $envelope = dtcAnswer();
        $loose = collect($envelope['result']['findings'])->firstWhere('group', null);

        expect($envelope['result']['total'])->toBe(2)
            ->and($loose)->toMatchArray(['name' => '/orders', 'count' => 1, 'latest_execution_id' => 'loose'])
            ->and(array_column($envelope['next'], 'tool'))->toBe(['execution', 'execution']);
    });

    it('orders the groups by server errors, then failures, then failure share, then group', function () {
        ingest([
            dtcRequest('/not-found', 404),
            dtcRequest('/not-found', 404),
            dtcRequest('/not-found', 404),
            dtcRequest('/one-error', 500),
            dtcRequest('/one-error', 200),
            dtcRequest('/one-error', 200),
            dtcRequest('/errors', 500),
            dtcRequest('/errors', 503),
            dtcRequest('/few-fine', 404),
            dtcRequest('/few-fine', 404),
            dtcRequest('/many-fine', 404),
            dtcRequest('/many-fine', 404),
            dtcRequest('/many-fine', 200),
            dtcRequest('/many-fine', 200),
        ]);

        $names = array_column(dtcAnswer()['result']['findings'], 'name');

        expect($names)->toBe(['/errors', '/one-error', '/not-found', '/few-fine', '/many-fine']);
    });

    it('breaks a complete tie by the group hash, ascending', function () {
        ingest([dtcRequest('/b', 404), dtcRequest('/a', 404), dtcRequest('/c', 404)]);

        $groups = array_column(dtcAnswer()['result']['findings'], 'group');
        $sorted = $groups;
        sort($sorted);

        expect($groups)->toBe($sorted)->and($groups)->toHaveCount(3);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_map(fn (int $route) => dtcRequest("/route-{$route}", 500), range(1, 4)));

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)
            ->and($envelope['result']['total'])->toBe(4)
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_map(fn (int $route) => dtcRequest("/route-{$route}", 500), range(1, 3)));

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });

    it('lists twenty findings by default', function () {
        ingest(array_map(fn (int $route) => dtcRequest("/route-{$route}", 500), range(1, 21)));

        $envelope = dtcAnswer();

        expect($envelope['result']['findings'])->toHaveCount(20)->and($envelope['result']['total'])->toBe(21);
    });

    it('refuses a limit that is not a whole number from 1 to 100', function (mixed $limit, string $shown) {
        $refusal = dtcRefusal(['shape' => 'failing-routes', 'limit' => $limit]);

        expect($refusal)->toStartWith('error: invalid_argument')->toContain('argument: limit')->toContain("got {$shown}");
    })->with([
        'zero' => [0, '0'],
        'over' => [101, '101'],
        'text' => ['5', '"5"'],
    ]);
});

describe('one group', function () {
    it('restricts the judgement to the group, and examines only its requests', function () {
        ingest([dtcRequest('/orders', 500), dtcRequest('/orders', 200), dtcRequest('/health', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'group' => dtcGroup('/orders')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 2, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['/orders']);
    });

    it('is clean when the group has requests and none failed', function () {
        ingest([dtcRequest('/orders', 200), dtcRequest('/health', 500)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'group' => dtcGroup('/orders')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1]);
    });

    it('answers that no request matches a group that holds none', function () {
        ingest([dtcRequest('/orders', 500), dtcRequest('/health', 200)]);

        $envelope = dtcAnswer(['shape' => 'failing-routes', 'group' => dtcGroup('/missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 2]);
    });

    it('answers that nothing matches a group by the counts a shape set aside, never by a block it carries beside them', function (array $saw, ?string $kind) {
        FakeDetector::ship(new FakeDetector(DetectorName::CACHE, saw: $saw));
        ingest([dtcRequest('/orders', 200)]);

        $envelope = dtcAnswer(['shape' => 'cache', 'group' => dtcGroup('/missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'saw' => $saw])
            ->and($envelope['empty']['kind'] ?? null)->toBe($kind);
    })->with([
        'a block alone' => [['activity' => ['stores' => [], 'total' => ['hits' => 0]]], 'no_match'],
        'a block beside a count of nothing' => [['activity' => ['stores' => []], 'excluded' => 0], 'no_match'],
        'a block beside a count set aside' => [['activity' => ['stores' => []], 'excluded' => 2], null],
    ]);

    it('refuses a group that is not a group hash', function (mixed $group) {
        $refusal = dtcRefusal(['shape' => 'failing-routes', 'group' => $group]);

        expect($refusal)->toStartWith('error: invalid_argument')->toContain('argument: group');
    })->with(['short', 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB', 42]);
});

describe('what to look at next', function () {
    it('follows the worst finding to its latest execution, its records and its group, and the calls run', function () {
        ingest([
            dtcRequest('/orders', 500, ['trace_id' => 'worst']),
            dtcRequest('/health', 404, ['trace_id' => 'second']),
        ]);

        $envelope = dtcAnswer();
        $group = dtcGroup('/orders');

        expect($envelope['next'])->toBe([
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'worst'], 'why' => __('firewatch::messages.detect_next_execution')],
            ['tool' => 'occurrences', 'arguments' => ['group' => $group], 'why' => __('firewatch::messages.detect_next_occurrences')],
            ['tool' => 'rank', 'arguments' => ['group' => $group], 'why' => __('firewatch::messages.detect_next_rank')],
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'second'], 'why' => __('firewatch::messages.detect_next_execution')],
        ]);

        foreach ($envelope['next'] as $call) {
            expect(dtcRun($call)['empty'])->toBeNull();
        }
    });

    it('offers at most five calls, the first finding giving three and each other one its latest execution', function () {
        ingest(array_map(fn (int $route) => dtcRequest("/route-{$route}", 500), range(1, 6)));

        $envelope = dtcAnswer();

        expect($envelope['next'])->toHaveCount(5)
            ->and(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution', 'execution']);
    });

    it('offers nothing when nothing was found', function () {
        ingest([dtcRequest('/orders', 200)]);

        expect(dtcAnswer()['next'])->toBe([]);
    });

    it('offers to look at a shape with findings from the all-shapes answer, and the call runs', function () {
        ingest([dtcRequest('/orders', 500)]);

        $envelope = dtcAnswer([]);

        expect($envelope['next'][0])->toBe(['tool' => 'detect', 'arguments' => ['shape' => 'failing-routes'], 'why' => __('firewatch::messages.detect_next_shape')])
            ->and(dtcRun($envelope['next'][0])['result']['verdict'])->toBe('findings');
    });

    it('offers its calls over the instants its window resolved to, so they read the same window when they run later', function () {
        ingest([
            dtcRequest('/orders', 500),
            syntheticRecord(RecordType::LOG)->with(['level' => 'error', 'message' => 'The card was declined.', 'timestamp' => DETECT_AT]),
        ]);

        $routes = dtcAnswer(['shape' => 'failing-routes', 'since' => '-2h']);
        $logs = dtcAnswer(['shape' => 'error-logs', 'since' => '-2h']);
        $shapes = dtcAnswer(['since' => '-2h']);
        $this->travel(3)->hours();
        $answers = array_map(dtcRun(...), [...$routes['next'], ...$logs['next'], ...$shapes['next']]);

        expect(array_column($routes['next'], 'arguments', 'tool'))->toEqual([
            'execution' => ['execution_id' => $routes['result']['findings'][0]['latest_execution_id']],
            'occurrences' => ['group' => dtcGroup('/orders'), 'since' => DETECT_AT - 3600],
            'rank' => ['group' => dtcGroup('/orders'), 'since' => DETECT_AT - 3600],
        ])
            ->and(array_column($logs['next'], 'arguments', 'tool')['occurrences'])->toMatchArray(['type' => 'log', 'since' => DETECT_AT - 3600])
            ->and(array_column($shapes['next'], 'tool'))->toContain('detect')
            ->and(array_column($shapes['next'], 'arguments'))->each->toMatchArray(['since' => DETECT_AT - 3600])
            ->and($answers)->toHaveCount(count($routes['next']) + count($logs['next']) + count($shapes['next']))
            ->and(array_column($answers, 'empty'))->each->toBeNull();
    });
});

test('the tool is listed with its description, arguments and annotations', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tool = collect($listing['tools'])->firstWhere('name', 'detect');

    expect($tool['description'])->toBe(__('firewatch::messages.tools.detect'))
        ->and(array_keys($tool['inputSchema']['properties']))->toBe(['shape', 'threshold', 'group', 'since', 'until', 'limit', 'format'])
        ->and($tool['inputSchema']['properties']['shape']['enum'])->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'cache', 'memory'])
        ->and($tool['annotations'])->toMatchArray(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]);
});

test('the catalogue of shapes is closed, named in kebab case and in the order of the design', function () {
    expect(array_column(DetectorName::cases(), 'value'))->toBe([
        'n-plus-one',
        'database-bound',
        'failing-routes',
        'failing-jobs',
        'queue-latency',
        'failing-tasks',
        'exception-clusters',
        'error-logs',
        'failing-http',
        'cache',
        'memory',
    ]);
});
