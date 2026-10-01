<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class History
{
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
     * Get the history the store's markers state for the record types a call read: it is complete from the latest start among them.
     *
     * A type starts at the latest of the store's creation, its last clear, a clear of the type and the instant records were pruned through, and the reason is the one that is latest. A tie goes to a clear, then a clear of the type, then a prune, then the creation.
     *
     * @param  array<string, string>  $meta
     * @param  list<RecordType>  $types
     */
    public static function of(array $meta, array $types, ?int $retentionAge = null, ?int $retentionRecords = null): self
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
     * Get the history of a store that can't be read: it states no start.
     */
    public static function unknown(?int $retentionAge, ?int $retentionRecords): self
    {
        return new self(null, null, $retentionAge, $retentionRecords);
    }

    /**
     * Get the starts a type's markers give, in the order a tie is settled.
     *
     * @param  array<string, string>  $meta
     * @return list<array{float, string}>
     */
    protected static function starts(array $meta, RecordType $type): array
    {
        $cleared = json_decode($meta['cleared_types'] ?? '', associative: true);

        $candidates = [
            [self::instant($meta['cleared_at'] ?? null), 'cleared'],
            [self::instant(is_array($cleared) ? $cleared[$type->value] ?? null : null), 'cleared-type'],
            [self::instant($meta['pruned_through'] ?? null), 'pruned-'.($meta['pruned_by'] ?? 'age')],
            [self::instant($meta['created_at'] ?? null), 'created'],
        ];

        return array_values(array_filter($candidates, fn (array $candidate) => $candidate[0] !== null));
    }

    /**
     * Read a marker as an instant, or null for one that is absent or not a number.
     */
    protected static function instant(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
            'retention' => ['age_seconds' => $this->retentionAge, 'records' => $this->retentionRecords],
        ];
    }
}
