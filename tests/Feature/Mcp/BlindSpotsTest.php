<?php

use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\RecordType;

function blindSpotIds(array $types, bool $actor = false, bool $anchored = false, bool $storeLevel = false): array
{
    return array_column(BlindSpots::for($types, $actor, $anchored, $storeLevel), 'id');
}

it('attaches the blind spots of the types a call examined', function (RecordType $type, array $ids) {
    expect(blindSpotIds([$type]))->toBe($ids);
})->with([
    'request' => [RecordType::REQUEST, ['console-requests', 'payload-on-server-error-only', 'dead-counters', 'memory-is-process-peak', 'octane-bootstrap']],
    'command' => [RecordType::COMMAND, ['dead-counters', 'vendor-defaults-unrecorded', 'memory-is-process-peak']],
    'job attempt' => [RecordType::JOB_ATTEMPT, ['dead-counters', 'sync-jobs-unrecorded', 'memory-is-process-peak', 'uninstrumented-dispatcher']],
    'scheduled task' => [RecordType::SCHEDULED_TASK, ['dead-counters', 'memory-is-process-peak']],
    'query' => [RecordType::QUERY, ['query-bindings-unpaired']],
    'exception' => [RecordType::EXCEPTION, ['exceptions-unreported']],
    'log' => [RecordType::LOG, ['named-log-channels']],
    'cache event' => [RecordType::CACHE_EVENT, ['vendor-defaults-unrecorded']],
    'mail' => [RecordType::MAIL, ['failed-flag-unpopulated', 'mail-by-notification']],
    'notification' => [RecordType::NOTIFICATION, ['failed-flag-unpopulated']],
    'outgoing request' => [RecordType::OUTGOING_REQUEST, ['unanswered-outgoing-requests']],
    'queued job' => [RecordType::QUEUED_JOB, ['uninstrumented-dispatcher']],
]);

it('attaches each blind spot once, in catalogue order', function () {
    $ids = blindSpotIds([RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB, RecordType::REQUEST, RecordType::COMMAND]);

    expect($ids)->toBe(array_values(array_intersect(array_keys(BlindSpots::catalogue()), $ids)))
        ->and($ids)->toBe(array_values(array_unique($ids)));
});

it('attaches nothing when no type was examined', function () {
    expect(blindSpotIds([], actor: true, anchored: true, storeLevel: true))->toBe([]);
});

it('attaches the blind spots of how the call read the records, for the types it examined', function (array $flags, array $ids) {
    expect(blindSpotIds([RecordType::LOG], ...$flags))->toBe($ids);
})->with([
    'a read by actor' => [['actor' => true], ['named-log-channels', 'actor-partial']],
    'a read anchored in time' => [['anchored' => true], ['named-log-channels', 'visible-at-completion']],
    'a store-level answer' => [['storeLevel' => true], ['named-log-channels', 'application-opt-outs', 'values-truncated']],
]);

it('gives each blind spot its kind and sentence', function () {
    expect(BlindSpots::for([RecordType::MAIL]))->toBe([
        ['id' => 'failed-flag-unpopulated', 'kind' => 'structural', 'message' => 'The failed flag on mail and notification records is always false; failures are not recorded.'],
        ['id' => 'mail-by-notification', 'kind' => 'structural', 'message' => 'Mail sent by a notification is recorded as a notification, not as mail.'],
    ]);
});
