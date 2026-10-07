<?php

test('the detector caveats have their pinned wording', function (string $key, int $count, string $wording) {
    expect(trans_choice("firewatch::messages.{$key}", $count, ['count' => $count]))->toBe($wording);
})->with([
    'reads' => ['detect_caveat_reads', 1, 'Reads are recognised by the first keyword; a WITH statement that writes counts as a read.'],
    'one incomplete execution' => ['detect_caveat_incomplete', 1, '1 examined execution has fewer captured than counted queries; run counts and shares are lower bounds.'],
    'several incomplete executions' => ['detect_caveat_incomplete', 3, '3 examined executions have fewer captured than counted queries; run counts and shares are lower bounds.'],
    'memory' => ['detect_caveat_memory', 1, 'A long-lived process inherits memory it already held; a peak is not attributable to code.'],
    'wait' => ['detect_caveat_wait', 1, 'The wire carries no availability time: a delayed or backed-off job counts as waiting; round-number waits are usually delays.'],
    'pending' => ['detect_caveat_pending', 1, 'No attempt is recorded: no worker has run, or one is running; the store cannot tell.'],
    'skipped' => ['detect_caveat_skipped', 1, 'Skipped runs are often intended.'],
    'not fired' => ['detect_caveat_not_fired', 1, 'A task that did not fire leaves no record.'],
]);
