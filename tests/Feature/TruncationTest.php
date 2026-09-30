<?php

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * @return list<array<string, mixed>>
 */
function storedRows(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

function truncationMarker(int $bytes): string
{
    return "... [truncated, {$bytes} bytes total]";
}

function queryOfLength(int $bytes, string $fill = 'a'): string
{
    $literal = str_repeat($fill, intdiv($bytes - 9, strlen($fill)));

    return "select '{$literal}'";
}

/**
 * @param  list<array<string, mixed>>  $frames
 */
function traceOf(array $frames): string
{
    return json_encode($frames, RecordMapper::JSON_FLAGS);
}

/**
 * @return array<string, mixed>
 */
function frameWithCode(int $bytes): array
{
    return ['file' => 'app/Models/Order.php:12', 'source' => 'App\Models\Order->ship', 'code' => ['12' => str_repeat('x', $bytes)]];
}

it('keeps a string of up to 65,535 bytes and cuts a longer one to 65,535 bytes with the truncation marker', function (int $bytes, bool $cut) {
    $sql = queryOfLength($bytes);

    DB::select($sql);
    Nightwatch::digest();

    [$query] = storedRows("SELECT sql FROM queries WHERE sql LIKE 'select ''%'");
    $marker = truncationMarker($bytes);

    expect(strlen($sql))->toBe($bytes)
        ->and($query['sql'])->toBe($cut ? substr($sql, 0, 65_535 - strlen($marker)).$marker : $sql);
})->with([
    'exactly the limit' => ['bytes' => 65_535, 'cut' => false],
    'one byte over' => ['bytes' => 65_536, 'cut' => true],
]);

it('cuts a string on a UTF-8 character boundary', function () {
    // Two-byte characters from an even offset put the odd limit inside a character.
    $sql = "select '".str_repeat('é', 35_000)."'";
    $marker = truncationMarker(strlen($sql));

    DB::select($sql);
    Nightwatch::digest();

    [$query] = storedRows("SELECT sql FROM queries WHERE sql LIKE 'select ''%'");

    expect(strlen($query['sql']))->toBe(65_534)
        ->and(mb_check_encoding($query['sql'], 'UTF-8'))->toBeTrue()
        ->and($query['sql'])->toBe(substr($sql, 0, 65_534 - strlen($marker)).$marker);
});

it('cuts a long value of a common column', function () {
    $deploy = str_repeat('d', 70_000);
    $marker = truncationMarker(70_000);

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->deploy($deploy)]);

    expect(storedRows('SELECT deploy FROM records'))->toBe([['deploy' => substr($deploy, 0, 65_535 - strlen($marker)).$marker]]);
});

it('keeps a JSON string Nightwatch cut mid-value whole, even when its cut split a character', function () {
    // Two-byte characters from an even offset put Nightwatch's odd 65,535-byte cut inside a character.
    $context = ['order' => str_repeat('é', 40_000)];
    $json = json_encode($context, JSON_UNESCAPED_UNICODE);

    Log::channel('nightwatch')->info('The order is large.', $context);
    Nightwatch::digest();

    [$log] = storedRows('SELECT context FROM logs');

    expect($log['context'])->toBe(substr($json, 0, 65_534)."\u{FFFD}");
});

it('keeps an exception trace whole when Nightwatch cut it mid-value', function () {
    $trace = '[{"file":"'.str_repeat('f', 70_000);

    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['trace' => $trace])]);

    expect(storedRows("SELECT data ->> '$.trace' AS trace FROM records"))->toBe([['trace' => $trace]]);
});

it('marks a value Nightwatch cut inside a character, whose replacement grew it past the limit', function () {
    Log::channel('nightwatch')->info(str_repeat('a', 65_534).'é');
    Nightwatch::digest();

    [$log] = storedRows('SELECT message FROM logs');
    $marker = truncationMarker(65_537);

    expect($log['message'])->toBe(str_repeat('a', 65_535 - strlen($marker)).$marker);
});

