<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Instant;
use Closure;
use SQLite3;
use SQLite3Exception;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Pruner
{
    /**
     * The seconds that must pass between two passes, across all processes.
     */
    protected const CLAIM_SECONDS = 60;

    /**
     * The most records one delete transaction removes.
     */
    protected const CHUNK_ROWS = 2000;

    /**
     * The most delete transactions one pass makes.
     */
    protected const PASS_TRANSACTIONS = 20;

    /**
     * The share of the record cap a trim leaves, so the cap does not creep on every pass.
     */
    protected const TRIM_TO = 0.9;

    /**
     * The pages one incremental vacuum frees, and the freelist length above which an idle pass reclaims.
     */
    protected const RECLAIM_PAGES = 1024;

    /**
     * Create a new pruner instance.
     */
    public function __construct(
        protected Writer $writer,
        protected Reader $reader,
        protected Configuration $configuration,
    ) {
        //
    }

    /**
     * Run a pass after a batch was stored, if no process ran one in the last minute: a busy store ends it silently.
     *
     * @throws SQLite3Exception|StoreFailure for a step that fails for any reason but the store being busy
     */
    public function run(): void
    {
        $now = Instant::now();

        $this->unlessBusy(function () use ($now) {
            if ($this->claim($now)) {
                $this->prune($now);
            }
        });
    }

    /**
     * Run a pass at once for a store that is full, whichever process claimed the minute, as no batch succeeds to start one: a busy store ends it silently.
     *
     * @throws SQLite3Exception|StoreFailure for a step that fails for any reason but the store being busy
     */
    public function runNow(): void
    {
        $this->unlessBusy(fn () => $this->prune(Instant::now()));
    }

    /**
     * Run the steps of a pass, ending it silently when the store is busy.
     *
     * @param  Closure(): void  $steps
     *
     * @throws SQLite3Exception|StoreFailure for a step that fails for any reason but the store being busy
     */
    protected function unlessBusy(Closure $steps): void
    {
        try {
            $steps();
        } catch (SQLite3Exception|StoreFailure $exception) {
            if (FailureKind::of($exception) !== FailureKind::BUSY) {
                throw $exception;
            }
        }
    }

    /**
     * Get the most records one delete transaction removes.
     */
    protected function chunkRows(): int
    {
        return static::CHUNK_ROWS;
    }

    /**
     * Get the most delete transactions one pass makes.
     */
    protected function passTransactions(): int
    {
        return static::PASS_TRANSACTIONS;
    }

    /**
     * Get the live bytes past which a pass trims the oldest records.
     */
    protected function backstopBytes(): int
    {
        return Writer::SIZE_BACKSTOP_BYTES;
    }

    /**
     * Get the pages one incremental vacuum frees, and the freelist length above which an idle pass reclaims.
     */
    protected function reclaimPages(): int
    {
        return static::RECLAIM_PAGES;
    }

    /**
     * Claim the pass for this minute, or find that another process did.
     */
    protected function claim(float $now): bool
    {
        $last = $this->lastClaim();

        if ($last !== null && $now - $last < static::CLAIM_SECONDS) {
            return false;
        }

        return $this->writer->transaction(function (SQLite3 $connection) use ($now) {
            return Markers::claimPrune($connection, $now, static::CLAIM_SECONDS);
        });
    }

    /**
     * Read when the last pass was claimed, in a plain read that takes no write lock, or null when none was or the store can't be read.
     */
    protected function lastClaim(): ?float
    {
        try {
            return $this->reader->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->pruneClaimedAt);
        } catch (StoreUnusable) {
            return null;
        }
    }

    /**
     * Prune what is older than the retention age, then what is over the record cap, within the transactions one pass has.
     */
    protected function prune(float $now): void
    {
        $cutoff = $now - $this->configuration->retentionAgeSeconds;

        $deleted = false;

        foreach (['users', 'drift'] as $table) {
            $deleted = $this->writer->transaction(function (SQLite3 $connection) use ($table, $cutoff) {
                /** @var SQLite3Stmt $statement */
                $statement = $connection->prepare("DELETE FROM {$table} WHERE last_seen < :cutoff");
                $statement->bindValue(':cutoff', $cutoff, SQLITE3_FLOAT);
                $statement->execute();

                return $connection->changes() > 0;
            }) || $deleted;
        }

        $transactions = $this->passTransactions();

        while ($transactions > 0) {
            $removed = $this->chunk('started_at < :cutoff', ['cutoff' => $cutoff], $this->chunkRows(), PruneReason::AGE);
            $deleted = $deleted || $removed > 0;

            // A transaction that found nothing to delete does not count against the pass.
            $transactions -= $removed > 0 ? 1 : 0;

            if ($removed < $this->chunkRows()) {
                break;
            }
        }

        $count = $this->count();

        if ($count > $this->configuration->retentionRecords) {
            $excess = $count - (int) floor(static::TRIM_TO * $this->configuration->retentionRecords);

            while ($transactions > 0 && $excess > 0) {
                $transactions--;
                $limit = min($this->chunkRows(), $excess);
                $removed = $this->chunk('1 = 1', [], $limit, PruneReason::CAP);
                $excess -= $removed;
                $deleted = $deleted || $removed > 0;

                if ($removed < $limit) {
                    break;
                }
            }
        }

        $pages = $this->pages();
        $target = (int) floor(static::TRIM_TO * $this->backstopBytes());

        // Once over the backstop the trim goes on until the target, so the boundary does not creep.
        $over = $this->backstopBytes() < $pages['live'] * $pages['size'];

        while ($over && $transactions > 0) {
            $transactions--;

            // Freed pages land on the freelist at once, so the counts are read again after each committed chunk.
            if ($this->chunk('1 = 1', [], $this->chunkRows(), PruneReason::SIZE) === 0) {
                break;
            }

            $deleted = true;

            $pages = $this->pages();

            if ($target >= $pages['live'] * $pages['size']) {
                break;
            }
        }

        $this->reclaim($deleted);
    }

    /**
     * Give freed pages back to the file: a short vacuum when the pass deleted or the freelist is long, then, after a deleting pass, a passive checkpoint and an optimize, each best effort.
     */
    protected function reclaim(bool $deleted): void
    {
        if (! $deleted && $this->pages()['free'] <= $this->reclaimPages()) {
            return;
        }

        $this->writer->transaction(fn (SQLite3 $connection) => $connection->exec('PRAGMA incremental_vacuum('.$this->reclaimPages().')'));

        if (! $deleted) {
            return;
        }

        $this->writer->maintain(function (SQLite3 $connection) {
            foreach (['PRAGMA wal_checkpoint(PASSIVE)', 'PRAGMA optimize'] as $statement) {
                try {
                    $connection->exec($statement);
                } catch (SQLite3Exception) {
                    //
                }
            }
        });
    }

    /**
     * Read the store's live and free pages and its page size in one snapshot.
     *
     * @return array{live: int, free: int, size: int}
     */
    protected function pages(): array
    {
        return $this->reader->snapshot(function (SQLite3 $connection) {
            $totalPages = Cell::integer($connection->querySingle('PRAGMA page_count'));
            $freePages = Cell::integer($connection->querySingle('PRAGMA freelist_count'));
            $pageSizeBytes = Cell::integer($connection->querySingle('PRAGMA page_size'));

            return [
                'live' => $totalPages - $freePages,
                'free' => $freePages,
                'size' => $pageSizeBytes,
            ];
        });
    }

    /**
     * Count the records the store holds.
     */
    protected function count(): int
    {
        $count = $this->reader->snapshot(fn (SQLite3 $connection) => $connection->querySingle('SELECT count(*) FROM records'));

        return is_int($count) ? $count : 0;
    }

    /**
     * Delete the oldest records that match in one transaction, with the records of unknown start below them and the marker that says what was removed, and get how many matched.
     *
     * @param  array<string, float>  $bindings
     */
    protected function chunk(string $condition, array $bindings, int $limit, PruneReason $reason): int
    {
        return $this->writer->transaction(function (SQLite3 $connection) use ($condition, $bindings, $limit, $reason) {
            $removed = $this->deleteOldest($connection, $condition, $bindings, $limit, $reason);

            // A record of unknown start has no age, but it counts towards the cap and the size, after every record of known start.
            if ($removed < $limit && $reason->countsUnstarted()) {
                $removed += $this->deleteUnstarted($connection, $limit - $removed);
            }

            return $removed;
        });
    }

    /**
     * Delete the oldest records of known start that match, with the records of unknown start below them and the marker that says what was removed, and get how many matched.
     *
     * @param  array<string, float>  $bindings
     */
    protected function deleteOldest(SQLite3 $connection, string $condition, array $bindings, int $limit, PruneReason $reason): int
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("SELECT id, started_at FROM records WHERE started_at IS NOT NULL AND {$condition} ORDER BY started_at, id LIMIT :limit");
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);

        foreach ($bindings as $name => $value) {
            $statement->bindValue(":{$name}", $value, SQLITE3_FLOAT);
        }

        $result = $statement->execute();
        $ids = [];
        $newest = 0.0;

        /** @var SQLite3Result $result */
        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $ids[] = Cell::integer($row[0]);
            $newest = Cell::float($row[1]);
        }

        if ($ids === []) {
            return 0;
        }

        $this->deleteIds($connection, $ids);
        $this->deleteUnstartedBelow($connection, max($ids));

        Markers::advancePrunedThrough($connection, $newest, $reason);

        return count($ids);
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
     * Delete a chunk of the records without a start that arrived before an id, as they age with their neighbours by arrival.
     */
    protected function deleteUnstartedBelow(SQLite3 $connection, int $id): void
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE started_at IS NULL AND id < :id ORDER BY id LIMIT :limit)');
        $statement->bindValue(':id', $id, SQLITE3_INTEGER);
        $statement->bindValue(':limit', $this->chunkRows(), SQLITE3_INTEGER);
        $statement->execute();
    }

    /**
     * Delete the records of unknown start that arrived first, up to a limit, and get how many there were; they lie outside every window with a start, so no marker moves.
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
