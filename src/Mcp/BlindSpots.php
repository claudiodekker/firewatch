<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\QueryBindings;
use ClaudioDekker\Firewatch\Capture\Truncator;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class BlindSpots
{
    /**
     * Get the record types each structural blind spot is about, or the fact about a call it depends on, by id in catalogue order.
     *
     * @return array<string, list<RecordType>|CallFact>
     */
    protected static function attachesTo(): array
    {
        return [
            'console-requests' => [RecordType::REQUEST],
            'unanswered-outgoing-requests' => [RecordType::OUTGOING_REQUEST],
            'payload-on-server-error-only' => [RecordType::REQUEST],
            'unrendered-validation-failures' => [RecordType::REQUEST],
            'dead-counters' => ExecutionType::records(),
            'failed-flag-unpopulated' => [RecordType::MAIL, RecordType::NOTIFICATION],
            'mail-by-notification' => [RecordType::MAIL],
            'sync-jobs-unrecorded' => [RecordType::JOB_ATTEMPT],
            'vendor-defaults-unrecorded' => [RecordType::COMMAND, RecordType::CACHE_EVENT],
            'exceptions-unreported' => [RecordType::EXCEPTION],
            'named-log-channels' => [RecordType::LOG],
            'memory-is-process-peak' => ExecutionType::records(),
            'query-bindings-unpaired' => [RecordType::QUERY],
            'uninstrumented-dispatcher' => [RecordType::JOB_ATTEMPT, RecordType::QUEUED_JOB],
            'actor-partial' => CallFact::ACTOR,
            'visible-at-completion' => CallFact::ANCHORED,
            'application-opt-outs' => CallFact::STORE_LEVEL,
            'values-truncated' => CallFact::STORE_LEVEL,
            'octane-bootstrap' => [RecordType::REQUEST],
            'compiled-view-templates' => CallFact::COMPILED_VIEW,
        ];
    }

    /**
     * Get the sentence of every structural blind spot by its id, in catalogue order.
     *
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (array_keys(self::attachesTo()) as $id) {
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
     * Get the blind spots of the record types a call examined.
     *
     * @param  list<RecordType>  $types
     * @param  list<CallFact>  $facts
     * @return list<array{id: string, kind: string, message: string}>
     */
    public static function for(array $types, array $facts = []): array
    {
        $blindSpots = [];

        foreach (self::attachesTo() as $id => $attaches) {
            $applies = $attaches instanceof CallFact
                ? $types !== [] && in_array($attaches, $facts, true)
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
