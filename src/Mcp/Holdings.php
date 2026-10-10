<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class Holdings
{
    /**
     * The most deploys an answer lists, newest first.
     */
    public const DEPLOYS = 20;

    /**
     * Create a new holdings instance.
     *
     * @param  array<string, array{records: int, oldest: float|null, newest: float|null}>  $types  by type value, every stored type, known or not
     * @param  list<array{deploy: string, first_seen_at: float, last_seen_at: float, records: int}>  $deploys  newest last seen first
     * @param  list<array{kind: string, type: string, count: int, last_seen_at: float}>  $drift  every drift row, a store-level one with an empty type included
     */
    public function __construct(
        public readonly int $records,
        public readonly ?float $oldest,
        public readonly ?float $newest,
        public readonly int $liveBytes,
        public readonly array $drift,
        protected array $types,
        protected array $deploys,
        protected int $people,
    ) {
        //
    }

    /**
     * Read the counts and span of each type, the newest deploys and the live pages, in the snapshot of the connection.
     */
    public static function read(SQLite3 $connection): self
    {
        $types = [];

        foreach (Stored::rows($connection, 'SELECT type, count(*) AS records, min(started_at) AS oldest, max(started_at) AS newest FROM records GROUP BY type') as $row) {
            $types[(string) $row['type']] = [
                'records' => Cell::integer($row['records']),
                'oldest' => is_float($row['oldest']) ? $row['oldest'] : null,
                'newest' => is_float($row['newest']) ? $row['newest'] : null,
            ];
        }

        $deploys = array_map(fn (array $row) => [
            'deploy' => (string) $row['deploy'],
            'first_seen_at' => Cell::float($row['first']),
            'last_seen_at' => Cell::float($row['last']),
            'records' => Cell::integer($row['records']),
        ], Stored::rows($connection, "SELECT deploy, min(started_at) AS first, max(started_at) AS last, count(*) AS records FROM records WHERE deploy IS NOT NULL AND deploy != '' GROUP BY deploy ORDER BY last DESC LIMIT ".(self::DEPLOYS + 1)));

        $drift = array_map(fn (array $row) => [
            'kind' => (string) $row['kind'],
            'type' => (string) $row['type'],
            'count' => Cell::integer($row['count']),
            'last_seen_at' => Cell::float($row['last_seen']),
        ], Stored::rows($connection, 'SELECT kind, type, sum(count) AS count, max(last_seen) AS last_seen FROM drift GROUP BY type, kind ORDER BY type, kind'));

        $people = Cell::integer($connection->querySingle('SELECT count(*) FROM users'));
        $totalPages = Cell::integer($connection->querySingle('PRAGMA page_count'));
        $freePages = Cell::integer($connection->querySingle('PRAGMA freelist_count'));
        $pageSizeBytes = Cell::integer($connection->querySingle('PRAGMA page_size'));

        $oldest = array_filter(array_column($types, 'oldest'), is_float(...));
        $newest = array_filter(array_column($types, 'newest'), is_float(...));

        return new self(
            records: array_sum(array_column($types, 'records')),
            oldest: $oldest === [] ? null : min($oldest),
            newest: $newest === [] ? null : max($newest),
            liveBytes: ($totalPages - $freePages) * $pageSizeBytes,
            drift: $drift,
            types: $types,
            deploys: $deploys,
            people: $people,
        );
    }

    /**
     * Get the records stored of one type, or the people in the directory for the user.
     */
    public function of(RecordType $type): int
    {
        return $type === RecordType::USER ? $this->people : $this->types[$type->value]['records'] ?? 0;
    }

    /**
     * Get one row per event type with its span and the instant its history is complete from, then the deploys listed.
     *
     * @return array{types: list<array<string, mixed>>, deploys: list<array{deploy: string, first_seen_at: float, last_seen_at: float, records: int}>}
     */
    public function result(Markers $meta): array
    {
        $types = array_map(function (RecordType $type) use ($meta) {
            $history = History::of($meta, [$type]);

            return [
                'type' => $type->value,
                'object' => Catalogue::object($type),
                'records' => $this->of($type),
                'oldest_at' => $this->types[$type->value]['oldest'] ?? null,
                'newest_at' => $this->types[$type->value]['newest'] ?? null,
                'complete_from_at' => $history->from,
                'complete_reason' => $history->reason?->value,
            ];
        }, RecordType::events());

        return [
            'types' => $types,
            'deploys' => array_slice($this->deploys, 0, self::DEPLOYS),
        ];
    }

    /**
     * Get the `truncated` entry of the deploys the limit cut, or null when every deploy is listed.
     *
     * @return array{section: string, shown: int, matched: int|null, reason: string, how: string}|null
     */
    public function truncation(): ?array
    {
        if (count($this->deploys) <= self::DEPLOYS) {
            return null;
        }

        return [
            'section' => 'deploys',
            'shown' => self::DEPLOYS,
            'matched' => null,
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.describe_deploys_how'),
        ];
    }

    /**
     * Get the types stored that are no record type of the contract.
     *
     * @return list<string>
     */
    public function unknownTypes(): array
    {
        return array_values(array_filter(array_keys($this->types), fn (string $type) => RecordType::tryFrom($type) === null));
    }
}
