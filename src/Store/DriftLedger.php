<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Capture\Drift;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class DriftLedger
{
    /**
     * The most drift rows kept apart.
     */
    protected const ROWS = 500;

    /**
     * The detail of the row a kind's drift beyond the cap is folded into.
     */
    protected const OVERFLOW = '... [overflow]';

    /**
     * The statement that adds a batch's count of one drift.
     */
    protected const UPSERT = <<<'SQL'
        INSERT INTO drift (kind, type, v, detail, count, first_seen, last_seen)
        VALUES (:kind, :type, :v, :detail, :count, :seen_at, :seen_at)
        ON CONFLICT (kind, type, v, detail) DO UPDATE SET
            count = count + excluded.count,
            last_seen = excluded.last_seen
        SQL;

    /**
     * Add a batch's drift to the ledger, folding each new row beyond the cap into its kind's overflow row.
     */
    public static function record(SQLite3 $connection, Drift $drift, float $seenAt): void
    {
        $counted = $drift->all();

        if ($counted === []) {
            return;
        }

        $kept = self::kept($connection);
        $rows = [];

        foreach ($counted as $occurrence) {
            $key = self::key($occurrence);

            if (! isset($kept[$key]) && count($kept) >= self::ROWS) {
                $occurrence = [
                    ...$occurrence,
                    'type' => '',
                    'v' => '',
                    'detail' => self::OVERFLOW,
                ];
                $key = self::key($occurrence);
            } else {
                $kept[$key] = true;
            }

            $rows[$key] ??= [
                ...$occurrence,
                'count' => 0,
                'seen_at' => $seenAt,
            ];
            $rows[$key]['count'] += $occurrence['count'];
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare(self::UPSERT);

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $statement->bindValue(':'.$column, $value);
            }

            $statement->execute();
            $statement->reset();
        }

        $statement->close();
    }

    /**
     * Summarise the ledger by type and kind, with the count and the last sighting of each.
     *
     * @param  bool  $typed  whether to leave out the drift of the store itself, which names no type
     * @return list<array{kind: string, type: string, count: int, last_seen_at: float}>
     */
    public static function summary(SQLite3 $connection, bool $typed = false): array
    {
        $where = $typed ? "WHERE type != ''" : '';

        return array_map(fn (array $row) => [
            'kind' => (string) $row['kind'],
            'type' => (string) $row['type'],
            'count' => Cell::integer($row['count']),
            'last_seen_at' => Cell::float($row['last_seen']),
        ], Fetch::rows($connection, "SELECT kind, type, sum(count) AS count, max(last_seen) AS last_seen FROM drift {$where} GROUP BY type, kind ORDER BY type, kind"));
    }

    /**
     * Delete the drift last seen before the instant, and count it.
     */
    public static function deleteSeenBefore(SQLite3 $connection, float $instant): int
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('DELETE FROM drift WHERE last_seen < :instant');

        $statement->bindValue(':instant', $instant, SQLITE3_FLOAT);
        $statement->execute();

        return $connection->changes();
    }

    /**
     * Get the keys of the rows the ledger keeps apart, beside the overflow rows.
     *
     * @return array<string, true>
     */
    protected static function kept(SQLite3 $connection): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT kind, type, v, detail FROM drift WHERE detail <> :overflow');

        $statement->bindValue(':overflow', self::OVERFLOW);

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $kept = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $kept[self::key($row)] = true;
        }

        $statement->close();

        return $kept;
    }

    /**
     * Get the key a row is kept under.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function key(array $row): string
    {
        return implode("\0", [$row['kind'], $row['type'], $row['v'], $row['detail']]);
    }
}
