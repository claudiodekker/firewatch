<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlFailure;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $writer = new Writer(app(Configuration::class), sqliteVersion: '3.45.1');
    $writer->transaction(fn (SQLite3 $connection) => $connection->exec("INSERT INTO records (type, data) VALUES ('request', '{}')"));
});

function qcStandIn(string $script, float $deadline = ChildRunner::DEADLINE_SECONDS): void
{
    app()->instance(SqlRunner::class, new ChildRunner(app(Configuration::class), deadline: $deadline, script: dirname(__DIR__, 2)."/Fixtures/Sql/{$script}.php"));
}

/**
 * Run the echoing stand-in child, which writes the given lines as its protocol, and get the text of the answer.
 *
 * @param  list<array<string, mixed>|string>  $lines
 * @param  string  $script  the stand-in that writes them, `echo` to end at once or `hang` to wait to be killed
 */
function qcEcho(array $lines, string $script = 'echo', float $deadline = ChildRunner::DEADLINE_SECONDS): string
{
    qcStandIn($script, $deadline);

    $output = implode("\n", array_map(fn (array|string $line) => is_string($line) ? $line : json_encode($line), $lines));
    $response = FirewatchServer::tool(Query::class, ['sql' => $output, 'format' => 'json']);

    return (fn () => $this->content())->call($response)[0];
}

/**
 * Get the state a real child reports for the store, read through the runner alone, without the server's preflight.
 */
function qcChildState(): StoreUnusable
{
    try {
        app(ChildRunner::class)->run('SELECT count(*) FROM records', 1);
    } catch (StoreUnusable $unusable) {
        return $unusable;
    }

    throw new RuntimeException('The child found the store usable.');
}

describe('isolation', function () {
    it('starts the child with none of the application\'s environment variables', function () {
        setEnvironmentVariable('APP_KEY', 'base64:secret');
        setEnvironmentVariable('FIREWATCH_QUERY_SENTINEL', 'leaked');
        qcStandIn('environment');

        $envelope = Envelope::assert(Query::class, ['sql' => 'SELECT 1']);
        $names = array_merge(...$envelope['result']['rows']);

        // macOS gives every process its text encoding, whatever environment it is started with.
        expect(array_diff($names, ['PHPRC', 'PHP_INI_SCAN_DIR', 'SystemRoot', '__CF_USER_TEXT_ENCODING']))->toBe([])
            ->and($names)->not->toContain('APP_KEY', 'FIREWATCH_QUERY_SENTINEL');
    })->group('process');

    it('forces its own ini over a hostile host ini', function () {
        $directory = sys_get_temp_dir().'/firewatch-ini-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("{$directory}/prepend.php", '<?php echo "stray output\n";');
        file_put_contents("{$directory}/php.ini", "precision=3\nserialize_precision=5\nauto_prepend_file={$directory}/prepend.php\n");
        setEnvironmentVariable('PHPRC', $directory);

        $envelope = Envelope::assert(Query::class, ['sql' => 'SELECT 0.1 + 0.2 AS sum']);

        expect($envelope['result']['rows'])->toBe([[0.30000000000000004]]);

        array_map(unlink(...), glob("{$directory}/*"));
        rmdir($directory);
    })->group('process');
});

describe('raw values', function () {
    it('returns values as stored, and what JSON can not hold in words', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => "SELECT 9223372036854775807 AS big, 1e999 AS high, -1e999 AS low, x'00ff10' AS bytes, CAST(x'ff61' AS TEXT) AS broken, NULL AS missing, 1.5 AS fraction"]);

        expect($envelope['result']['rows'])->toBe([[PHP_INT_MAX, 'Infinity', '-Infinity', '<blob 3 bytes>', "\u{FFFD}a", null, 1.5]]);
    })->group('process');

    it('returns text that holds a NUL byte cut at it, as the SQLite driver reads it', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => "SELECT CAST(x'610062' AS TEXT) AS text"]);

        expect($envelope['result']['rows'])->toBe([['a']]);
    })->group('process');
});

