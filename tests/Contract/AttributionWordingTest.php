<?php

test('each fixed sentence of an attribution has its pinned wording', function (string $key, array $replace, string $wording, int $count) {
    $text = $count === 0 ? __("firewatch::messages.{$key}", $replace) : trans_choice("firewatch::messages.{$key}", $count, $replace);

    expect($text)->toBe($wording);
})->with([
    'the summary' => [
        'actor_summary',
        ['person' => 'Taylor', 'attributed' => 3, 'total' => 5, 'direct' => 1, 'dispatch' => 1, 'inside' => 1, 'unattributed' => 1],
        'Taylor: 3 of 5 executions in the window attributed (1 direct, 1 dispatch, 1 inside); 1 cannot be attributed.',
        0,
    ],
    'commands and tasks' => [
        'actor_commands_note',
        ['commands' => 2, 'tasks' => 1, 'inside' => 1],
        '2 commands and 1 scheduled tasks ran in this window and carry no actor; 1 of them are shown as inside work; what this person set off through the others is not shown.',
        0,
    ],
    'no commands or tasks' => [
        'actor_no_commands_note',
        [],
        'No commands or scheduled tasks ran in this window, so nothing was lost to them.',
        0,
    ],
    'attempts with no actor' => [
        'actor_no_actor_note',
        ['count' => 2],
        '2 job attempts had no recorded user and no traceable dispatch; some may belong to this person.',
        2,
    ],
    'requests with no recorded user' => [
        'actor_guest_note',
        ['count' => 2],
        '2 requests carry no recorded user (a guest, a user of a non-default guard, or one Nightwatch could not resolve) and cannot be attributed.',
        2,
    ],
    'the standing caveats' => [
        'actor_caveats_note',
        [],
        'User ids are keys recorded as sent, not qualified by guard or model; a reseeded database can give an id to another person. Only the latest name and username are searchable. `first_seen_at` is when the directory row was created, not when the person first acted. History before the coverage start is gone.',
        0,
    ],
    'occurrences by user id' => [
        'occurrences_user_only',
        [],
        'Filtered by recorded user only; use `actor` for dispatch and inside links.',
        0,
    ],
]);
