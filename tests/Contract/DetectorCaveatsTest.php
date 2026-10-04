<?php

test('the detector caveats have their pinned wording', function (string $key, int $count, string $wording) {
    expect(trans_choice("firewatch::messages.{$key}", $count, ['count' => $count]))->toBe($wording);
})->with([
    'reads' => ['detect_caveat_reads', 1, 'Reads are recognised by the first keyword; a WITH statement that writes counts as a read.'],
    'one incomplete execution' => ['detect_caveat_incomplete', 1, '1 examined execution has fewer captured than counted queries; run counts and shares are lower bounds.'],
    'several incomplete executions' => ['detect_caveat_incomplete', 3, '3 examined executions have fewer captured than counted queries; run counts and shares are lower bounds.'],
]);
