<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\RecordType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\Query as QueryRecord;

/**
 * @return list<array{sql: string, bindings: mixed}>
 */
function storedBindings(): array
{
    return array_map(function (array $row) {
        $row['bindings'] = $row['bindings'] === null ? null : json_decode($row['bindings'], associative: true, flags: JSON_THROW_ON_ERROR);

        return $row;
    }, storeRows("SELECT sql, bindings FROM queries WHERE sql LIKE 'select %probe%' ORDER BY id"));
}

/**
 * @param  list<mixed>  $bindings
 */
function selectProbe(array $bindings): void
{
    $placeholders = implode(', ', array_fill(0, max(count($bindings), 1), '?'));

    DB::select("select {$placeholders} as probe", $bindings === [] ? [1] : $bindings);
}

it('pairs each query with its own bindings, also when the same query runs twice', function () {
    DB::select('select ?, ? as probe', [1, 'two']);
    DB::select('select ?, ? as probe', [3, 'four']);
    Nightwatch::digest();

    expect(storedBindings())->toBe([
        ['sql' => 'select ?, ? as probe', 'bindings' => [1, 'two']],
        ['sql' => 'select ?, ? as probe', 'bindings' => [3, 'four']],
    ]);
});

it('stores the values sent to the database, raw', function () {
    DB::select('select ?, ?, ?, ?, ?, ? as probe', [null, true, 1.5, -2, CarbonImmutable::parse('2026-09-30 12:34:56'), 'secret']);
    Nightwatch::digest();

    expect(storedBindings()[0]['bindings'])->toBe([null, 1, 1.5, -2, '2026-09-30 12:34:56', 'secret']);
});

it('stores an empty list for a query without bindings', function () {
    DB::select('select 1 as probe');
    Nightwatch::digest();

    expect(storedBindings())->toBe([['sql' => 'select 1 as probe', 'bindings' => []]]);
});

it('keeps a value of up to 1,024 bytes and cuts a longer one to 1,024 bytes with the truncation marker', function (int $bytes, bool $cut) {
    $value = str_repeat('é', intdiv($bytes, 2)).str_repeat('a', $bytes % 2);
    $marker = "... [truncated, {$bytes} bytes total]";

    selectProbe([$value]);
    Nightwatch::digest();

    $stored = storedBindings()[0]['bindings'][0];

    expect($stored)->toBe($cut ? mb_strcut($value, 0, 1_024 - strlen($marker), 'UTF-8').$marker : $value)
        ->and(strlen($stored))->toBeLessThanOrEqual(1_024);
})->with([
    'exactly the limit' => ['bytes' => 1_024, 'cut' => false],
    'one byte over' => ['bytes' => 1_025, 'cut' => true],
]);

it('replaces a value that is not UTF-8 with its size, before any cut', function (string $value) {
    selectProbe([$value, 'text']);
    Nightwatch::digest();

    expect(storedBindings()[0]['bindings'])->toBe(['[binary '.strlen($value).' bytes]', 'text']);
})->with([
    'short' => "\xff\xfe\x00\x01",
    'longer than a value may be' => "\xff".str_repeat("\x00", 2_000),
]);

it('stores a float that JSON can\'t hold as its string', function (float $value, string $stored) {
    selectProbe([$value]);
    Nightwatch::digest();

    expect(storedBindings()[0]['bindings'])->toBe([$stored]);
})->with([
    'infinity' => [INF, 'INF'],
    'negative infinity' => [-INF, '-INF'],
    'not a number' => [NAN, 'NAN'],
]);

it('keeps each query\'s bindings with its record when a full buffer drops its oldest', function () {
    app(Core::class)->ingest->shouldDigestWhenBufferIsFull(false);

    foreach (range(1, 600) as $index) {
        DB::select("select ? as probe_{$index}", [$index]);
    }

    Nightwatch::digest();

    $rows = storedBindings();

    expect($rows)->toHaveCount(500)
        ->and(array_map(fn (array $row) => $row['sql'], $rows))->toBe(array_map(fn (int $index) => "select ? as probe_{$index}", range(101, 600)))
        ->and(array_map(fn (array $row) => $row['bindings'], $rows))->toBe(array_map(fn (int $index) => [$index], range(101, 600)));
});

it('stores a stringable value as its string', function () {
    DB::select('select ? as probe', [str('text')]);
    Nightwatch::digest();

    expect(storedBindings()[0]['bindings'])->toBe(['text']);
});

it('replaces any other value with its type', function () {
    // No driver accepts such a value, so only a QueryExecuted event dispatched by hand carries one.
    event(new QueryExecuted('select ? as probe', [new stdClass], 0.1, DB::connection()));
    Nightwatch::digest();

    expect(storedBindings()[0]['bindings'])->toBe(['[stdClass]']);
});

