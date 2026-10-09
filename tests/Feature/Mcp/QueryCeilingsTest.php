<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
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
