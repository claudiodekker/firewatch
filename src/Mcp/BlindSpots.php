<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\QueryBindings;
use ClaudioDekker\Firewatch\Capture\Truncator;
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
            $catalogue[$id] = self::sentence($id);
        }

        return $catalogue;
    }

    /**
     * Get the sentence of a structural blind spot, with the limits it states.
     */
    protected static function sentence(string $id): string
    {
        return __("firewatch::messages.blind_spots.{$id}", [
            'field_bytes' => number_format(Truncator::FIELD_LIMIT_BYTES),
            'bindings_bytes' => number_format(QueryBindings::TOTAL_LIMIT_BYTES),
            'characters' => number_format(Bounds::CELL_CHARACTERS),
        ]);
    }

    /**
     * Get the blind spots of the record types a call examined, found or not, so an empty answer carries them.
     *
     * @param  list<RecordType>  $types
     * @param  bool  $actor  attach what depends on reading by actor
     * @param  bool  $anchored  attach what depends on anchoring on the latest execution, a split or a trend
     * @param  bool  $storeLevel  attach what is about the whole store
     * @return list<array{id: string, kind: string, message: string}>
     */
    public static function for(array $types, bool $actor = false, bool $anchored = false, bool $storeLevel = false): array
    {
        $flags = [
            'actor' => $actor,
            'anchored' => $anchored,
            'storeLevel' => $storeLevel,
        ];
        $blindSpots = [];

        foreach (self::ATTACHES_TO as $id => $attaches) {
            $applies = is_string($attaches)
                ? $types !== [] && $flags[$attaches]
                : array_filter($attaches, fn (RecordType $type) => in_array($type, $types, true)) !== [];

            if ($applies) {
                $blindSpots[] = [
                    'id' => $id,
                    'kind' => BlindSpotKind::STRUCTURAL->value,
                    'message' => self::sentence($id),
                ];
            }
        }

        return $blindSpots;
    }
}
