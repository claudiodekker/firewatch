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

const CCH_AT = 1790776000.0;

const CCH_NOTHING = ['hits' => 0, 'misses' => 0, 'writes' => 0, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 0, 'hit_rate_pct' => null];

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(CCH_AT + 3600));
});

function cchGroup(string $key, string $store = 'array'): string
{
    return md5("{$store},{$key}");
}

/**
 * Build one cache event of the key in the store, of the group of the two.
 *
 * @param  array<string, mixed>  $fields
 */
function cchEvent(string $event, string $key = 'prices', string $store = 'array', array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::CACHE_EVENT)->with([
        '_group' => cchGroup($key, $store),
        'store' => $store,
        'key' => $key,
        'type' => $event,
        'execution_source' => 'request',
        'timestamp' => CCH_AT + 1,
        ...$fields,
    ]);
}

/**
 * Build the cache events of one key, in order.
 *
 * @param  list<string>  $events
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function cchEvents(string $key, array $events, string $store = 'array', array $fields = []): array
{
    return array_map(fn (string $event) => cchEvent($event, $key, $store, $fields), $events);
}

/**
 * Build the reads of one key: so many hits, then so many misses.
 *
 * @param  array<string, mixed>  $fields
 * @return list<RecordBuilder>
 */
function cchReads(string $key, int $hits, int $misses, string $store = 'array', array $fields = []): array
{
    return cchEvents($key, [...array_fill(0, $hits, 'hit'), ...array_fill(0, $misses, 'miss')], $store, $fields);
}

