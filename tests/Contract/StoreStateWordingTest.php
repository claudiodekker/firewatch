<?php

use ClaudioDekker\Firewatch\Store\StoreState;

test('each store state has its pinned wording', function (string $key, array $replace, string $wording) {
    expect(__("firewatch::messages.{$key}", $replace))->toBe($wording);
})->with([
    'absent' => [
        'no_store',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite'],
        'No application process has written a store at /srv/app/storage/firewatch/firewatch.sqlite yet: exercise the application, then ask again.',
    ],
    'foreign' => [
        'store_unusable.foreign_file',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite'],
        'The file at /srv/app/storage/firewatch/firewatch.sqlite is not a Firewatch store, and Firewatch will not touch it: set `database` to another path.',
    ],
    'an older schema' => [
        'store_unusable.older_schema',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite', 'found' => 1, 'expected' => 2],
        'The store at /srv/app/storage/firewatch/firewatch.sqlite was written by an older Firewatch schema (version 1, this release reads version 2). The next captured batch rebuilds it; it holds no readable data until then.',
    ],
    'a newer schema' => [
        'store_unusable.newer_schema',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite', 'found' => 3, 'expected' => 2],
        'The store at /srv/app/storage/firewatch/firewatch.sqlite was written by a newer Firewatch schema (version 3, this release reads version 2). This release never rebuilds it and drops what it captures; upgrade Firewatch to read it.',
    ],
    'a SQLite below the floor' => [
        'store_unusable.sqlite_too_old',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite', 'version' => '3.40.1', 'minimum' => '3.41.0'],
        'SQLite 3.40.1 is older than the 3.41.0 Firewatch needs, so nothing is captured and the store at /srv/app/storage/firewatch/firewatch.sqlite can not be read.',
    ],
    'corrupt' => [
        'store_unusable.unreadable',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite', 'cause' => 'the file is damaged. The next captured batch moves it aside and starts a new one.'],
        'The store at /srv/app/storage/firewatch/firewatch.sqlite can not be read: the file is damaged. The next captured batch moves it aside and starts a new one.',
    ],
    'busy' => [
        'store_unusable.unreadable',
        ['path' => '/srv/app/storage/firewatch/firewatch.sqlite', 'cause' => 'it stayed busy for 1000 ms, so try again.'],
        'The store at /srv/app/storage/firewatch/firewatch.sqlite can not be read: it stayed busy for 1000 ms, so try again.',
    ],
    'the cause of a damaged store' => ['store_causes.corrupt', [], 'the file is damaged. The next captured batch moves it aside and starts a new one.'],
    'the cause of a busy store' => ['store_causes.busy', [], 'it stayed busy for 1000 ms, so try again.'],
]);

test('the store states are the closed set of the design', function () {
    $values = array_map(fn (StoreState $state) => $state->value, StoreState::cases());

    expect($values)->toEqualCanonicalizing(['absent', 'schema_mismatch', 'corrupt', 'foreign', 'busy', 'unavailable']);
});