describe('the protocol', function () {
    it('trusts nothing but whole protocol lines in their order', function (array $lines) {
        $text = qcEcho($lines);

        expect($text)->toBe(__('firewatch::messages.failed'));

        Exceptions::assertReported(SqlFailure::class);
        Exceptions::assertReportedCount(1);
    })->with([
        'a line that is no JSON' => [['not json']],
        'a line with no kind' => [[['columns' => ['n']]]],
        'a line of an unknown kind' => [[['k' => 'banner']]],
        'a row before the columns' => [[['k' => 'row', 'r' => [1]], ['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 1, 'stop' => 'complete']]],
        'a row of another width' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'row', 'r' => [1, 2]], ['k' => 'end', 'rows' => 1, 'stop' => 'complete']]],
        'the columns twice' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'complete']]],
        'a line after the end' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'complete'], ['k' => 'row', 'r' => [1]]]],
        'an end before the columns' => [[['k' => 'end', 'rows' => 0, 'stop' => 'complete']]],
        'a columns line with no reads' => [[['k' => 'columns', 'columns' => ['n']], ['k' => 'end', 'rows' => 0, 'stop' => 'complete']]],
        'a read that is no table and view' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => [['requests']]], ['k' => 'end', 'rows' => 0, 'stop' => 'complete']]],
        'an unknown stop' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'unknown']]],
        'a state among other lines' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'state', 'state' => 'busy', 'found' => null]]],
        'a state a child never reports' => [[['k' => 'state', 'state' => 'unavailable', 'found' => null]]],
        'an unknown error code' => [[['k' => 'error', 'code' => 'bogus', 'message' => 'out of memory']]],
        'a stop the child never reports' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'deadline']]],
        'an error stop with no message' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'row', 'r' => [1]], ['k' => 'end', 'rows' => 1, 'stop' => 'error']]],
        'an error stop with no rows' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'error', 'message' => 'boom']]],
        'an unknown denial' => [[['k' => 'error', 'code' => 'not_allowed', 'subject' => 'everything', 'name' => null]]],
        'a failure the child reports' => [[['k' => 'error', 'code' => 'failed', 'message' => 'The request is unreadable.']]],
    ])->group('process');

    it('answers a child that ended without a complete result and no row as aborted, with its stderr', function (array $lines) {
        $text = qcEcho($lines);

        expect($text)->toBe(__('firewatch::messages.aborted')."\n".__('firewatch::messages.aborted_stderr', ['stderr' => 'stand-in stderr']));

        Exceptions::assertNothingReported();
    })->with([
        'no end line' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []]]],
        'an end line that counts other rows' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 2, 'stop' => 'complete']]],
    ])->group('process');

    it('returns the rows a child streamed before it ended without a complete result, as partial with stop aborted', function (array $lines) {
        $text = qcEcho($lines);
        $envelope = json_decode($text, associative: true);

        expect($envelope['result']['rows'])->toBe([[1]])
            ->and($envelope['result']['stop'])->toBe('aborted')
            ->and($envelope['summary'])->toBe(__('firewatch::messages.query_summary_partial', ['rows' => '1 row', 'columns' => '1 column']))
            ->and($envelope['truncated'])->toBe([[
                'section' => 'rows',
                'shown' => 1,
                'matched' => null,
                'reason' => 'partial',
                'how' => __('firewatch::messages.query_partial_how'),
            ]])
            ->and($envelope['notes'])->toBe([
                __('firewatch::messages.query_raw_values'),
                __('firewatch::messages.query_partial_note'),
                __('firewatch::messages.aborted_stderr', ['stderr' => 'stand-in stderr']),
            ]);

        Exceptions::assertNothingReported();
    })->with([
        'no end line' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'row', 'r' => [1]]]],
        'an end line that counts other rows' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'row', 'r' => [1]], ['k' => 'end', 'rows' => 2, 'stop' => 'complete']]],
    ])->group('process');

    it('answers a child that died before writing a line as aborted, with its stderr', function () {
        qcStandIn('silent');

        $text = FirewatchServer::tool(Query::class, ['sql' => 'SELECT 1']);

        expect((fn () => $this->content())->call($text)[0])->toBe(__('firewatch::messages.aborted')."\n".__('firewatch::messages.aborted_stderr', ['stderr' => 'stand-in stderr']));
    })->group('process');

    it('kills a child that runs past its deadline before a row, and answers deadline', function () {
        qcStandIn('sleep', deadline: 0.2);

        $text = FirewatchServer::tool(Query::class, ['sql' => 'SELECT 1']);

        expect((fn () => $this->content())->call($text)[0])->toBe(__('firewatch::messages.deadline', ['seconds' => 10]));
    })->group('process');

    it('returns the rows of a child killed at its deadline as partial with stop deadline', function () {
        $text = qcEcho([
            ['k' => 'columns', 'columns' => ['n'], 'reads' => []],
            ['k' => 'row', 'r' => [1]],
            ['k' => 'row', 'r' => [2]],
            '',
        ], 'hang', deadline: 0.5);
        $envelope = json_decode($text, associative: true);

        expect($envelope['result']['rows'])->toBe([[1], [2]])
            ->and($envelope['result']['stop'])->toBe('deadline')
            ->and($envelope['truncated'][0]['reason'])->toBe('partial')
            ->and($envelope['notes'])->toBe([__('firewatch::messages.query_raw_values'), __('firewatch::messages.query_partial_note')]);
    })->group('process');

    it('drops the line a kill cut short instead of failing on it', function () {
        $text = qcEcho([
            ['k' => 'columns', 'columns' => ['n'], 'reads' => []],
            ['k' => 'row', 'r' => [1]],
            '{"k":"row","r":[2',
        ], 'hang', deadline: 0.5);
        $envelope = json_decode($text, associative: true);

        expect($envelope['result']['rows'])->toBe([[1]])
            ->and($envelope['result']['stop'])->toBe('deadline');

        Exceptions::assertNothingReported();
    })->group('process');

    it('kills a child whose output passes 1 MiB, and answers aborted without waiting for the deadline', function () {
        qcStandIn('flood');

        $text = FirewatchServer::tool(Query::class, ['sql' => 'SELECT 1']);

        expect((fn () => $this->content())->call($text)[0])->toBe(__('firewatch::messages.aborted')."\n".__('firewatch::messages.aborted_stderr', ['stderr' => 'stand-in stderr']));
    })->group('process');

    it('takes the record types read from the columns line, so they are known before any row', function () {
        $text = qcEcho([
            ['k' => 'columns', 'columns' => ['n'], 'reads' => [['requests', null], ['users', null]]],
            ['k' => 'row', 'r' => [1]],
            ['k' => 'end', 'rows' => 1, 'stop' => 'complete'],
        ]);

        expect(json_decode($text, associative: true)['coverage']['types_read'])->toBe(['request', 'user']);
    })->group('process');

    it('shows a text cell the child cut with the notice for the characters it left out', function () {
        $text = qcEcho([
            ['k' => 'columns', 'columns' => ['text'], 'reads' => []],
            ['k' => 'row', 'r' => [['text' => 'abc', 'omitted' => 40]]],
            ['k' => 'end', 'rows' => 1, 'stop' => 'complete'],
        ]);
        $envelope = json_decode($text, associative: true);

        expect($envelope['result']['rows'])->toBe([['abc'.__('firewatch::messages.cell_truncated', ['count' => 40])]])
            ->and($envelope['truncated'][0]['reason'])->toBe('cap');
    })->group('process');

    it('answers a store state the child reports alone the way every tool does', function () {
        $text = qcEcho([['k' => 'state', 'state' => 'schema_mismatch', 'found' => 2]]);
        $envelope = json_decode($text, associative: true);

        expect($envelope['empty']['kind'])->toBe('store_unusable')
            ->and($envelope['coverage']['reason'])->toBe('newer_schema');
    })->group('process');

    it('answers an isolation the child could not establish as unavailable', function () {
        $text = qcEcho([['k' => 'error', 'code' => 'unavailable', 'reason' => 'authorizer']]);

        expect($text)->toBe(__('firewatch::messages.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.authorizer')]));
    })->group('process');

    it('answers a runtime error before any row as invalid SQL', function () {
        $text = FirewatchServer::tool(Query::class, ['sql' => "SELECT json_extract('{', '$')"]);

        expect((fn () => $this->content())->call($text)[0])->toBe(__('firewatch::messages.invalid_sql', ['message' => 'malformed JSON']));
    })->group('process');

    it('returns the rows before a runtime error as partial with stop error, and SQLite\'s message as a note', function () {
        $envelope = Envelope::assert(Query::class, ['sql' => "SELECT json_extract(column1, '$') FROM (VALUES ('{}'), ('{'))"]);

        expect($envelope['result']['rows'])->toBe([['{}']])
            ->and($envelope['result']['stop'])->toBe('error')
            ->and($envelope['truncated'][0]['reason'])->toBe('partial')
            ->and($envelope['notes'])->toBe([
                __('firewatch::messages.query_raw_values'),
                __('firewatch::messages.query_partial_note'),
                'malformed JSON',
            ]);
    })->group('process');

    it('cuts the message of an error stop to 300 characters in its note', function () {
        $text = qcEcho([
            ['k' => 'columns', 'columns' => ['n'], 'reads' => []],
            ['k' => 'row', 'r' => [1]],
            ['k' => 'end', 'rows' => 1, 'stop' => 'error', 'message' => str_repeat('m', 301)],
        ]);

        expect(json_decode($text, associative: true)['notes'][2])->toBe(str_repeat('m', 300));
    })->group('process');

    it('answers a heap limit SQLite did not accept as unavailable', function () {
        $text = qcEcho([['k' => 'error', 'code' => 'unavailable', 'reason' => 'heap_limit']]);

        expect($text)->toBe(__('firewatch::messages.unavailable', ['reason' => __('firewatch::messages.sql_unavailable.heap_limit')]));
    })->group('process');

    it('answers memory ran out before the first row as the memory error', function (array $lines) {
        expect(qcEcho($lines))->toBe(__('firewatch::messages.memory', ['mebibytes' => 32]));
    })->with([
        'while compiling' => [[['k' => 'error', 'code' => 'memory', 'message' => 'out of memory']]],
        'at the end line' => [[['k' => 'columns', 'columns' => ['n'], 'reads' => []], ['k' => 'end', 'rows' => 0, 'stop' => 'memory']]],
    ])->group('process');
});

