<?php

use ClaudioDekker\Firewatch\Mcp\TimeGrammar;
use Illuminate\Support\Carbon;

function readTime(mixed $value, string $timezone = 'UTC'): ?float
{
    return TimeGrammar::parse($value, Carbon::parse('2026-09-30 14:00:00.5', 'UTC')->toImmutable(), $timezone);
}

it('reads a time of the grammar', function (mixed $value, string $timezone, float $epoch) {
    expect(readTime($value, $timezone))->toBe($epoch);
})->with([
    'epoch seconds' => ['1790776800', 'UTC', 1790776800.0],
    'epoch seconds as a number' => [1790776800, 'UTC', 1790776800.0],
    'decimal epoch' => ['1790776800.25', 'UTC', 1790776800.25],
    'decimal epoch as a number' => [1790776800.25, 'UTC', 1790776800.25],
    'the last epoch' => ['4102444800', 'UTC', 4102444800.0],
    'epoch zero' => ['0', 'UTC', 0.0],
    'ISO with Z' => ['2026-09-30T14:00:00Z', 'Europe/Amsterdam', 1790776800.0],
    'ISO with an offset' => ['2026-09-30T16:00:00+02:00', 'UTC', 1790776800.0],
    'ISO with fractional seconds' => ['2026-09-30T14:00:00.250Z', 'UTC', 1790776800.25],
    'ISO without seconds' => ['2026-09-30T14:00Z', 'UTC', 1790776800.0],
    'local date-time' => ['2026-09-30 16:00:00', 'Europe/Amsterdam', 1790776800.0],
    'local date-time with a T' => ['2026-09-30T16:00:00', 'Europe/Amsterdam', 1790776800.0],
    'local date-time without seconds' => ['2026-09-30 16:00', 'Europe/Amsterdam', 1790776800.0],
    'local date-time with microseconds' => ['2026-09-30 16:00:00.000250', 'Europe/Amsterdam', 1790776800.00025],
    'local date' => ['2026-09-30', 'Europe/Amsterdam', 1790719200.0],
    'now' => ['now', 'UTC', 1790776800.5],
    'now in capitals' => [' NOW ', 'UTC', 1790776800.5],
    'seconds ago' => ['-30s', 'UTC', 1790776770.5],
    'minutes ago' => ['-5m', 'UTC', 1790776500.5],
    'hours ago' => ['-2h', 'UTC', 1790769600.5],
    'a day ago' => ['-1d', 'UTC', 1790690400.5],
    'a day ago across a clock change' => ['-1d', 'Europe/Amsterdam', 1790690400.5],
    'weeks ago' => ['-2w', 'UTC', 1789567200.5],
    'a unit in words' => ['-3 hours', 'UTC', 1790766000.5],
    'a unit in singular' => ['-1 day', 'UTC', 1790690400.5],
    'a unit in capitals' => ['-1 Week', 'UTC', 1790172000.5],
    'minutes in words' => ['-10 minutes', 'UTC', 1790776200.5],
    'seconds in words' => ['-1 second', 'UTC', 1790776799.5],
    'hours in words' => ['-1 hour', 'UTC', 1790773200.5],
]);

it('refuses what the grammar does not read', function (mixed $value) {
    expect(readTime($value))->toBeNull();
})->with([
    'yesterday' => 'yesterday',
    'a millisecond epoch' => '1790776800000',
    'an epoch past the last' => '4102444801',
    'a negative epoch' => '-1790776800',
    'an empty string' => '',
    'only spaces' => '   ',
    'a relative time without the minus' => '1d',
    'a relative time in the future' => '+1d',
    'zero units' => '-0d',
    'months' => '-1mo',
    'years' => '-1y',
    'a decimal count' => '-1.5h',
    'a space after the minus' => '- 1d',
    'a unit that is none' => '-1 fortnight',
    'a count without a unit' => '-5',
    'a free-form phrase' => 'last tuesday',
    'a date that is not one' => '2026-02-30',
    'a month that is not one' => '2026-13-01',
    'an hour that is not one' => '2026-09-30 25:00',
    'a date in another order' => '30-09-2026',
    'a slashed date' => '2026/09/30',
    'trailing text' => '2026-09-30 and more',
    'a boolean' => true,
    'null' => null,
    'an array' => [[1790776800]],
    'a number that is a millisecond epoch' => 1790776800000,
]);
