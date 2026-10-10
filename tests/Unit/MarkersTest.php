<?php

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\PruneReason;

function markerStore(): SQLite3
{
    $connection = new SQLite3(':memory:');
    $connection->enableExceptions(true);
    $connection->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');

    return $connection;
}

it('reads no marker from a store that has none', function () {
    $connection = markerStore();

    $markers = Markers::read($connection);

    expect($markers)->toEqual(new Markers);
});

it('reads the creation and the rebuild with their microseconds', function () {
    $connection = markerStore();
    $at = CarbonImmutable::createFromFormat('U.u', '1790776800.250000');

    Markers::markCreated($connection, $at, '1.0.0');
    Markers::markRebuilt($connection, $at->addSeconds(5), 'corrupt');
    $markers = Markers::read($connection);

    expect($markers->createdAt)->toBe(1790776800.25)
        ->and($markers->rebuiltAt)->toBe(1790776805.25)
        ->and($markers->rebuiltWhy)->toBe('corrupt');
});

it('advances the pruned-through instant with the reason of the pass that advanced it', function () {
    $connection = markerStore();

    Markers::advancePrunedThrough($connection, 100.5, PruneReason::AGE);
    Markers::advancePrunedThrough($connection, 200.0, PruneReason::CAP);
    $markers = Markers::read($connection);

    expect($markers->prunedThrough)->toBe(200.0)
        ->and($markers->prunedReason)->toBe(PruneReason::CAP);
});

it('never moves an instant back, and an equal one keeps the reason that set it', function (float $second) {
    $connection = markerStore();
    Markers::advancePrunedThrough($connection, 200.0, PruneReason::AGE);
    Markers::markCleared($connection, 200.0);

    Markers::advancePrunedThrough($connection, $second, PruneReason::SIZE);
    Markers::markCleared($connection, $second);
    $markers = Markers::read($connection);

    expect($markers->prunedThrough)->toBe(200.0)
        ->and($markers->prunedReason)->toBe(PruneReason::AGE)
        ->and($markers->clearedAt)->toBe(200.0);
})->with([
    'an earlier instant' => [199.999999],
    'the same instant' => [200.0],
]);

it('keeps the clear of each type apart, and never moves one back', function () {
    $connection = markerStore();

    Markers::markTypeCleared($connection, RecordType::LOG, 300.0);
    Markers::markTypeCleared($connection, RecordType::REQUEST, 100.0);
    Markers::markTypeCleared($connection, RecordType::LOG, 250.0);
    $markers = Markers::read($connection);

    expect($markers->clearedTypes)->toBe(['log' => 300.0, 'request' => 100.0])
        ->and($markers->clearedAtOf(RecordType::LOG))->toBe(300.0)
        ->and($markers->clearedAtOf(RecordType::QUERY))->toBeNull()
        ->and($markers->clearedAt)->toBeNull();
});

it('claims the prune pass once per interval', function () {
    $connection = markerStore();

    $first = Markers::claimPrune($connection, 1000.0, intervalSeconds: 60);
    $within = Markers::claimPrune($connection, 1059.0, intervalSeconds: 60);
    $after = Markers::claimPrune($connection, 1061.0, intervalSeconds: 60);
    $markers = Markers::read($connection);

    expect($first)->toBeTrue()
        ->and($within)->toBeFalse()
        ->and($after)->toBeTrue()
        ->and($markers->pruneClaimedAt)->toBe(1061.0);
});

it('records how Nightwatch is installed, and changes it when it changes', function (bool $verified) {
    $connection = markerStore();

    Markers::recordNightwatch($connection, 'v1.30.2', verified: $verified);
    Markers::recordNightwatch($connection, 'v1.30.2', verified: $verified);
    $markers = Markers::read($connection);

    expect($markers->nightwatchVersion)->toBe('v1.30.2')
        ->and($markers->nightwatchVerified)->toBe($verified);

    Markers::recordNightwatch($connection, 'v2.0.0', verified: ! $verified);
    $markers = Markers::read($connection);

    expect($markers->nightwatchVersion)->toBe('v2.0.0')
        ->and($markers->nightwatchVerified)->toBe(! $verified);
})->with([
    'a verified release' => [true],
    'an unverified release' => [false],
]);

it('reads a prune that names no reason as pruned by age, and a marker that is no number as absent', function () {
    $connection = markerStore();
    $connection->exec("INSERT INTO meta (key, value) VALUES ('pruned_through', '400'), ('created_at', 'soon'), ('cleared_types', 'not json')");

    $markers = Markers::read($connection);

    expect($markers->prunedThrough)->toBe(400.0)
        ->and($markers->prunedReason)->toBe(PruneReason::AGE)
        ->and($markers->createdAt)->toBeNull()
        ->and($markers->clearedTypes)->toBe([]);
});
