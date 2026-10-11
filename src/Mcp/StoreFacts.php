<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;
use SQLite3Result;

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

        /** @var SQLite3Result $result */
        $result = $connection->query('SELECT kind, type, sum(count), max(last_seen) FROM drift WHERE type != \'\' GROUP BY type, kind ORDER BY type, kind');
        $drift = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $drift[] = [
                'kind' => (string) $row[0],
                'type' => (string) $row[1],
                'count' => (int) $row[2],
                'last_seen' => (float) $row[3],
            ];
        }

        return new self(
            meta: Markers::read($connection),
            drift: $drift,
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
