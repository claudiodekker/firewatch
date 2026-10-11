<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\DriftLedger;
use ClaudioDekker\Firewatch\Store\History;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class StoreFacts
{
    /**
     * Create a new store facts instance.
     *
     * @param  list<array{kind: string, type: string, count: int, last_seen: float}>  $drift
     */
    public function __construct(
        public readonly Markers $meta,
        public readonly array $drift = [],
        public readonly int $records = 0,
        public readonly ?float $oldest = null,
        public readonly ?float $newest = null,
    ) {
        //
    }

    /**
     * Read the facts of the store's markers, drift and span, in the snapshot of the connection.
     */
    public static function read(SQLite3 $connection): self
    {
        $span = Stored::rows($connection, 'SELECT count(*) AS records, min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];

        return new self(
            meta: Markers::read($connection),
            drift: array_map(fn (array $row) => [
                'kind' => $row['kind'],
                'type' => $row['type'],
                'count' => $row['count'],
                'last_seen' => $row['last_seen_at'],
            ], DriftLedger::summary($connection, typed: true)),
            records: is_int($span['records']) ? $span['records'] : 0,
            oldest: is_float($span['oldest']) ? $span['oldest'] : null,
            newest: is_float($span['newest']) ? $span['newest'] : null,
        );
    }

    /**
     * Get the instant from which the store's history of the record types is complete, or null when its markers state none.
     *
     * @param  list<RecordType>  $types
     */
    public function historyFrom(array $types): ?float
    {
        return History::of($this->meta, $types)->from;
    }
}
