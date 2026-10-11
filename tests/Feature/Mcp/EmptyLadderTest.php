<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;

const LADDER_AT = 1790776000.0;

it('gives the same empty answer from every tool for a named group over an empty window', function (string $group, string $kind) {
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['_group' => str_repeat('a', 32), 'timestamp' => LADDER_AT]),
        syntheticRecord(RecordType::REQUEST)->with(['_group' => str_repeat('b', 32), 'timestamp' => LADDER_AT + 10]),
    ]);

    $window = ['since' => LADDER_AT + 100, 'until' => LADDER_AT + 200];
    $named = ['group' => str_repeat($group, 32)];

    $empties = collect([
        'trend' => Envelope::assert(Trend::class, [...$named, ...$window]),
        'compare' => Envelope::assert(Compare::class, [...$named, ...$window, 'split_at' => LADDER_AT + 150]),
        'rank' => Envelope::assert(Rank::class, [...$named, ...$window]),
        'occurrences' => Envelope::assert(Occurrences::class, [...$named, ...$window]),
    ])->map(fn (array $envelope) => [
        'kind' => $envelope['empty']['kind'],
        'population' => $envelope['empty']['population'],
        'summary' => $envelope['summary'],
        'result' => $envelope['result'],
    ]);

    expect($empties->pluck('kind')->unique()->all())->toBe([$kind])
        ->and($empties->pluck('population')->unique()->count())->toBe(1)
        ->and($empties->pluck('summary')->unique()->count())->toBe(1)
        ->and($empties->pluck('result')->unique()->count())->toBe(1);
})->with([
    'a group the store does not hold' => ['f', 'no_match'],
    'a group the store holds' => ['a', 'window_empty'],
]);
