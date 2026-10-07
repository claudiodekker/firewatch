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
     * The reason of a start that is the creation of the store.
     */
    protected const CREATED = 'created';

    /**
     * Create a new history instance.
     *
     * @param  string|null  $reason  created, cleared, cleared-type, pruned-age, pruned-cap or pruned-size
     */
    public function __construct(
        public readonly ?float $from,
        public readonly ?string $reason,
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
                if ($reason !== self::CREATED && ($latest === null || $from > $latest)) {
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
     * @return list<array{float, string}>
     */
    protected static function starts(Markers $meta, RecordType $type): array
    {
        $candidates = [
            [$meta->clearedAt, 'cleared'],
            [$meta->clearedAtOf($type), 'cleared-type'],
            [$meta->prunedThrough, 'pruned-'.$meta->prunedReason?->value],
            [$meta->createdAt, self::CREATED],
        ];

        return array_values(array_filter($candidates, fn (array $candidate) => $candidate[0] !== null));
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
            'reason' => $this->reason,
            'retention' => [
                'age_seconds' => $this->retentionAge,
                'records' => $this->retentionRecords,
            ],
        ];
    }
}
