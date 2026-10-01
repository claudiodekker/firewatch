<?php

namespace ClaudioDekker\Firewatch\Mcp;

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
     * @param  array<string, string>  $meta  the store's markers by key
     * @param  list<array{kind: string, type: string, count: int, last_seen: float}>  $drift
     */
    public function __construct(
        public readonly array $meta,
        public readonly array $drift = [],
    ) {
        //
    }

    /**
     * Read the facts of the store's markers and drift, in the snapshot of the connection.
     */
    public static function read(SQLite3 $connection): self
    {
        /** @var SQLite3Result $result */
        $result = $connection->query('SELECT key, value FROM meta');
        $meta = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $meta[(string) $row[0]] = (string) $row[1];
        }

        /** @var SQLite3Result $result */
        $result = $connection->query('SELECT kind, type, sum(count), max(last_seen) FROM drift WHERE type != \'\' GROUP BY type, kind ORDER BY type, kind');
        $drift = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $drift[] = ['kind' => (string) $row[0], 'type' => (string) $row[1], 'count' => (int) $row[2], 'last_seen' => (float) $row[3]];
        }

        return new self($meta, $drift);
    }
}