function cchExecution(string $execution): RecordBuilder
{
    return syntheticRecord(RecordType::REQUEST)->inExecution($execution)->with(['timestamp' => CCH_AT + 1]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function cchAnswer(array $arguments = []): array
{
    return Envelope::assert(Detect::class, ['shape' => 'cache', ...$arguments]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function cchRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Detect::class, ['shape' => 'cache', ...$arguments]);

    return (fn () => $this->content())->call($response)[0];
}

describe('the verdict', function () {
    it('has findings when a key was read with a low hit rate, over every cache event examined, and states the threshold', function () {
        ingest([...cchReads('prices', hits: 1, misses: 2), ...cchReads('settings', hits: 3, misses: 1), cchEvent('write', 'settings')]);

        $envelope = cchAnswer();

        expect($envelope['result'])->toMatchArray(['detector' => 'cache', 'verdict' => 'findings', 'reason' => null, 'examined' => 8, 'total' => 1])
            ->and($envelope['result']['threshold'])->toBe(['name' => 'percent', 'value' => 50, 'default' => 50, 'unit' => 'percent', 'range' => ['min' => 1, 'max' => 100], 'is_default' => true])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_findings_summary', ['detector' => 'cache', 'total' => 1, 'examined' => 8, 'input' => __('firewatch::messages.detect_input.cache')]))
            ->and($envelope['result']['findings'][0]['group'])->toBe(cchGroup('prices'));
    });

    it('takes a hit rate strictly below 50 percent over 3 or more reads for low by default', function (int $hits, int $misses, string $verdict) {
        ingest(cchReads('prices', $hits, $misses));

        expect(cchAnswer()['result'])->toMatchArray(['verdict' => $verdict, 'examined' => $hits + $misses]);
    })->with([
        'no hit in 2 reads, under the floor' => [0, 2, 'clean'],
        'no hit in 3 reads, at the floor' => [0, 3, 'findings'],
        '1 hit in 2 reads, under the floor' => [1, 1, 'clean'],
        '1 hit in 3 reads' => [1, 2, 'findings'],
        '2 hits in 4 reads, at the threshold' => [2, 2, 'clean'],
        '2 hits in 5 reads, just under' => [2, 3, 'findings'],
        '3 hits in 5 reads, just over' => [3, 2, 'clean'],
        '49 hits in 99 reads, just under' => [49, 50, 'findings'],
        'only hits' => [3, 0, 'clean'],
    ]);

    it('does not count a write or a delete as a read', function () {
        ingest([...cchReads('prices', hits: 0, misses: 2), ...cchEvents('prices', ['write', 'write', 'delete'])]);

        expect(cchAnswer()['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 5]);
    });

    it('takes the hit rate below which a key is flagged from the call, and says that it is not the default', function (int|float $threshold, int $hits, int $misses, string $verdict) {
        ingest(cchReads('prices', $hits, $misses));

        $result = cchAnswer(['threshold' => $threshold])['result'];

        expect($result)->toMatchArray(['verdict' => $verdict, 'examined' => $hits + $misses])
            ->and($result['threshold'])->toMatchArray(['value' => $threshold, 'default' => 50, 'is_default' => false]);
    })->with([
        'half under a raised threshold' => [60, 2, 2, 'findings'],
        'at a raised threshold' => [60, 3, 2, 'clean'],
        'a third just under a fraction' => [33.4, 1, 2, 'findings'],
        'a third just over a fraction' => [33.3, 1, 2, 'clean'],
        'no hit at the lowest threshold' => [1, 0, 3, 'findings'],
        '1 hit in 100 reads at the lowest threshold' => [1, 1, 99, 'clean'],
        'only hits at the highest threshold' => [100, 3, 0, 'clean'],
        'one miss at the highest threshold' => [100, 99, 1, 'findings'],
    ]);

    it('refuses a threshold that is no number from 1 to 100', function (mixed $threshold) {
        ingest(cchReads('prices', hits: 0, misses: 3));

        $refusal = cchRefusal(['threshold' => $threshold]);

        expect($refusal)->toStartWith('error: invalid_argument')
            ->toContain('argument: threshold')
            ->toContain('a number of 1 to 100')
            ->toContain('detect(shape: "cache", threshold: 50)');
    })->with([
        'zero' => [0],
        'just under the range' => [0.9],
        'just over the range' => [100.1],
        'over the range' => [101],
        'text' => ['50'],
        'a boolean' => [true],
    ]);

    it('has findings when a write or a delete of a key failed, whatever its reads', function (array $events) {
        ingest(cchEvents('prices', $events));

        expect(cchAnswer()['result'])->toMatchArray(['verdict' => 'findings', 'examined' => count($events), 'total' => 1]);
    })->with([
        'one failed write and no read' => [['write-failure']],
        'one failed delete and no read' => [['delete-failure']],
        'a failed write among hits' => [['hit', 'hit', 'hit', 'write-failure']],
        'a failed delete under the floor of reads' => [['miss', 'delete-failure']],
    ]);

    it('is clean over the cache events examined when no key is read badly and nothing failed, and says how many', function () {
        ingest([...cchReads('prices', hits: 2, misses: 2), ...cchEvents('settings', ['miss', 'write', 'hit', 'delete'])]);

        $envelope = cchAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'reason' => null, 'examined' => 8, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_clean_summary', ['detector' => 'cache', 'examined' => 8, 'input' => __('firewatch::messages.detect_input.cache')]));
    });

    it('is not evaluated when the window holds no cache event, never clean', function () {
        ingest([syntheticRecord(RecordType::REQUEST), syntheticRecord(RecordType::COMMAND)]);

        $envelope = cchAnswer();

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0, 'total' => 0, 'findings' => []])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.detect_not_evaluated_summary', ['detector' => 'cache', 'reason' => 'no_records']));
    });

    it('judges the cache events that started in the window, the start included and the end not', function () {
        ingest([
            cchEvent('write-failure', fields: ['timestamp' => CCH_AT - 1]),
            cchEvent('hit', fields: ['timestamp' => CCH_AT]),
            cchEvent('write-failure', fields: ['timestamp' => CCH_AT + 10]),
        ]);

        $envelope = cchAnswer(['since' => (string) CCH_AT, 'until' => (string) (CCH_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'clean', 'examined' => 1])
            ->and($envelope['result']['saw']['activity']['total'])->toBe([...CCH_NOTHING, 'hits' => 1, 'hit_rate_pct' => 100]);
    });

    it('finds a write that failed at the start of the window, and not one that failed at its end', function () {
        ingest([
            cchEvent('write-failure', fields: ['timestamp' => CCH_AT, 'execution_id' => 'at-since']),
            cchEvent('write-failure', 'settings', fields: ['timestamp' => CCH_AT + 10, 'execution_id' => 'at-until']),
        ]);

        $envelope = cchAnswer(['since' => (string) CCH_AT, 'until' => (string) (CCH_AT + 10)]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 1, 'total' => 1])
            ->and($envelope['result']['findings'][0]['latest_execution_id'])->toBe('at-since');
    });

    it('counts a cache event of a kind it does not know as examined, and nowhere else', function () {
        ingest([...cchReads('prices', hits: 2, misses: 0), cchEvent('flush'), cchEvent('flush', 'settings')->without('type')]);

        $result = cchAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 4])
            ->and($result['saw']['activity']['total'])->toBe([...CCH_NOTHING, 'hits' => 2, 'hit_rate_pct' => 100]);
    });
});