it('stores no bindings for a query whose bindings can\'t be read, and still records it', function () {
    $value = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('The value can\'t be read.');
        }
    };

    // A driver casts the value before the query runs, so only a QueryExecuted event dispatched by hand carries one.
    event(new QueryExecuted('select ? as probe', [$value], 0.1, DB::connection()));
    Nightwatch::digest();

    expect(storedBindings())->toBe([['sql' => 'select ? as probe', 'bindings' => null]]);
});

it('pairs each query with its own bindings after Firewatch registers again', function () {
    registerFirewatch();

    DB::select('select ? as probe', ['first']);
    DB::select('select ? as probe', ['second']);
    Nightwatch::digest();

    expect(array_column(storedBindings(), 'bindings'))->toBe([['first'], ['second']]);
});

it('keeps bindings of up to 16,384 bytes of JSON and replaces the rest with a count once over', function (array $bindings, array $stored) {
    selectProbe($bindings);
    Nightwatch::digest();

    $kept = storedBindings()[0]['bindings'];

    expect($kept)->toBe($stored)
        ->and(strlen(json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBeLessThanOrEqual(16_384);
})->with([
    // Sixteen 1,000-byte values and one of 332 bytes encode to exactly 16,384 bytes.
    'exactly the limit' => [
        'bindings' => [...array_fill(0, 16, str_repeat('a', 1_000)), str_repeat('b', 332)],
        'stored' => [...array_fill(0, 16, str_repeat('a', 1_000)), str_repeat('b', 332)],
    ],
    'one byte over' => [
        'bindings' => [...array_fill(0, 16, str_repeat('a', 1_000)), str_repeat('b', 333)],
        'stored' => [...array_fill(0, 16, str_repeat('a', 1_000)), '... [1 more bindings]'],
    ],
    'over, where the count needs the room of a kept value' => [
        'bindings' => [...array_fill(0, 16, str_repeat('a', 1_000)), str_repeat('b', 332), 'c'],
        'stored' => [...array_fill(0, 16, str_repeat('a', 1_000)), '... [2 more bindings]'],
    ],
]);

it('does not lend a query\'s bindings to a later one when Nightwatch never records it', function (Closure $skip) {
    $skip(fn () => DB::select('select ? as probe', ['skipped']));
    DB::select('select ? as probe', ['recorded']);
    Nightwatch::digest();

    expect(storedBindings())->toBe([['sql' => 'select ? as probe', 'bindings' => ['recorded']]]);
})->with([
    'rejected' => [function (Closure $query) {
        $rejected = false;
        Nightwatch::rejectQueries(function (QueryRecord $record) use (&$rejected) {
            return $record->sql === 'select ? as probe' && ! $rejected && $rejected = true;
        });
        $query();
    }],
    'paused' => [function (Closure $query) {
        Nightwatch::pause();
        $query();
        Nightwatch::resume();
    }],
    'ignored' => [fn (Closure $query) => Nightwatch::ignore($query)],
]);

it('pairs a query whose recording ran another query', function () {
    // Nightwatch pauses while its callbacks run, so it never records the inner query.
    $nested = false;
    Nightwatch::rejectQueries(function (QueryRecord $record) use (&$nested) {
        if ($record->sql === 'select ? as outer_probe' && ! $nested) {
            $nested = true;
            DB::select('select ? as inner_probe', ['inner']);
        }

        return false;
    });

    DB::select('select ? as outer_probe', ['outer']);
    Nightwatch::digest();

    expect($nested)->toBeTrue()
        ->and(storedBindings())->toBe([['sql' => 'select ? as outer_probe', 'bindings' => ['outer']]]);
});

it('stores no bindings for a query whose recorded SQL no longer matches what ran', function () {
    Nightwatch::redactQueries(function (QueryRecord $record) {
        if ($record->sql === 'select ? as probe') {
            $record->sql = 'select ? as redacted_probe';
        }
    });

    DB::select('select ? as probe', ['secret']);
    Nightwatch::digest();

    expect(storedBindings())->toBe([['sql' => 'select ? as redacted_probe', 'bindings' => null]]);
});

it('stores no bindings for a query whose recorded connection no longer matches what ran', function () {
    // Nightwatch cuts a connection name to 255 bytes.
    $name = str_repeat('c', 256);
    config()->set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => ':memory:']);

    DB::connection($name)->select('select ? as probe', ['secret']);
    Nightwatch::digest();

    expect(storedBindings())->toBe([['sql' => 'select ? as probe', 'bindings' => null]]);
});

it('stores no bindings for a query record Firewatch saw no query for', function () {
    ingest([syntheticRecord(RecordType::QUERY)->with(['sql' => 'select ? as probe'])]);

    expect(storedBindings())->toBe([['sql' => 'select ? as probe', 'bindings' => null]]);
});

it('captures no bindings when Off or stepped aside', function (array $config) {
    app('events')->forget(QueryExecuted::class);
    config()->set($config);

    registerFirewatch();

    expect(app('events')->hasListeners(QueryExecuted::class))->toBeFalse();
})->with([
    'Off' => ['config' => ['firewatch.enabled' => false]],
    'stepped aside' => ['config' => ['firewatch.environments' => 'local']],
]);
