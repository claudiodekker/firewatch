<?php

namespace ClaudioDekker\Firewatch\Store;

use SQLite3;
use SQLite3Stmt;

/**
 * @internal
 */
class UserDirectory
{
    /**
     * The statement that adds a user to the directory, or refreshes one it holds.
     */
    protected const UPSERT = <<<'SQL'
        INSERT INTO users (id, worker, name, username, first_seen, last_seen)
        VALUES (:id, :worker, :name, :username, :seen_at, :seen_at)
        ON CONFLICT (id, coalesce(worker, '')) DO UPDATE SET
            name = excluded.name,
            username = excluded.username,
            last_seen = max(coalesce(last_seen, excluded.last_seen), coalesce(excluded.last_seen, last_seen))
        SQL;

    /**
     * Add the users to the directory, or refresh the ones it holds.
     *
     * @param  list<array<string, mixed>>  $users  each with an id, worker, name, username and seen_at
     */
    public static function upsert(SQLite3 $connection, array $users): void
    {
        if ($users === []) {
            return;
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare(self::UPSERT);

        foreach ($users as $user) {
            foreach ($user as $column => $value) {
                $statement->bindValue(':'.$column, $value);
            }

            $statement->execute();
            $statement->reset();
        }

        $statement->close();
    }

    /**
     * Count the people in the directory.
     */
    public static function count(SQLite3 $connection): int
    {
        return Cell::integer($connection->querySingle('SELECT count(*) FROM users'));
    }

    /**
     * List the people of the directory, or those a condition on its columns keeps, newest sighting first, with how many the condition keeps in all.
     *
     * @param  array<string, string|int>  $bindings
     * @return array{list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>, int}
     */
    public static function list(SQLite3 $connection, int $limit, ?string $condition = null, array $bindings = []): array
    {
        $where = $condition === null ? '' : "WHERE {$condition}";

        $bindings['limit'] = $limit;

        /** @var list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null, matched: int}> $rows */
        $rows = Fetch::rows($connection, "SELECT id, name, username, first_seen, last_seen, count(*) OVER () AS matched
            FROM users {$where} ORDER BY last_seen DESC, id LIMIT :limit", $bindings);

        $people = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'username' => $row['username'],
            'first_seen' => $row['first_seen'],
            'last_seen' => $row['last_seen'],
        ], $rows);

        return [$people, $rows[0]['matched'] ?? 0];
    }

    /**
     * Delete the people last seen up to the instant, and count them.
     */
    public static function deleteSeenThrough(SQLite3 $connection, float $instant): int
    {
        return self::delete($connection, 'last_seen <= :instant', $instant);
    }

    /**
     * Delete the people last seen before the instant, and count them.
     */
    public static function deleteSeenBefore(SQLite3 $connection, float $instant): int
    {
        return self::delete($connection, 'last_seen < :instant', $instant);
    }

    /**
     * Delete the people a condition on their last sighting keeps, and count them.
     */
    protected static function delete(SQLite3 $connection, string $condition, float $instant): int
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("DELETE FROM users WHERE {$condition}");

        $statement->bindValue(':instant', $instant, SQLITE3_FLOAT);
        $statement->execute();

        return $connection->changes();
    }
}
