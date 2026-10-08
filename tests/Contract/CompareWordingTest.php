<?php

test('the calls compare offers name the first group listed, which is true when no group moved', function () {
    expect(__('firewatch::messages.compare_next_occurrences'))->toBe('List the records of the first group listed.')
        ->and(__('firewatch::messages.compare_next_rank'))->toBe('Break the first group listed down by deploy.');
});
