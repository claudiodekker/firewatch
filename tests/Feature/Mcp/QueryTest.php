<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\QueryStop;
use ClaudioDekker\Firewatch\Sql\SqlRows;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\FakeSqlRunner;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const QUERY_AT = 1790776000.0;

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(QUERY_AT + 3600));
});

/**
 * @param  array<string, mixed>  $fields
 */
function qryRequest(string $path, int $duration, float $at = QUERY_AT, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::REQUEST)->with([
        'route_path' => $path,
        'duration' => $duration,
        'timestamp' => $at,
        ...$fields,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function qryAnswer(array $arguments): array
{
    return Envelope::assert(Query::class, $arguments);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function qryText(array $arguments): string
{
    $response = FirewatchServer::tool(Query::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

function qryStandIn(string $script): void
{
    app()->instance(SqlRunner::class, new ChildRunner(app(Configuration::class), script: dirname(__DIR__, 2)."/Fixtures/Sql/{$script}.php"));
}

function qrySpawned(): bool
{
    return is_file(app(Configuration::class)->database.'.spawned');
}

/**
 * Write a file that is not a Firewatch store where the store belongs.
 */
function qryForeignStore(): void
{
    $path = app(Configuration::class)->database;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, str_repeat('not a database ', 100));
}

describe('the rows of a statement', function () {
    it('answers with the rows positional to their columns, as stored', function () {
        ingest([
            qryRequest('/checkout', 1_500_000),
            qryRequest('/checkout', 2_500_000, QUERY_AT + 10),
            qryRequest('/home', 250_000, QUERY_AT + 20),
        ]);

        $envelope = qryAnswer(['sql' => 'SELECT route_path, count(*) AS n, max(duration) AS longest, min(started_at) AS first FROM requests GROUP BY route_path ORDER BY n DESC']);

        expect($envelope['result']['elapsed_ms'])->toBeInt();

        $envelope['result']['elapsed_ms'] = 0;

        expect($envelope)->toBe([
            'tool' => 'query',
            'now' => (int) QUERY_AT + 3600,
            'window' => [
                'windowed' => false,
                'reason' => __('firewatch::messages.query_window_reason'),
            ],
            'summary' => __('firewatch::messages.query_summary', ['rows' => '2 rows', 'columns' => '4 columns']),
            'empty' => null,
            'result' => [
                'columns' => ['route_path', 'n', 'longest', 'first'],
                'rows' => [
                    ['/checkout', 2, 2_500_000, (int) QUERY_AT],
                    ['/home', 1, 250_000, (int) QUERY_AT + 20],
                ],
                'stop' => 'complete',
                'elapsed_ms' => 0,
            ],
            'coverage' => [
                'state' => 'ok',
                'reason' => null,
                'oldest_at' => (int) QUERY_AT,
                'newest_at' => (int) QUERY_AT + 20,
                'records' => 3,
                'types_read' => ['request'],
                'history' => [
                    'from' => (int) QUERY_AT + 3600,
                    'reason' => 'created',
                    'retention' => [
                        'age_seconds' => 3_153_600_000,
                        'records' => 100_000,
                    ],
                ],
                'straddling' => null,
            ],
            'blind_spots' => BlindSpots::for([RecordType::REQUEST]),
            'notes' => [__('firewatch::messages.query_raw_values')],
            'truncated' => [],
            'next' => [],
        ]);
    })->group('process');

    it('prints the rows as one table in markdown, with null as n/a', function () {
        ingest([qryRequest('/home', 1)]);

        $markdown = qryText(['sql' => "SELECT 'a' AS x, NULL AS y, 'b|c' AS x"]);

        expect($markdown)->toContain("### rows\n\n| x | y | x |\n| --- | --- | --- |\n| a | ".__('firewatch::messages.cell_null').' | b\|c |');
    })->group('process');

    it('keeps duplicate column names, and a statement that reads no record type attaches no structural blind spot', function () {
        ingest([qryRequest('/home', 1)]);

        $envelope = qryAnswer(['sql' => 'SELECT 1 AS n, 2 AS n']);

        expect($envelope['result']['columns'])->toBe(['n', 'n'])
            ->and($envelope['result']['rows'])->toBe([[1, 2]])
            ->and($envelope['coverage']['types_read'])->toBe([])
            ->and(array_column($envelope['blind_spots'], 'kind'))->not->toContain('structural');
    })->group('process');

    it('answers no rows as no match, keeping the columns, and never as clean', function () {
        ingest([qryRequest('/home', 1)]);

        $envelope = qryAnswer(['sql' => "SELECT route_path FROM requests WHERE route_path = '/nowhere'"]);

        expect($envelope['empty'])->toBe([
            'kind' => 'no_match',
            'population' => null,
            'message' => __('firewatch::messages.query_no_rows'),
        ])
            ->and($envelope['summary'])->toBe(__('firewatch::messages.query_no_rows'))
            ->and($envelope['result']['columns'])->toBe(['route_path'])
            ->and($envelope['result']['rows'])->toBe([])
            ->and($envelope['coverage']['types_read'])->toBe(['request']);
    })->group('process');
});

describe('the limit', function () {
    beforeEach(function () {
        ingest([qryRequest('/home', 1)]);
    });

    it('answers exactly the limit as complete', function () {
        $envelope = qryAnswer(['sql' => 'VALUES (1), (2), (3)', 'limit' => 3]);

        expect($envelope['result']['stop'])->toBe('complete')
            ->and($envelope['truncated'])->toBe([])
            ->and($envelope['next'])->toBe([]);
    })->group('process');

    it('stops one row beyond the limit, says so, and offers the most rows an answer holds', function () {
        $envelope = qryAnswer(['sql' => 'VALUES (1), (2), (3)', 'limit' => 2]);
        $more = Envelope::assert(Query::class, $envelope['next'][0]['arguments']);

        expect($envelope['result']['rows'])->toBe([[1], [2]])
            ->and($envelope['result']['stop'])->toBe('limit')
            ->and($envelope['summary'])->toBe(__('firewatch::messages.query_summary_more', ['rows' => '2 rows', 'columns' => '1 column', 'shown' => 2]))
            ->and($envelope['truncated'])->toBe([[
                'section' => 'rows',
                'shown' => 2,
                'matched' => null,
                'reason' => 'limit',
                'how' => __('firewatch::messages.query_limit_how'),
            ]])
            ->and($envelope['next'])->toBe([[
                'tool' => 'query',
                'arguments' => [
                    'sql' => 'VALUES (1), (2), (3)',
                    'limit' => 500,
                ],
                'why' => __('firewatch::messages.query_next_more'),
            ]])
            ->and($more['result']['rows'])->toBe([[1], [2], [3]]);
    })->group('process');

    it('offers nothing more at the most rows an answer holds', function () {
        $envelope = qryAnswer(['sql' => 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 501) SELECT n FROM c', 'limit' => 500]);

        expect($envelope['result']['stop'])->toBe('limit')
            ->and($envelope['next'])->toBe([]);
    })->group('process');

    it('returns 50 rows when no limit is asked for', function () {
        $envelope = qryAnswer(['sql' => 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 60) SELECT n FROM c']);

        expect($envelope['result']['rows'])->toHaveCount(50);
    })->group('process');
});

describe('arguments', function () {
    it('refuses a missing or empty statement', function (array $arguments) {
        expect(qryText($arguments))->toBe(__('firewatch::messages.missing_argument', ['argument' => 'sql', 'accepted' => 'one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement', 'example' => 'query(sql: "SELECT type, count(*) FROM records GROUP BY type")']));
    })->with([
        'missing' => [[]],
        'empty' => [['sql' => '']],
    ]);

    it('refuses a statement that is no string', function () {
        expect(qryText(['sql' => 5]))->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'sql', 'expected' => 'a string', 'value' => '5', 'accepted' => 'one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement', 'example' => 'query(sql: "SELECT type, count(*) FROM records GROUP BY type")']));
    });

    it('accepts a limit from 1 to 500 and refuses any other', function (mixed $limit, bool $accepted) {
        qryStandIn('marker');
        ingest([qryRequest('/home', 1)]);

        $text = qryText(['sql' => 'SELECT 1', 'limit' => $limit]);

        expect(str_starts_with($text, 'error: invalid_argument'))->toBe(! $accepted);
    })->with([
        'zero' => [0, false],
        'one' => [1, true],
        'five hundred' => [500, true],
        'five hundred and one' => [501, false],
        'a string' => ['5', false],
    ])->group('process');

    it('words the refusal of a limit with the range and an example', function () {
        expect(qryText(['sql' => 'SELECT 1', 'limit' => 501]))->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'limit', 'expected' => '1 to 500', 'value' => '501', 'accepted' => 'a whole number from 1 to 500', 'example' => 'query(sql: "SELECT * FROM requests", limit: 50)']));
    });

    it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
        $text = qryText(['sql' => 'SELECT 1', $argument => 'now']);

        expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'query', 'accepted' => 'sql, limit, format', 'example' => 'query(format: "json")']));
    })->with([
        'since' => ['since', 'inapplicable_argument'],
        'until' => ['until', 'inapplicable_argument'],
        'a misspelling' => ['sequel', 'unknown_argument'],
    ]);
});

