<?php

use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\RecordType;

it('starts a type\'s history at the latest of the store\'s markers', function (array $meta, ?float $from, ?string $reason) {
    $history = History::of($meta, [RecordType::REQUEST]);

    expect($history->from)->toBe($from)
        ->and($history->reason)->toBe($reason);
})->with([
    'no marker' => [[], null, null],
    'creation' => [['created_at' => '100.5'], 100.5, 'created'],
    'a clear after creation' => [['created_at' => '100', 'cleared_at' => '200'], 200.0, 'cleared'],
    'a clear of the type' => [['created_at' => '100', 'cleared_types' => '{"request":300}'], 300.0, 'cleared-type'],
    'a clear of another type' => [['created_at' => '100', 'cleared_types' => '{"query":300}'], 100.0, 'created'],
    'a prune by age' => [['created_at' => '100', 'pruned_through' => '400', 'pruned_by' => 'age'], 400.0, 'pruned-age'],
    'a prune by record count' => [['created_at' => '100', 'pruned_through' => '400', 'pruned_by' => 'cap'], 400.0, 'pruned-cap'],
    'a prune by size' => [['created_at' => '100', 'pruned_through' => '400', 'pruned_by' => 'size'], 400.0, 'pruned-size'],
    'a prune that names no cause' => [['created_at' => '100', 'pruned_through' => '400'], 400.0, 'pruned-age'],
    'a clear before a prune' => [['created_at' => '100', 'cleared_at' => '200', 'pruned_through' => '400', 'pruned_by' => 'cap'], 400.0, 'pruned-cap'],
    'a tie of a clear and a prune' => [['cleared_at' => '200', 'pruned_through' => '200', 'pruned_by' => 'age'], 200.0, 'cleared'],
    'a tie of a clear of the type and a prune' => [['cleared_types' => '{"request":200}', 'pruned_through' => '200', 'pruned_by' => 'age'], 200.0, 'cleared-type'],
    'a tie of a clear and a creation' => [['created_at' => '200', 'cleared_at' => '200'], 200.0, 'cleared'],
    'a tie of a prune and a creation' => [['created_at' => '200', 'pruned_through' => '200', 'pruned_by' => 'cap'], 200.0, 'pruned-cap'],
    'markers that are not numbers' => [['created_at' => 'soon', 'cleared_types' => 'not json'], null, null],
]);

it('starts the history of several types at the latest start among them, with that type\'s reason', function () {
    $meta = ['created_at' => '100', 'cleared_types' => '{"query":300,"log":250}'];

    $history = History::of($meta, [RecordType::REQUEST, RecordType::LOG, RecordType::QUERY]);

    expect($history->from)->toBe(300.0)
        ->and($history->reason)->toBe('cleared-type');
});

it('has no start for no type', function () {
    expect(History::of(['created_at' => '100'], [])->from)->toBeNull();
});

it('is retained for the age and record count it was given', function (?int $age, ?int $records) {
    $history = History::of([], [RecordType::REQUEST], $age, $records);

    expect($history->toArray())->toBe(['from' => null, 'reason' => null, 'retention' => ['age_seconds' => $age, 'records' => $records]]);
})->with([
    'both' => [604800, 100000],
    'unlimited' => [null, null],
]);
