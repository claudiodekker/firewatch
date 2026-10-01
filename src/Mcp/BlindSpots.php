<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class BlindSpots
{
    /**
     * The four execution types.
     *
     * @var list<RecordType>
     */
    protected const EXECUTIONS = [RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK];

    /**
     * The record types each structural blind spot is about, or the way of reading it is about, by id in catalogue order.
     *
     * @var array<string, list<RecordType>|string>
     */
    protected const ATTACHES_TO = [
        'console-requests' => [RecordType::REQUEST],
        'unanswered-outgoing-requests' => [RecordType::OUTGOING_REQUEST],
        'payload-on-server-error-only' => [RecordType::REQUEST],
        'dead-counters' => self::EXECUTIONS,
        'failed-flag-unpopulated' => [RecordType::MAIL, RecordType::NOTIFICATION],
        'mail-by-notification' => [RecordType::MAIL],
        'sync-jobs-unrecorded' => [RecordType::JOB_ATTEMPT],
        'vendor-defaults-unrecorded' => [RecordType::COMMAND, RecordType::CACHE_EVENT],
        'exceptions-unreported' => [RecordType::EXCEPTION],
        'named-log-channels' => [RecordType::LOG],
        'memory-is-process-peak' => self::EXECUTIONS,
        'query-bindings-unpaired' => [RecordType::QUERY],
        'uninstrumented-dispatcher' => [RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB],
        'actor-partial' => 'actor',
        'visible-at-completion' => 'anchored',
        'application-opt-outs' => 'storeLevel',
        'values-truncated' => 'storeLevel',
        'octane-bootstrap' => [RecordType::REQUEST],
    ];

    /**
     * Get the closed catalogue: every structural blind spot's id and its sentence, in catalogue order.
     *
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (array_keys(self::ATTACHES_TO) as $id) {
            $catalogue[$id] = __("firewatch::messages.blind_spots.{$id}");
        }

        return $catalogue;
    }

    /**
     * Get the blind spots of the record types a call examined, whether or not it found any, so an empty answer carries them.
     *
     * The three flags attach what depends on how the call read the records, for the types it examined: by actor, anchored on the latest execution, a split or a trend, or about the whole store.
     *
     * @param  list<RecordType>  $types
     * @return list<array{id: string, kind: string, message: string}>
     */
    public static function for(array $types, bool $actor = false, bool $anchored = false, bool $storeLevel = false): array
    {
        $flags = ['actor' => $actor, 'anchored' => $anchored, 'storeLevel' => $storeLevel];
        $blindSpots = [];

        foreach (self::ATTACHES_TO as $id => $attaches) {
            $applies = is_string($attaches)
                ? $types !== [] && $flags[$attaches]
                : array_filter($attaches, fn (RecordType $type) => in_array($type, $types, true)) !== [];

            if ($applies) {
                $blindSpots[] = ['id' => $id, 'kind' => 'structural', 'message' => __("firewatch::messages.blind_spots.{$id}")];
            }
        }

        return $blindSpots;
    }
}