describe('the SQL text', function () {
    it('runs a statement of the most bytes the policy allows', function () {
        ingest([qryRequest('/home', 1)]);
        $sql = 'SELECT 1 AS n'.str_repeat(' ', 16_384 - 13);

        $envelope = qryAnswer(['sql' => $sql]);

        expect(strlen($sql))->toBe(16_384)
            ->and($envelope['result']['rows'])->toBe([[1]]);
    })->group('process');

    it('refuses longer SQL and a NUL byte before a child is spawned', function (string $sql, string $subject) {
        qryStandIn('marker');
        ingest([qryRequest('/home', 1)]);

        $lines = explode("\n", qryText(['sql' => $sql]));

        expect(array_slice($lines, 0, 2))->toBe(['error: not_allowed', __("firewatch::messages.sql_denied.{$subject}", ['bytes' => '16,384'])])
            ->and(qrySpawned())->toBeFalse();
    })->with([
        'one byte too long' => ['SELECT 1'.str_repeat(' ', 16_384 - 7), 'too_long'],
        'a NUL byte' => ["SELECT 'a\0b'", 'nul'],
    ])->group('process');

    it('spawns the child for a statement the screen passes', function () {
        qryStandIn('marker');
        ingest([qryRequest('/home', 1)]);

        qryText(['sql' => 'SELECT 1']);

        expect(qrySpawned())->toBeTrue();
    })->group('process');
});

