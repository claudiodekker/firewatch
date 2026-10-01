<?php

use ClaudioDekker\Firewatch\Mcp\BlindSpots;

test('the structural blind spot catalogue pins its ids and sentences', function () {
    expect(BlindSpots::catalogue())->toBe([
        'console-requests' => 'Requests are recorded only while the application serves HTTP; test runs and console processes record none. An empty request answer may mean the app was never exercised over HTTP.',
        'unanswered-outgoing-requests' => 'An outgoing request that received no response (timeout, connection failure) leaves no record.',
        'payload-on-server-error-only' => 'A request\'s payload is stored only when the response status is 500, as far as verified.',
        'dead-counters' => 'lazy_loads, hydrated_models, files_read and files_written are always 0 because the sensors never fill them; 0 does not mean none. Use the N+1 detector on query rows.',
        'failed-flag-unpopulated' => 'The failed flag on mail and notification records is always false; failures are not recorded.',
        'mail-by-notification' => 'Mail sent by a notification is recorded as a notification, not as mail.',
        'sync-jobs-unrecorded' => 'Jobs on the sync connection run inside the dispatching execution and have no attempt record.',
        'vendor-defaults-unrecorded' => 'Vendor commands and framework cache keys on Nightwatch\'s default exclusion lists are not recorded.',
        'exceptions-unreported' => 'Exceptions the application does not report, and exceptions inside scheduled tasks, are not recorded.',
        'named-log-channels' => 'Logs written directly to a named channel are not captured; only the default channel is.',
        'memory-is-process-peak' => 'Memory is the whole process\'s peak since the sensor last reset it: requests, scheduled tasks and job attempts reset it, a command reports its whole process; it is not attributable to code.',
        'query-bindings-unpaired' => 'A query\'s bindings are null when they could not be paired with certainty; null means unknown, not none.',
        'uninstrumented-dispatcher' => 'A job dispatched where Nightwatch was not running has attempts but no dispatch, and may have a fresh trace per attempt; join on job id, not trace id.',
        'actor-partial' => 'Commands and scheduled tasks carry no actor; requests with no recorded user may be guests or users of a non-default guard; ids are keys, not people; names come from the application\'s user callback, and impersonation shows the impersonated user.',
        'visible-at-completion' => 'An execution is recorded when it finishes: work still running is absent, and work spanning a boundary sits on the side where it started.',
        'application-opt-outs' => 'Anything the application\'s opt-outs excluded (pause, ignore, reject callbacks, never-sample) and processes killed before flushing are absent.',
        'values-truncated' => 'Long values are cut at ingest (65,535 bytes per string, bindings 16,384 bytes in all) and again to 2,000 characters when printed; the SQL tool prints at most 2,000 characters per cell, read the rest with substr().',
        'octane-bootstrap' => 'Under Octane the request bootstrap stage is always 0, because the worker is already booted; compare stage shares only between requests served the same way.',
    ]);
});

test('every blind spot is at most 40 words', function () {
    foreach (BlindSpots::catalogue() as $id => $message) {
        expect(str_word_count(str_replace(['_', '-'], 'x', $message)))->toBeLessThanOrEqual(40, $id);
    }
});
