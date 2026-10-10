<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;

/**
 * @internal
 */
class History
{
    /**
     * Create a new history instance.
     */
    public function __construct(
        public readonly ?float $from,
        public readonly ?HistoryReason $reason,
        public readonly ?int $retentionAge,
        public readonly ?int $retentionRecords,
    ) {
        //
    }

    /**
     * Get the history the store's markers state for the record types a call read, complete from the latest start among them.
     *
     * @param  list<RecordType>  $types
     */
    public static function of(Markers $meta, array $types, ?int $retentionAge = null, ?int $retentionRecords = null): self
    {
        $latest = null;

        foreach ($types as $type) {
            foreach (self::starts($meta, $type) as [$from, $reason]) {
                if ($latest === null || $from > $latest[0]) {
                    $latest = [$from, $reason];
                }
            }
        }

        return new self($latest[0] ?? null, $latest[1] ?? null, $retentionAge, $retentionRecords);
    }

    /**
     * Get the instant through which a clear or a prune removed records of the types, or null when none did.
     *
     * @param  list<RecordType>  $types
     */
    public static function removedThrough(Markers $meta, array $types): ?float
    {
        $latest = null;

        foreach ($types as $type) {
            foreach (self::starts($meta, $type) as [$from, $reason]) {
                if ($reason !== HistoryReason::CREATED && ($latest === null || $from > $latest)) {
                    $latest = $from;
                }
            }
        }

        return $latest;
    }

    /**
     * Get the history of a store that can't be read.
     */
    public static function unknown(?int $retentionAge, ?int $retentionRecords): self
    {
        return new self(null, null, $retentionAge, $retentionRecords);
    }

    /**
     * Get the starts a type's markers give, in the order a tie is settled.
     *
     * @return list<array{float, HistoryReason}>
     */
    protected static function starts(Markers $meta, RecordType $type): array
    {
        $candidates = [
            [$meta->clearedAt, HistoryReason::CLEARED],
            [$meta->clearedAtOf($type), HistoryReason::CLEARED_TYPE],
            [$meta->prunedThrough, $meta->prunedReason === null ? null : HistoryReason::pruned($meta->prunedReason)],
            [$meta->createdAt, HistoryReason::CREATED],
        ];

        return array_values(array_filter($candidates, fn (array $candidate) => $candidate[0] !== null && $candidate[1] !== null));
    }

    /**
     * Get the history as the `history` key of the envelope's coverage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'reason' => $this->reason?->value,
            'retention' => [
                'age_seconds' => $this->retentionAge,
                'records' => $this->retentionRecords,
            ],
        ];
    }
}