describe('the finding', function () {
    it('states a key read with a low hit rate by its reads, and takes when it was seen, its latest execution and whom it reached from its misses', function () {
        ingest([
            cchEvent('hit', fields: ['timestamp' => CCH_AT, 'execution_id' => 'a', 'user' => '3']),
            cchEvent('miss', fields: ['timestamp' => CCH_AT + 60, 'execution_id' => 'b', 'user' => '7']),
            cchEvent('write', fields: ['timestamp' => CCH_AT + 61, 'execution_id' => 'b', 'user' => '7']),
            cchEvent('miss', fields: ['timestamp' => CCH_AT + 120, 'execution_id' => 'c', 'user' => '7']),
            cchEvent('miss', fields: ['timestamp' => CCH_AT + 180, 'execution_id' => 'd']),
            cchEvent('hit', fields: ['timestamp' => CCH_AT + 240, 'execution_id' => 'e', 'user' => '9']),
            cchEvent('delete', fields: ['timestamp' => CCH_AT + 300, 'execution_id' => 'f', 'user' => '11']),
        ]);

        $envelope = cchAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => cchGroup('prices'),
            'name' => 'prices',
            'count' => 5,
            'first_seen_at' => CCH_AT + 60,
            'last_seen_at' => CCH_AT + 180,
            'latest_execution_id' => 'd',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'reasons' => ['low_hit_rate'],
                'store' => 'array',
                'key' => 'prices',
                'hits' => 2,
                'misses' => 3,
                'writes' => 1,
                'write_failures' => 0,
                'deletes' => 1,
                'delete_failures' => 0,
                'hit_rate_pct' => 40.0,
            ],
        ]])->and($envelope['result']['findings'][0])->not->toHaveKey('worst_execution_id');
    });

    it('states a key with a failed write or delete by its failures, and takes when it was seen, its latest execution and whom it reached from them', function () {
        ingest([
            cchEvent('miss', fields: ['timestamp' => CCH_AT, 'execution_id' => 'a', 'user' => '3']),
            cchEvent('write-failure', fields: ['timestamp' => CCH_AT + 60, 'execution_id' => 'b', 'user' => '7']),
            cchEvent('miss', fields: ['timestamp' => CCH_AT + 120, 'execution_id' => 'c', 'user' => '5']),
            cchEvent('delete-failure', fields: ['timestamp' => CCH_AT + 180, 'execution_id' => 'd']),
            cchEvent('write-failure', fields: ['timestamp' => CCH_AT + 240, 'execution_id' => 'e', 'user' => '7']),
            cchEvent('miss', fields: ['timestamp' => CCH_AT + 300, 'execution_id' => 'f', 'user' => '9']),
        ]);

        $envelope = cchAnswer();

        expect($envelope['result']['findings'])->toEqual([[
            'group' => cchGroup('prices'),
            'name' => 'prices',
            'count' => 3,
            'first_seen_at' => CCH_AT + 60,
            'last_seen_at' => CCH_AT + 240,
            'latest_execution_id' => 'e',
            'reaches' => ['signed_in_actors' => 1, 'without_actor' => 1],
            'evidence' => [
                'reasons' => ['low_hit_rate', 'write_failing', 'delete_failing'],
                'store' => 'array',
                'key' => 'prices',
                'hits' => 0,
                'misses' => 3,
                'writes' => 0,
                'write_failures' => 2,
                'deletes' => 0,
                'delete_failures' => 1,
                'hit_rate_pct' => 0.0,
            ],
        ]]);
    });

    it('gives each reason a key qualifies by, in a fixed order, and counts the failures when it has any and the reads when not', function (array $events, array $reasons, int $count) {
        ingest(cchEvents('prices', $events));

        $finding = cchAnswer()['result']['findings'][0];

        expect($finding['evidence']['reasons'])->toBe($reasons)
            ->and($finding['count'])->toBe($count);
    })->with([
        'a low hit rate' => [['miss', 'miss', 'miss', 'hit'], ['low_hit_rate'], 4],
        'a failed write' => [['hit', 'write-failure'], ['write_failing'], 1],
        'a failed delete' => [['delete-failure', 'delete-failure'], ['delete_failing'], 2],
        'a failed delete and a failed write' => [['delete-failure', 'write-failure', 'hit', 'hit', 'hit'], ['write_failing', 'delete_failing'], 2],
        'a failed delete and a low hit rate' => [['delete-failure', 'miss', 'miss', 'miss'], ['low_hit_rate', 'delete_failing'], 1],
        'a failed write under the floor of reads' => [['write-failure', 'miss', 'miss'], ['write_failing'], 1],
    ]);

    it('names a low hit rate by the threshold of the call', function () {
        ingest([...cchReads('prices', hits: 2, misses: 2), cchEvent('write-failure')]);

        $reasons = fn (array $arguments) => cchAnswer($arguments)['result']['findings'][0]['evidence']['reasons'];

        expect($reasons([]))->toBe(['write_failing'])
            ->and($reasons(['threshold' => 51]))->toBe(['low_hit_rate', 'write_failing']);
    });

    it('states no hit rate for a key that was never read, never 0', function () {
        ingest(cchEvents('prices', ['write', 'write-failure']));

        expect(cchAnswer()['result']['findings'][0]['evidence'])->toMatchArray(['hits' => 0, 'misses' => 0, 'writes' => 1, 'write_failures' => 1, 'hit_rate_pct' => null]);
    });

    it('rounds the hit rate to one decimal', function () {
        ingest(cchReads('prices', hits: 1, misses: 2));

        expect(cchAnswer()['result']['findings'][0]['evidence']['hit_rate_pct'])->toBe(33.3);
    });

    it('reports the same key in two stores as two findings with one name, told apart by the store', function () {
        ingest([...cchReads('prices', hits: 0, misses: 3, store: 'redis'), ...cchReads('prices', hits: 1, misses: 3, store: 'array')]);

        $findings = cchAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['prices', 'prices'])
            ->and(array_column($findings, 'group'))->toBe([cchGroup('prices', 'redis'), cchGroup('prices', 'array')])
            ->and(array_column(array_column($findings, 'evidence'), 'store'))->toBe(['redis', 'array']);
    });

    it('keeps a store the wire named with nothing as it is, and states none for a store the wire left out', function () {
        ingest([
            cchEvent('write-failure', 'void', ''),
            cchEvent('write-failure', 'lost', 'gone', ['timestamp' => CCH_AT + 5])->without('store'),
        ]);

        $result = cchAnswer()['result'];

        expect(array_column(array_column($result['findings'], 'evidence'), 'store', 'key'))->toBe(['lost' => null, 'void' => ''])
            ->and(array_column($result['saw']['activity']['stores'], 'store'))->toBe([null, '']);
    });

    it('keeps the cache events the wire sent without a group or a key as one finding', function () {
        ingest([
            cchEvent('write-failure')->without('_group', 'key'),
            cchEvent('delete-failure')->without('_group', 'key'),
            cchEvent('write-failure'),
        ]);

        $findings = cchAnswer()['result']['findings'];

        expect(array_column($findings, 'group'))->toBe([null, cchGroup('prices')])
            ->and($findings[0])->toMatchArray(['name' => '', 'count' => 2])
            ->and($findings[0]['evidence'])->toMatchArray(['reasons' => ['write_failing', 'delete_failing'], 'key' => null, 'write_failures' => 1, 'delete_failures' => 1])
            ->and($findings[1]['evidence'])->toMatchArray(['key' => 'prices', 'write_failures' => 1, 'delete_failures' => 0]);
    });

    it('points at the latest record of the finding, and at the one stored last of two that started together', function (array $events, string $latest) {
        ingest(array_map(fn (array $event) => cchEvent($event[0], fields: ['execution_id' => $event[1], 'timestamp' => CCH_AT + $event[2]]), $events));

        $finding = cchAnswer()['result']['findings'][0];

        expect($finding)->toMatchArray(['latest_execution_id' => $latest, 'first_seen_at' => CCH_AT + 5, 'last_seen_at' => CCH_AT + 9]);
    })->with([
        'of the misses of a low hit rate' => [[['miss', 'a', 5], ['miss', 'b', 9], ['miss', 'c', 9], ['miss', 'd', 7], ['hit', 'e', 20], ['write', 'f', 30]], 'c'],
        'of the failures' => [[['write-failure', 'a', 5], ['delete-failure', 'b', 9], ['write-failure', 'c', 9], ['delete-failure', 'd', 7], ['miss', 'e', 20], ['hit', 'f', 30]], 'c'],
    ]);

    it('points at no execution when the latest record of the finding carries none', function () {
        ingest([
            cchEvent('write-failure', fields: ['execution_id' => 'a', 'timestamp' => CCH_AT + 5]),
            cchEvent('write-failure', fields: ['execution_id' => '', 'timestamp' => CCH_AT + 9]),
        ]);

        expect(cchAnswer()['result']['findings'][0]['latest_execution_id'])->toBeNull();
    });

    it('counts the signed-in users the records of the finding reached, and those without one', function (string $counted, string $other, array $reaches) {
        ingest([
            cchEvent($counted, fields: ['user' => '7']),
            cchEvent($counted, fields: ['user' => '7']),
            cchEvent($counted, fields: ['user' => '9']),
            cchEvent($counted),
            cchEvent($other, fields: ['user' => '11']),
            cchEvent($other),
        ]);

        expect(cchAnswer()['result']['findings'][0]['reaches'])->toBe($reaches);
    })->with([
        'the misses of a low hit rate, and not its hits' => ['miss', 'hit', ['signed_in_actors' => 2, 'without_actor' => 1]],
        'the failures, and not the misses' => ['delete-failure', 'miss', ['signed_in_actors' => 2, 'without_actor' => 1]],
    ]);
});

