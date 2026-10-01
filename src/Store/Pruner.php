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

        foreach (['users', 'drift'] as $table) {
            $this->writer->transaction(function (SQLite3 $connection) use ($table, $cutoff) {
                /** @var SQLite3Stmt $statement */
                $statement = $connection->prepare("DELETE FROM {$table} WHERE last_seen < :cutoff");
                $statement->bindValue(':cutoff', $cutoff, SQLITE3_FLOAT);
                $statement->execute();
            });
        }

        $transactions = $this->passTransactions();

        while ($transactions > 0) {
            $transactions--;

            if ($this->chunk('started_at < :cutoff', ['cutoff' => $cutoff], $this->chunkRows(), 'age') < $this->chunkRows()) {
                break;
            }
        }

        $count = $this->count();

        if ($count <= $this->configuration->retentionRecords) {
            return;
        }

        $excess = $count - (int) floor(static::TRIM_TO * $this->configuration->retentionRecords);

        while ($transactions > 0 && $excess > 0) {
            $transactions--;
            $limit = min($this->chunkRows(), $excess);
            $removed = $this->chunk('1 = 1', [], $limit, 'cap');
            $excess -= $removed;

            if ($removed < $limit) {
                break;
            }
        }
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
