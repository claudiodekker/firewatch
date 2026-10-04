<?php

use ClaudioDekker\Firewatch\Mcp\Detectors\Threshold;
use ClaudioDekker\Firewatch\Mcp\Refusal;

function thrKind(string $kind): Threshold
{
    return $kind === 'whole'
        ? new Threshold(name: 'status', unit: 'status', default: 400, minimum: 100, maximum: 599)
        : new Threshold(name: 'peak', unit: 'mb', default: 64, minimum: 1, whole: false);
}

it('reads a number in range as it is', function (string $kind, mixed $value, int|float $expected) {
    expect(thrKind($kind)->read($value, 'call()'))->toBe($expected);
})->with([
    'the lowest whole number' => ['whole', 100, 100],
    'the highest whole number' => ['whole', 599, 599],
    'the lowest fraction' => ['fractional', 1, 1],
    'a fraction' => ['fractional', 1.5, 1.5],
    'a large fraction with no maximum' => ['fractional', 4096.25, 4096.25],
]);

it('refuses what is out of range, a fraction of a whole one, or no number, naming what it accepts', function (string $kind, mixed $value, string $accepted) {
    try {
        thrKind($kind)->read($value, 'call()');
    } catch (Refusal $refusal) {
        expect($refusal->getMessage())->toContain("`threshold` must be {$accepted}; got ".json_encode($value))->toContain("accepted: {$accepted}");

        return;
    }

    $this->fail('The threshold accepted '.json_encode($value));
})->with([
    'below the range' => ['whole', 99, 'a whole number of 100 to 599'],
    'above the range' => ['whole', 600, 'a whole number of 100 to 599'],
    'a fraction of a whole one' => ['whole', 400.5, 'a whole number of 100 to 599'],
    'a whole number written as a float' => ['whole', 450.0, 'a whole number of 100 to 599'],
    'text' => ['whole', '400', 'a whole number of 100 to 599'],
    'a flag' => ['whole', true, 'a whole number of 100 to 599'],
    'below the minimum of a fractional one' => ['fractional', 0.5, 'a number of 1 or more'],
    'zero' => ['fractional', 0, 'a number of 1 or more'],
    'text for a fractional one' => ['fractional', '2', 'a number of 1 or more'],
]);

it('states the value in force, the default, the unit and the range', function () {
    expect(thrKind('whole')->describe(500))->toBe(['name' => 'status', 'value' => 500, 'default' => 400, 'unit' => 'status', 'range' => ['min' => 100, 'max' => 599], 'is_default' => false])
        ->and(thrKind('fractional')->describe())->toBe(['name' => 'peak', 'value' => 64, 'default' => 64, 'unit' => 'mb', 'range' => ['min' => 1, 'max' => null], 'is_default' => true]);
});
