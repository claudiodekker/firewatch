<?php

use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\PruneReason;

it('starts a type\'s history at the latest of the store\'s markers', function (Markers $markers, ?float $from, ?string $reason) {
    $history = History::of($markers, [RecordType::REQUEST]);

    expect($history->from)->toBe($from)
        ->and($history->reason?->value)->toBe($reason);
})->with([
    'no marker' => [new Markers, null, null],
    'creation' => [new Markers(createdAt: 100.5), 100.5, 'created'],
    'a clear after creation' => [new Markers(createdAt: 100.0, clearedAt: 200.0), 200.0, 'cleared'],
    'a clear of the type' => [new Markers(createdAt: 100.0, clearedTypes: ['request' => 300.0]), 300.0, 'cleared-type'],
    'a clear of another type' => [new Markers(createdAt: 100.0, clearedTypes: ['query' => 300.0]), 100.0, 'created'],
    'a prune by age' => [new Markers(createdAt: 100.0, prunedThrough: 400.0, prunedReason: PruneReason::AGE), 400.0, 'pruned-age'],
    'a prune by record count' => [new Markers(createdAt: 100.0, prunedThrough: 400.0, prunedReason: PruneReason::CAP), 400.0, 'pruned-cap'],
    'a prune by size' => [new Markers(createdAt: 100.0, prunedThrough: 400.0, prunedReason: PruneReason::SIZE), 400.0, 'pruned-size'],
    'a clear before a prune' => [new Markers(createdAt: 100.0, clearedAt: 200.0, prunedThrough: 400.0, prunedReason: PruneReason::CAP), 400.0, 'pruned-cap'],
    'a tie of a clear and a prune' => [new Markers(clearedAt: 200.0, prunedThrough: 200.0, prunedReason: PruneReason::AGE), 200.0, 'cleared'],
    'a tie of a clear of the type and a prune' => [new Markers(clearedTypes: ['request' => 200.0], prunedThrough: 200.0, prunedReason: PruneReason::AGE), 200.0, 'cleared-type'],
    'a tie of a clear and a creation' => [new Markers(createdAt: 200.0, clearedAt: 200.0), 200.0, 'cleared'],
    'a tie of a prune and a creation' => [new Markers(createdAt: 200.0, prunedThrough: 200.0, prunedReason: PruneReason::CAP), 200.0, 'pruned-cap'],
]);

it('starts the history of several types at the latest start among them, with that type\'s reason', function () {
    $markers = new Markers(createdAt: 100.0, clearedTypes: ['query' => 300.0, 'log' => 250.0]);

    $history = History::of($markers, [RecordType::REQUEST, RecordType::LOG, RecordType::QUERY]);

    expect($history->from)->toBe(300.0)
        ->and($history->reason?->value)->toBe('cleared-type');
});

it('has no start for no type', function () {
    expect(History::of(new Markers(createdAt: 100.0), [])->from)->toBeNull();
});

it('is retained for the age and record count it was given', function (?int $age, ?int $records) {
    $history = History::of(new Markers, [RecordType::REQUEST], $age, $records);

    expect($history->toArray())->toBe(['from' => null, 'reason' => null, 'retention' => ['age_seconds' => $age, 'records' => $records]]);
})->with([
    'both' => [604800, 100000],
    'unlimited' => [null, null],
]);