describe('the order', function () {
    it('lists the key with the most failed writes and deletes first, whatever its hit rate', function () {
        ingest([
            ...cchEvents('once', ['write-failure', 'miss', 'miss', 'miss']),
            ...cchEvents('thrice', ['write-failure', 'delete-failure', 'delete-failure', 'hit', 'hit', 'hit']),
            ...cchEvents('twice', ['delete-failure', 'delete-failure']),
            ...cchReads('never-hit', hits: 0, misses: 9),
        ]);

        expect(array_column(cchAnswer()['result']['findings'], 'name'))->toBe(['thrice', 'twice', 'once', 'never-hit']);
    });

    it('lists keys with as many failures by the lowest hit rate, and one that was never read last', function () {
        ingest([
            ...cchEvents('unread', ['write-failure']),
            ...cchEvents('half', ['write-failure', 'hit', 'miss']),
            ...cchEvents('none', ['write-failure', 'miss']),
            ...cchEvents('all', ['write-failure', 'hit']),
            ...cchReads('third', hits: 1, misses: 2),
            ...cchReads('quarter', hits: 1, misses: 3),
        ]);

        expect(array_column(cchAnswer()['result']['findings'], 'name'))->toBe(['none', 'half', 'all', 'unread', 'quarter', 'third']);
    });

    it('orders by the hit rate before it is rounded', function () {
        ingest([
            ...cchReads('higher', hits: 1, misses: 44, fields: ['_group' => str_repeat('0', 32)]),
            ...cchReads('lower', hits: 1, misses: 45, fields: ['_group' => str_repeat('f', 32)]),
        ]);

        $findings = cchAnswer()['result']['findings'];

        expect(array_column($findings, 'name'))->toBe(['lower', 'higher'])
            ->and(array_column(array_column($findings, 'evidence'), 'hit_rate_pct'))->toBe([2.2, 2.2]);
    });

    it('lists keys that tie by their group hash', function () {
        ingest([cchEvent('write-failure', 'tied-a'), cchEvent('write-failure', 'tied-b'), cchEvent('write-failure', 'tied-c')]);

        $tied = [cchGroup('tied-a'), cchGroup('tied-b'), cchGroup('tied-c')];
        sort($tied, SORT_STRING);

        expect(array_column(cchAnswer()['result']['findings'], 'group'))->toBe($tied);
    });

    it('shows the findings up to the limit, with the exact total and a note of the cut', function () {
        ingest(array_merge(...array_map(fn (int $key) => cchEvents("key-{$key}", array_fill(0, $key, 'write-failure')), range(1, 4))));

        $envelope = cchAnswer(['limit' => 3]);

        expect(array_column($envelope['result']['findings'], 'name'))->toBe(['key-4', 'key-3', 'key-2'])
            ->and($envelope['result'])->toMatchArray(['examined' => 10, 'total' => 4])
            ->and($envelope['truncated'])->toBe([['section' => 'findings', 'shown' => 3, 'matched' => 4, 'reason' => 'limit', 'how' => __('firewatch::messages.detect_findings_how')]]);
    });

    it('is complete when exactly the limit is shown', function () {
        ingest(array_merge(...array_map(fn (int $key) => cchEvents("key-{$key}", ['write-failure']), range(1, 3))));

        $envelope = cchAnswer(['limit' => 3]);

        expect($envelope['result']['findings'])->toHaveCount(3)->and($envelope['truncated'])->toBe([]);
    });
});