describe('the second snapshot', function () {
    it('measures the conditions of the types the statement read', function (string $sql, bool $drift) {
        ingest([qryRequest('/home', 1, fields: ['colour' => 'red'])]);

        $envelope = qryAnswer(['sql' => $sql]);

        expect(in_array('drift', array_column($envelope['blind_spots'], 'id'), true))->toBe($drift)
            ->and($envelope['coverage']['records'])->toBe(1);
    })->with([
        'a count of the requests' => ['SELECT count(*) FROM requests', true],
        'a statement that reads no type' => ['SELECT 1', false],
    ])->group('process');

    it('keeps the rows when the store is gone by the time coverage is read', function () {
        ingest([qryRequest('/home', 1)]);
        $path = app(Configuration::class)->database;

        app()->instance(SqlRunner::class, new FakeSqlRunner(function () use ($path) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path.$suffix);
            }

            return new SqlRows(['n'], [[1]], QueryStop::COMPLETE, [RecordType::REQUEST], 3);
        }));

        $envelope = json_decode(qryText(['sql' => 'SELECT count(*) AS n FROM requests', 'format' => 'json']), associative: true);

        expect($envelope['result']['rows'])->toBe([[1]])
            ->and($envelope['empty'])->toBeNull()
            ->and($envelope['coverage']['state'])->toBe('absent')
            ->and($envelope['coverage']['types_read'])->toBe(['request'])
            ->and(array_column($envelope['blind_spots'], 'id'))->toContain('console-requests');
    })->group('posix');
});

describe('the state of the store', function () {
    it('answers that there is no store without spawning a child', function () {
        qryStandIn('marker');

        $envelope = qryAnswer(['sql' => 'SELECT 1']);

        expect($envelope['empty']['kind'])->toBe('no_store')
            ->and($envelope['coverage']['state'])->toBe('absent')
            ->and($envelope['coverage']['types_read'])->toBe([])
            ->and(qrySpawned())->toBeFalse()
            ->and(app(Configuration::class)->database)->not->toBeFile();
    });

    it('answers that the store is unusable without spawning a child', function () {
        qryStandIn('marker');
        qryForeignStore();

        $envelope = qryAnswer(['sql' => 'SELECT 1']);

        expect($envelope['empty']['kind'])->toBe('store_unusable')
            ->and($envelope['coverage']['reason'])->toBe('foreign_file')
            ->and(qrySpawned())->toBeFalse();
    });

    it('answers a store the child found unreadable the way every tool does', function () {
        ingest([qryRequest('/home', 1)]);
        app()->instance(SqlRunner::class, new FakeSqlRunner(fn () => throw new StoreUnusable(StoreState::BUSY)));

        $envelope = qryAnswer(['sql' => 'SELECT 1']);

        expect($envelope['empty'])->toBe([
            'kind' => 'store_unusable',
            'population' => null,
            'message' => __('firewatch::messages.store_unusable.unreadable', ['path' => app(Configuration::class)->database, 'cause' => __('firewatch::messages.store_causes.busy')]),
        ])
            ->and($envelope['coverage']['reason'])->toBe('unreadable')
            ->and($envelope['result'])->toBe([]);
    });
});

test('the tool is listed with its description, arguments and annotations', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tool = collect($listing['tools'])->firstWhere('name', 'query');

    expect($tool['description'])->toBe(__('firewatch::messages.tools.query'))
        ->and(str_word_count($tool['description']))->toBeLessThanOrEqual(150)
        ->and(array_keys($tool['inputSchema']['properties']))->toBe(['sql', 'limit', 'format'])
        ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false])
        ->and(array_map(fn (array $property) => str_word_count($property['description']), $tool['inputSchema']['properties']))->each->toBeLessThanOrEqual(30);
});