it('keeps a field with a numeric name under its name', function () {
    $record = syntheticRecord(RecordType::CACHE_EVENT)->make();
    $record['5'] = 'five';

    ingest([$record]);

    expect(storedRows("SELECT data ->> '$.\"5\"' AS five, data ->> '$.\"0\"' AS zero FROM records"))->toBe([['five' => 'five', 'zero' => null]]);
});

it('cuts the largest strings of data over 1 MiB to 4,096 bytes until it fits', function () {
    $message = str_repeat('m', 70_000);
    $class = str_repeat('c', 60_000);
    $file = str_repeat('f', 50_000);
    $trace = traceOf([frameWithCode(950_000)]);

    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['message' => $message, 'class' => $class, 'file' => $file, 'trace' => $trace])]);

    [$exception] = storedRows('SELECT message, class, file, json(trace) AS trace, length(CAST(data AS BLOB)) AS bytes FROM exceptions');

    expect($exception['message'])->toBe(substr($message, 0, 4_096 - strlen(truncationMarker(70_000))).truncationMarker(70_000))
        ->and($exception['class'])->toBe(substr($class, 0, 4_096 - strlen(truncationMarker(60_000))).truncationMarker(60_000))
        ->and($exception['file'])->toBe($file)
        ->and($exception['trace'])->toBe($trace)
        ->and($exception['bytes'])->toBeLessThanOrEqual(1_048_576);
});

it('nulls the trace\'s code snippets when cutting strings leaves data over 1 MiB', function () {
    $message = str_repeat('m', 70_000);
    $trace = traceOf([frameWithCode(600_000), frameWithCode(600_000)]);
    $frame = ['file' => 'app/Models/Order.php:12', 'source' => 'App\Models\Order->ship', 'code' => null];

    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['message' => $message, 'trace' => $trace])]);

    [$exception] = storedRows('SELECT message, json(trace) AS trace, length(CAST(data AS BLOB)) AS bytes FROM exceptions');

    expect($exception['message'])->toBe(substr($message, 0, 4_096 - strlen(truncationMarker(70_000))).truncationMarker(70_000))
        ->and($exception['trace'])->toBe(traceOf([$frame, $frame]))
        ->and($exception['bytes'])->toBeLessThanOrEqual(1_048_576);
});

it('keeps a record whose data still exceeds 1 MiB after every cut', function () {
    $trace = traceOf([['file' => str_repeat('f', 1_100_000), 'source' => '', 'code' => null]]);

    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['trace' => $trace])]);

    expect(storedRows('SELECT json(trace) AS trace FROM exceptions'))->toBe([['trace' => $trace]]);
});

it('keeps the code of a field named trace on a record that is not an exception', function () {
    $trace = [['file' => 'app/Models/Order.php:12', 'code' => ['12' => str_repeat('x', 1_100_000)]]];

    ingest([syntheticRecord(RecordType::CACHE_EVENT)->with(['trace' => $trace])]);

    expect(storedRows("SELECT length(data ->> '$.trace[0].code.\"12\"') AS code FROM records"))->toBe([['code' => 1_100_000]]);
});

it('keeps data of up to 1 MiB and cuts data one byte over', function (int $over, bool $cut) {
    // A probe record measures the data around the padding, which grows it byte for byte.
    $message = str_repeat('m', 5_000);
    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['message' => $message, 'trace' => traceOf([frameWithCode(1)])])]);
    [$probe] = storedRows('SELECT length(CAST(data AS BLOB)) AS bytes FROM records');
    $padding = 1_048_576 - $probe['bytes'] + 1 + $over;

    ingest([syntheticRecord(RecordType::EXCEPTION)->with(['message' => $message, 'trace' => traceOf([frameWithCode($padding)])])]);

    [$exception] = storedRows('SELECT message, length(CAST(data AS BLOB)) AS bytes FROM exceptions ORDER BY id DESC LIMIT 1');

    expect($exception['message'])->toBe($cut ? substr($message, 0, 4_096 - strlen(truncationMarker(5_000))).truncationMarker(5_000) : $message)
        ->and($exception['bytes'])->toBe($cut ? 1_048_577 - 5_000 + 4_096 : 1_048_576);
})->with([
    'exactly 1 MiB' => ['over' => 0, 'cut' => false],
    'one byte over' => ['over' => 1, 'cut' => true],
]);