describe('the activity', function () {
    it('counts what the cache events say of each store, by store name, and in total', function () {
        ingest([
            ...cchEvents('prices', ['miss', 'write', 'hit', 'hit'], 'redis'),
            ...cchEvents('session', ['write-failure', 'write-failure', 'delete'], 'redis'),
            ...cchEvents('prices', ['miss', 'miss', 'hit', 'delete-failure'], 'array'),
            ...cchEvents('lock', ['write', 'delete'], 'file'),
        ]);

        expect(cchAnswer()['result']['saw'])->toBe(['activity' => [
            'stores' => [
                ['store' => 'array', 'hits' => 1, 'misses' => 2, 'writes' => 0, 'write_failures' => 0, 'deletes' => 0, 'delete_failures' => 1, 'hit_rate_pct' => 33.3],
                ['store' => 'file', 'hits' => 0, 'misses' => 0, 'writes' => 1, 'write_failures' => 0, 'deletes' => 1, 'delete_failures' => 0, 'hit_rate_pct' => null],
                ['store' => 'redis', 'hits' => 2, 'misses' => 1, 'writes' => 1, 'write_failures' => 2, 'deletes' => 1, 'delete_failures' => 0, 'hit_rate_pct' => 66.7],
            ],
            'total' => ['hits' => 3, 'misses' => 3, 'writes' => 2, 'write_failures' => 2, 'deletes' => 2, 'delete_failures' => 1, 'hit_rate_pct' => 50],
        ]]);
    });

    it('shows a cache that works by its activity when nothing was found', function () {
        ingest(cchReads('prices', hits: 3, misses: 1));

        $result = cchAnswer()['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'findings' => []])
            ->and($result['saw']['activity'])->toBe([
                'stores' => [['store' => 'array', ...CCH_NOTHING, 'hits' => 3, 'misses' => 1, 'hit_rate_pct' => 75]],
                'total' => [...CCH_NOTHING, 'hits' => 3, 'misses' => 1, 'hit_rate_pct' => 75],
            ]);
    });

    it('states that nothing touched the cache when nothing was examined, with no store and no hit rate', function () {
        ingest([syntheticRecord(RecordType::REQUEST)]);

        $result = cchAnswer()['result'];

        expect($result['verdict'])->toBe('not_evaluated')
            ->and($result['saw'])->toBe(['activity' => ['stores' => [], 'total' => CCH_NOTHING]]);
    });
});

describe('one group', function () {
    it('restricts the judgement and the activity to the key, and examines only its cache events', function () {
        ingest([...cchReads('prices', hits: 1, misses: 3), ...cchEvents('session', ['write-failure', 'write-failure'], 'redis')]);

        $envelope = cchAnswer(['group' => cchGroup('prices')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'findings', 'examined' => 4, 'total' => 1])
            ->and(array_column($envelope['result']['findings'], 'name'))->toBe(['prices'])
            ->and($envelope['result']['saw']['activity'])->toBe([
                'stores' => [['store' => 'array', ...CCH_NOTHING, 'hits' => 1, 'misses' => 3, 'hit_rate_pct' => 25]],
                'total' => [...CCH_NOTHING, 'hits' => 1, 'misses' => 3, 'hit_rate_pct' => 25],
            ]);
    });

    it('is clean when the key has cache events and none qualifies it, and shows its counters in the activity', function () {
        ingest([...cchEvents('prices', ['miss', 'write', 'hit']), ...cchEvents('session', ['write-failure'], 'redis')]);

        $result = cchAnswer(['group' => cchGroup('prices')])['result'];

        expect($result)->toMatchArray(['verdict' => 'clean', 'examined' => 3, 'total' => 0])
            ->and($result['saw']['activity']['stores'])->toBe([['store' => 'array', ...CCH_NOTHING, 'hits' => 1, 'misses' => 1, 'writes' => 1, 'hit_rate_pct' => 50]]);
    });

    it('answers that no cache event matches a group that holds none, with an activity of nothing', function () {
        ingest([...cchEvents('prices', ['write-failure']), ...cchReads('settings', hits: 3, misses: 0)]);

        $envelope = cchAnswer(['group' => cchGroup('missing')]);

        expect($envelope['result'])->toMatchArray(['verdict' => 'not_evaluated', 'reason' => 'no_records', 'examined' => 0])
            ->and($envelope['result']['saw'])->toBe(['activity' => ['stores' => [], 'total' => CCH_NOTHING]])
            ->and($envelope['empty'])->toMatchArray(['kind' => 'no_match', 'population' => 4]);
    });
});

describe('the caveats', function () {
    it('says that keys that embed ids fragment into groups of one, whatever the verdict', function (array $events, array $arguments, string $verdict) {
        ingest([...cchEvents('prices', $events), syntheticRecord(RecordType::QUERY)]);

        $result = cchAnswer($arguments)['result'];

        expect($result['verdict'])->toBe($verdict)
            ->and($result['caveats'])->toBe([__('firewatch::messages.detect_caveat_cache_keys')]);
    })->with([
        'with a failed write' => [['write-failure'], [], 'findings'],
        'when clean' => [['hit'], [], 'clean'],
        'with nothing examined' => [[], [], 'not_evaluated'],
        'for a group that holds none' => [['write-failure'], ['group' => md5('missing')], 'not_evaluated'],
    ]);
});

describe('the blind spots', function () {
    it('states that the keys of the framework and its packages are not recorded, also when nothing was examined', function (array $events) {
        ingest([...cchEvents('prices', $events), syntheticRecord(RecordType::QUERY)]);

        $blindSpots = array_column(cchAnswer()['blind_spots'], 'message', 'id');

        expect($blindSpots)->toHaveKeys(['vendor-defaults-unrecorded', 'actor-partial'])
            ->and($blindSpots['vendor-defaults-unrecorded'])->toBe(__('firewatch::messages.blind_spots.vendor-defaults-unrecorded'))
            ->and($blindSpots['actor-partial'])->toBe(__('firewatch::messages.blind_spots.actor-partial'));
    })->with([
        'with findings' => [['write-failure']],
        'when clean' => [['hit']],
        'with nothing examined' => [[]],
    ]);
});

describe('what to look at next', function () {
    it('follows the worst finding to the execution of its latest record, its records and its group, then the next finding, and the calls run', function () {
        ingest([
            cchExecution('a'),
            cchExecution('b'),
            cchExecution('c'),
            cchEvent('write-failure', 'session', fields: ['execution_id' => 'a', 'timestamp' => CCH_AT + 5]),
            cchEvent('write-failure', 'session', fields: ['execution_id' => 'b', 'timestamp' => CCH_AT + 10]),
            ...cchReads('prices', hits: 0, misses: 3, fields: ['execution_id' => 'c']),
            cchEvent('hit', 'prices', fields: ['execution_id' => 'a', 'timestamp' => CCH_AT + 50]),
        ]);

        $envelope = cchAnswer();
        $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class, 'rank' => Rank::class];

        expect(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'rank', 'execution'])
            ->and(array_column($envelope['next'], 'arguments'))->toBe([['execution_id' => 'b'], ['group' => cchGroup('session')], ['group' => cchGroup('session')], ['execution_id' => 'c']]);

        foreach ($envelope['next'] as $call) {
            expect(Envelope::assert($tools[$call['tool']], $call['arguments'])['empty'])->toBeNull();
        }
    });
});

