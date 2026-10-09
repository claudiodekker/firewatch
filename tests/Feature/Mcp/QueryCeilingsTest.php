<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

beforeEach(function () {
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
});

/**
 * Get a scalar subquery that holds the given character repeated to the given length.
 */
function qclText(int $length, string $character = 'x'): string
{
    return "(WITH RECURSIVE c(s) AS (SELECT '{$character}' UNION ALL SELECT s || s FROM c WHERE length(s) < {$length}) SELECT substr(s, 1, {$length}) FROM c ORDER BY length(s) DESC LIMIT 1)";
}

describe('the cell cap', function () {
    it('shows 2,000 characters of a longer cell with a notice for the rest, and says to read it with substr()', function (string $character) {
        $envelope = Envelope::assert(Query::class, ['sql' => 'SELECT '.qclText(2500, $character).' AS text, 7 AS n']);

        expect($envelope['result']['rows'])->toBe([[str_repeat($character, 2000).__('firewatch::messages.cell_truncated', ['count' => 500]), 7]])
            ->and($envelope['truncated'])->toBe([[
                'section' => 'rows',
                'shown' => 1,
                'matched' => null,
                'reason' => 'cap',
                'how' => __('firewatch::messages.query_cap_how'),
            ]]);
    })->with(['ascii' => ['x'], 'multibyte' => ['é']])->group('process');

    it('leaves a cell of exactly 2,000 characters whole', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => 'SELECT '.qclText(2000).' AS text']);

        expect($envelope['result']['rows'])->toBe([[str_repeat('x', 2000)]])
            ->and($envelope['truncated'])->toBe([]);
    })->group('process');
});

/**
 * Get a statement that returns the given number of rows of x, the last one of its own length.
 */
function qclRows(int $count, int $length, ?int $lastLength = null, int $columns = 1): string
{
    $last = $lastLength ?? $length;
    $cell = "substr(s, 1, CASE WHEN i = {$count} THEN {$last} ELSE {$length} END)";
    $select = implode(', ', array_fill(0, $columns, $cell));

    return "WITH RECURSIVE c(s) AS (SELECT 'x' UNION ALL SELECT s || s FROM c WHERE length(s) < 2048), n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < {$count}) SELECT {$select} FROM n, c WHERE length(s) = 2048 ORDER BY i";
}

/**
 * Get the text of the tool's answer.
 *
 * @param  array<string, mixed>  $arguments
 */
function qclAnswerText(array $arguments): string
{
    $response = FirewatchServer::tool(Query::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

describe('the row budget', function () {
    it('returns rows that sum to exactly 16,000 bytes, as complete', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => qclRows(8, 1996)]);

        expect($envelope['result']['rows'])->toHaveCount(8)
            ->and($envelope['result']['stop'])->toBe('complete')
            ->and($envelope['truncated'])->toBe([]);
    })->group('process');

    it('stops at the budget when one more byte would not fit', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => qclRows(8, 1996, 1997)]);

        expect($envelope['result']['rows'])->toHaveCount(7)
            ->and($envelope['result']['stop'])->toBe('budget')
            ->and($envelope['summary'])->toBe(__('firewatch::messages.query_summary_more', ['rows' => '7 rows', 'columns' => '1 column', 'shown' => 7]))
            ->and($envelope['truncated'])->toBe([[
                'section' => 'rows',
                'shown' => 7,
                'matched' => null,
                'reason' => 'size',
                'how' => __('firewatch::messages.query_budget_how'),
            ]])
            ->and($envelope['next'])->toBe([]);
    })->group('process');

    it('stops at the budget when a row follows rows that fill it', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => qclRows(9, 1996)]);

        expect($envelope['result']['rows'])->toHaveCount(8)
            ->and($envelope['result']['stop'])->toBe('budget');
    })->group('process');

    it('stops at the limit, whatever the size of the row beyond it', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => qclRows(2, 1, 2000, columns: 9), 'limit' => 1]);

        expect($envelope['result']['rows'])->toHaveCount(1)
            ->and($envelope['result']['stop'])->toBe('limit');
    })->group('process');

    it('answers a first row over the budget as row_too_large', function () {
        expect(qclAnswerText(['sql' => qclRows(1, 2000, columns: 9)]))->toBe(__('firewatch::messages.row_too_large', ['bytes' => '16,000']));
    })->group('process');
});

/**
 * Run the real child with a shorter deadline than the fixed one.
 */
function qclDeadline(float $seconds): void
{
    app()->instance(SqlRunner::class, new ChildRunner(app(Configuration::class), deadline: $seconds));
}

describe('the deadline', function () {
    it('kills a heavy cross join at the deadline, and answers deadline', function () {
        qclDeadline(1.0);

        $sql = 'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 20000) SELECT count(*) FROM c AS a, c AS b, c AS d';

        expect(qclAnswerText(['sql' => $sql]))->toBe(__('firewatch::messages.deadline', ['seconds' => 10]));
    })->group('process');
});
