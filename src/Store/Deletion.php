<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\RecordType;
use Closure;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Deletion
{
    /**
     * The most records one delete transaction removes.
     */
    public const CHUNK_ROWS = 2000;

    /**
     * The pages one incremental vacuum frees.
     */
    public const RECLAIM_PAGES = 1024;

    /**
     * Create a new store deletion instance.
     */
    public function __construct(
        protected Writer $writer,
        protected int $chunkRows = self::CHUNK_ROWS,
        protected int $reclaimPages = self::RECLAIM_PAGES,
    ) {
        //
    }

    /**
     * Prune one chunk of the oldest records that match, and get how many matched.
     *
     * @param  array<string, float>  $bindings
     */
    public function pruneChunk(string $condition, array $bindings, int $limit, PruneReason $reason): int
    {
        return $this->deleteChunk(
            function (SQLite3 $connection) use ($condition, $bindings, $limit, $reason) {
                [$removed, $newest] = $this->deleteOldestStarted($connection, $condition, $bindings, $limit);

                if ($removed < $limit && $reason->countsUnstarted()) {
                    $removed += $this->deleteUnstarted($connection, $limit - $removed);
                }

                return [$removed, $newest];
            },
            function (SQLite3 $connection, ?float $newest) use ($reason) {
                if ($newest !== null) {
                    Markers::advancePrunedThrough($connection, $newest, $reason);
                }
            },
        );
    }

    /**
     * Clear one chunk of the records up to an id, of one type or of every type, and get how many there were.
     */
    public function clearChunk(int $through, ?RecordType $type, float $clearedAt): int
    {
        return $this->deleteChunk(
            function (SQLite3 $connection) use ($through, $type) {
                /** @var SQLite3Stmt $statement */
                $statement = $connection->prepare('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE id <= :through AND (:type IS NULL OR type = :type) ORDER BY id LIMIT :limit)');

                $statement->bindValue(':through', $through, SQLITE3_INTEGER);
                $statement->bindValue(':type', $type?->value, $type === null ? SQLITE3_NULL : SQLITE3_TEXT);
                $statement->bindValue(':limit', $this->chunkRows, SQLITE3_INTEGER);
                $statement->execute();

                return [$connection->changes(), null];
            },
            function (SQLite3 $connection) use ($type, $clearedAt) {
                if ($type === null) {
                    Markers::markCleared($connection, $clearedAt);
                } else {
                    Markers::markTypeCleared($connection, $type, $clearedAt);
                }
            },
        );
    }

    /**
     * Give up to the reclaim page count of freed pages back to the file.
     */
    public function reclaim(): void
    {
        $this->writer->transaction(fn (SQLite3 $connection) => $connection->exec('PRAGMA incremental_vacuum('.$this->reclaimPages.')'));
    }

    /**
     * Give every freed page back to the file.
     */
    public function reclaimAll(): void
    {
        $freePages = Cell::integer($this->writer->transaction(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA freelist_count')));

        for ($steps = intdiv($freePages + $this->reclaimPages - 1, $this->reclaimPages); $steps > 0; $steps--) {
            $this->reclaim();
        }
    }

    /**
     * Run the deletes of one chunk and write the coverage marker that accounts for them in one transaction, and get how many records it counts.
     *
     * @param  Closure(SQLite3): array{int, ?float}  $delete  deletes the chunk, and gets how many records it counts and the latest start among them
     * @param  Closure(SQLite3, ?float): void  $marker
     */
    protected function deleteChunk(Closure $delete, Closure $marker): int
    {
        return $this->writer->transaction(function (SQLite3 $connection) use ($delete, $marker) {
            [$removed, $newest] = $delete($connection);

            $marker($connection, $newest);

            return $removed;
        });
    }

    /**
     * Delete the oldest records of known start that match, and get how many matched and the latest start among them.
     *
     * @param  array<string, float>  $bindings
     * @return array{int, ?float}
     */
    protected function deleteOldestStarted(SQLite3 $connection, string $condition, array $bindings, int $limit): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("SELECT id, started_at FROM records WHERE started_at IS NOT NULL AND {$condition} ORDER BY started_at, id LIMIT :limit");
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);

        foreach ($bindings as $name => $value) {
            $statement->bindValue(":{$name}", $value, SQLITE3_FLOAT);
        }

        $result = $statement->execute();
        $ids = [];
        $newest = null;

        /** @var SQLite3Result $result */
        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $ids[] = Cell::integer($row[0]);
            $newest = Cell::float($row[1]);
        }

        if ($ids === []) {
            return [0, null];
        }

        $this->deleteIds($connection, $ids);
        $this->deleteUnstartedBelow($connection, max($ids));

        return [count($ids), $newest];
    }

    /**
     * Delete the records with the given ids.
     *
     * @param  non-empty-list<int>  $ids
     */
    protected function deleteIds(SQLite3 $connection, array $ids): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("DELETE FROM records WHERE id IN ({$placeholders})");

        foreach ($ids as $position => $id) {
            $statement->bindValue($position + 1, $id, SQLITE3_INTEGER);
        }

        $statement->execute();
    }

    /**
     * Delete a chunk of the records without a start that arrived before an id.
     */
    protected function deleteUnstartedBelow(SQLite3 $connection, int $id): void
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE started_at IS NULL AND id < :id ORDER BY id LIMIT :limit)');
        $statement->bindValue(':id', $id, SQLITE3_INTEGER);
        $statement->bindValue(':limit', $this->chunkRows, SQLITE3_INTEGER);
        $statement->execute();
    }

    /**
     * Delete the records of unknown start that arrived first, up to a limit, and get how many there were.
     */
    protected function deleteUnstarted(SQLite3 $connection, int $limit): int
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE started_at IS NULL ORDER BY id LIMIT :limit)');
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $statement->execute();

        return $connection->changes();
    }
}