describe('with the other shapes', function () {
    it('is a row of the overview, with its worst finding and without its activity', function () {
        ingest([
            ...cchReads('prices', hits: 0, misses: 3),
            ...cchEvents('session', ['write-failure'], 'redis'),
            ...cchReads('settings', hits: 3, misses: 0),
        ]);

        $rows = Envelope::assert(Overview::class)['result']['detectors'];
        $row = collect($rows)->firstWhere('detector', 'cache');

        expect($row)->toBe([
            'detector' => 'cache',
            'verdict' => 'findings',
            'reason' => null,
            'examined' => 7,
            'total' => 2,
            'worst' => ['name' => 'session', 'group' => cchGroup('session', 'redis')],
        ]);
    });

    it('is run between failing-http and memory when no shape is named', function () {
        ingest([cchEvent('write-failure')]);

        $detectors = Envelope::assert(Detect::class)['result']['detectors'];

        expect(array_column($detectors, 'detector'))->toBe(['n-plus-one', 'database-bound', 'failing-routes', 'failing-jobs', 'queue-latency', 'failing-tasks', 'exception-clusters', 'error-logs', 'failing-http', 'cache', 'memory'])
            ->and($detectors[9])->toMatchArray(['verdict' => 'findings', 'total' => 1]);
    });
});
