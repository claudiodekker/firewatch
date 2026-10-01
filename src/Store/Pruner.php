<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Illuminate\Support\Facades\Date;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;

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
     * @throws Throwable for a step that fails for any reason but the store being busy
     */
    public function run(): void
    {
        $now = (float) Date::now()->format('U.u');

        try {
            if ($this->claim($now)) {
                $this->prune($now);
            }
        } catch (Throwable $exception) {
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
            $connection->exec("INSERT OR IGNORE INTO meta (key, value) VALUES ('prune_claimed_at', '0')");

            /** @var SQLite3Stmt $statement */
            $statement = $connection->prepare("UPDATE meta SET value = :now WHERE key = 'prune_claimed_at' AND CAST(value AS REAL) < :before");
            $statement->bindValue(':now', $this->text($now));
            $statement->bindValue(':before', $now - static::CLAIM_SECONDS, SQLITE3_FLOAT);
            $statement->execute();

            return $connection->changes() === 1;
        });
    }

    /**
     * Read when the last pass was claimed, in a plain read that takes no write lock, or null when none was or the store can't be read.
     */
    protected function lastClaim(): ?float
    {
        try {
            $value = $this->reader->snapshot(fn (SQLite3 $connection) => $connection->querySingle("SELECT value FROM meta WHERE key = 'prune_claimed_at'"));
        } catch (StoreUnusable) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
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
            $removed = $this->chunk('started_at < :cutoff', ['cutoff' => $cutoff], $this->chunkRows(), 'age');
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
                $removed = $this->chunk('1 = 1', [], $limit, 'cap');
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
            if ($this->chunk('1 = 1', [], $this->chunkRows(), 'size') === 0) {
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

        $this->writer->maintain(function (SQLite3 $connection) use ($deleted) {
            $connection->exec('BEGIN IMMEDIATE');
            $connection->exec('PRAGMA incremental_vacuum('.$this->reclaimPages().')');
            $connection->exec('COMMIT');

            if (! $deleted) {
                return;
            }

            foreach (['PRAGMA wal_checkpoint(PASSIVE)', 'PRAGMA optimize'] as $statement) {
                try {
                    $connection->exec($statement);
                } catch (Throwable) {
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
            $total = $connection->querySingle('PRAGMA page_count');
            $free = $connection->querySingle('PRAGMA freelist_count');
            $size = $connection->querySingle('PRAGMA page_size');

            return ['live' => (int) $total - (int) $free, 'free' => (int) $free, 'size' => (int) $size];
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
    protected function chunk(string $condition, array $bindings, int $limit, string $reason): int
    {
        return $this->writer->transaction(function (SQLite3 $connection) use ($condition, $bindings, $limit, $reason) {
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
                $ids[] = (int) $row[0];
                $newest = (float) $row[1];
            }

            if ($ids === []) {
                return 0;
            }

            $connection->exec('DELETE FROM records WHERE id IN ('.implode(',', $ids).')');

            // Records without a start age with their neighbours by arrival.
            $connection->exec('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE started_at IS NULL AND id < '.max($ids).' ORDER BY id LIMIT '.$this->chunkRows().')');

            $this->mark($connection, $newest, $reason);

            return count($ids);
        });
    }

    /**
     * Record that history was removed through an instant, which never moves back, with the reason of the pass that advanced it.
     */
    protected function mark(SQLite3 $connection, float $through, string $reason): void
    {
        $current = $connection->querySingle("SELECT value FROM meta WHERE key = 'pruned_through'");

        if (is_numeric($current) && (float) $current >= $through) {
            return;
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("INSERT INTO meta (key, value) VALUES ('pruned_through', :through), ('pruned_by', :reason) ON CONFLICT (key) DO UPDATE SET value = excluded.value");
        $statement->bindValue(':through', $this->text($through));
        $statement->bindValue(':reason', $reason);
        $statement->execute();
    }

    /**
     * Write an instant as meta holds it: Unix seconds with microseconds.
     */
    protected function text(float $instant): string
    {
        return sprintf('%.6F', $instant);
    }
}