describe('the store as the child finds it', function () {
    it('reports a store that is gone as absent', function () {
        $path = app(Configuration::class)->database;

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($path.$suffix);
        }

        expect(qcChildState())->state->toBe(StoreState::ABSENT);
    })->group('process', 'posix');

    it('reports a store stamped by another application or schema', function (string $stamp, StoreState $state, ?int $found) {
        (new SQLite3(app(Configuration::class)->database))->exec($stamp);

        expect(qcChildState())->state->toBe($state)->found->toBe($found);
    })->with([
        'another application' => ['PRAGMA application_id = 7', StoreState::FOREIGN, null],
        'a newer schema' => ['PRAGMA user_version = '.(Schema::VERSION + 1), StoreState::SCHEMA_MISMATCH, Schema::VERSION + 1],
    ])->group('process');

    it('reports a damaged store as corrupt', function () {
        $path = app(Configuration::class)->database;
        clearstatcache(true, $path);
        $handle = fopen($path, 'r+b');
        fseek($handle, 100);
        fwrite($handle, str_repeat("\xff", filesize($path) - 100));
        fclose($handle);

        expect(qcChildState())->state->toBe(StoreState::CORRUPT);
    })->group('process');

    it('reports a store that stays locked past the busy timeout as busy', function () {
        $connection = new SQLite3(app(Configuration::class)->database);
        $connection->exec('PRAGMA journal_mode = DELETE');
        $connection->exec('BEGIN EXCLUSIVE');

        expect(qcChildState())->state->toBe(StoreState::BUSY);

        $connection->exec('ROLLBACK');
    })->group('process');
});

it('screens the statement again in the child, which is authoritative', function () {
    $request = json_encode([
        'sql' => 'SELECT 1; SELECT 2',
        'limit' => 1,
        'store' => app(Configuration::class)->database,
        'application_id' => Schema::APPLICATION_ID,
        'version' => Schema::VERSION,
    ]);

    $process = new Process([PHP_BINARY, ChildRunner::SCRIPT], input: $request);
    $process->run();

    expect($process->getOutput())->toBe('{"k":"error","code":"not_allowed","subject":"second_statement","name":null}'."\n");
})->group('process');
