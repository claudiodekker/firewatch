<?php

namespace ClaudioDekker\Firewatch\Mcp;

use SQLite3;

/**
 * @internal
 */
class Identification
{
    /**
     * The most people an answer lists.
     */
    protected const LISTED = 10;

    /**
     * Create a new identification instance.
     *
     * @param  Rows<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $known  the people of the user directory, newest sighting first
     * @param  int  $knownCount  all the people of the user directory, of whom the most recently seen are listed
     */
    protected function __construct(
        public readonly Rows $known,
        public readonly int $knownCount,
    ) {
        //
    }

    /**
     * Resolve who is meant, in the snapshot of the connection.
     */
    public static function of(SQLite3 $connection, string $who): self
    {
        [$known, $count] = self::people($connection);

        return new self(Rows::bound($known, self::LISTED), $count);
    }

    /**
     * Determine if the user directory holds no one.
     */
    public function knowsNoOne(): bool
    {
        return $this->knownCount === 0;
    }

    /**
     * Read the people of the user directory, newest sighting first and one more than is listed, and count them all.
     *
     * @return array{list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>, int}
     */
    protected static function people(SQLite3 $connection): array
    {
        /** @var list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null, matched: int}> $rows */
        $rows = Stored::rows($connection, 'SELECT id, name, username, first_seen, last_seen, count(*) OVER () AS matched
            FROM users ORDER BY last_seen DESC, id LIMIT :limit', [
            'limit' => Rows::fetch(self::LISTED),
        ]);

        $people = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'username' => $row['username'],
            'first_seen' => $row['first_seen'],
            'last_seen' => $row['last_seen'],
        ], $rows);

        return [$people, $rows[0]['matched'] ?? 0];
    }
}
