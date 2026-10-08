<?php

test('each fixed sentence of an attribution has its pinned wording', function (Closure $text, string $wording) {
    expect($text())->toBe($wording);
})->with([
    'the summary' => [
        fn () => __('firewatch::messages.actor_summary', ['person' => 'Taylor', 'attributed' => 3, 'total' => 5, 'direct' => 1, 'dispatch' => 1, 'inside' => 1, 'unattributable' => 1]),
        'Taylor: 3 of 5 executions in the window attributed (1 direct, 1 dispatch, 1 inside); 1 cannot be attributed.',
    ],
    'the summary without the person' => [
        fn () => __('firewatch::messages.actor_summary_without_person', ['attributed' => 3, 'total' => 5, 'direct' => 1, 'dispatch' => 1, 'inside' => 1, 'unattributable' => 1]),
        '3 of 5 executions in the window attributed (1 direct, 1 dispatch, 1 inside); 1 cannot be attributed.',
    ],
    'the summary of nothing attributed' => [
        fn () => __('firewatch::messages.actor_nothing_attributed_summary', ['person' => 'Taylor']),
        'Nothing in this window is attributed to Taylor.',
    ],
    'the summary of nothing attributed without the person' => [
        fn () => __('firewatch::messages.actor_nothing_attributed_summary_without_person'),
        'Nothing in this window is attributed to the person identified.',
    ],
    'the message of nothing attributed' => [
        fn () => __('firewatch::messages.actor_nothing_attributed', ['person' => 'Taylor', 'population' => 7]),
        'Nothing in this window is attributed to Taylor, among the 7 executions that started in it.',
    ],
    'the message of a window without executions' => [
        fn () => __('firewatch::messages.actor_no_executions', ['population' => 40]),
        'No request, command, job attempt or scheduled task started in this window, so nothing can be attributed; the store holds 40 records: widen the window or move it.',
    ],
    'how to see past one listed execution' => [
        fn () => trans_choice('firewatch::messages.actor_executions_how', 1, ['listed' => 1]),
        'The newest attributed execution is listed; narrow `since` and `until` to see the others.',
    ],
    'how to see past several listed executions' => [
        fn () => trans_choice('firewatch::messages.actor_executions_how', 20, ['listed' => 20]),
        'The 20 newest attributed executions are listed; narrow `since` and `until` to see the others.',
    ],
    'commands and tasks, several of each' => [
        fn () => __('firewatch::messages.actor_commands_note', [
            'commands' => trans_choice('firewatch::messages.actor_commands_count', 2, ['count' => 2]),
            'tasks' => trans_choice('firewatch::messages.actor_tasks_count', 3, ['count' => 3]),
            'inside' => trans_choice('firewatch::messages.actor_inside_count', 2, ['count' => 2]),
        ]),
        '2 commands and 3 scheduled tasks ran in this window and carry no actor; 2 of them are shown as inside work; what this person set off through the others is not shown.',
    ],
    'commands and tasks, one of each' => [
        fn () => __('firewatch::messages.actor_commands_note', [
            'commands' => trans_choice('firewatch::messages.actor_commands_count', 1, ['count' => 1]),
            'tasks' => trans_choice('firewatch::messages.actor_tasks_count', 1, ['count' => 1]),
            'inside' => trans_choice('firewatch::messages.actor_inside_count', 1, ['count' => 1]),
        ]),
        '1 command and 1 scheduled task ran in this window and carry no actor; 1 of them is shown as inside work; what this person set off through the others is not shown.',
    ],
    'commands and tasks, none of either inside' => [
        fn () => __('firewatch::messages.actor_commands_note', [
            'commands' => trans_choice('firewatch::messages.actor_commands_count', 1, ['count' => 1]),
            'tasks' => trans_choice('firewatch::messages.actor_tasks_count', 0, ['count' => 0]),
            'inside' => trans_choice('firewatch::messages.actor_inside_count', 0, ['count' => 0]),
        ]),
        '1 command and 0 scheduled tasks ran in this window and carry no actor; 0 of them are shown as inside work; what this person set off through the others is not shown.',
    ],
    'no commands or tasks' => [
        fn () => __('firewatch::messages.actor_no_commands_note'),
        'No commands or scheduled tasks ran in this window, so nothing was lost to them.',
    ],
    'one attempt with no actor' => [
        fn () => trans_choice('firewatch::messages.actor_no_actor_note', 1, ['count' => 1]),
        '1 job attempt had no recorded user and no traceable dispatch; it may belong to this person.',
    ],
    'several attempts with no actor' => [
        fn () => trans_choice('firewatch::messages.actor_no_actor_note', 2, ['count' => 2]),
        '2 job attempts had no recorded user and no traceable dispatch; some may belong to this person.',
    ],
    'one request with no recorded user' => [
        fn () => trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
        '1 request carries no recorded user (a guest, a user of a non-default guard, or one Nightwatch could not resolve) and cannot be attributed.',
    ],
    'several requests with no recorded user' => [
        fn () => trans_choice('firewatch::messages.actor_guest_note', 2, ['count' => 2]),
        '2 requests carry no recorded user (a guest, a user of a non-default guard, or one Nightwatch could not resolve) and cannot be attributed.',
    ],
    'what an id and a name are' => [
        fn () => __('firewatch::messages.actor_identity_note'),
        'User ids are keys recorded as sent, not qualified by guard or model; a reseeded database can give an id to another person. Only the latest name and username are searchable. `first_seen_at` is when the directory row was created, not when the person first acted. History before the coverage start is gone.',
    ],
    'occurrences by user id' => [
        fn () => __('firewatch::messages.occurrences_user_only'),
        'Filtered by recorded user only; use `actor` for dispatch and inside links.',
    ],
]);
