<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Closure;
use SQLite3;
use SQLite3Exception;

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
     * The most delete transactions one pass makes.
     */
    protected const PASS_TRANSACTIONS = 20;

    /**
     * The share of the record cap or the size backstop a trim leaves, so neither creeps on every pass.
     */
    protected const TRIM_TO = 0.9;

    /**
     * The deletion the pass removes records and reclaims pages with.
     */
    protected Deletion $deletion;

    /**
     * Create a new pruner instance.
     *
     * @param  int  $reclaimPages  the pages one incremental vacuum frees, and the freelist length above which an idle pass reclaims
     */
    public function __construct(
        protected Writer $writer,
        protected Reader $reader,
        protected Configuration $configuration,
        protected int $chunkRows = Deletion::CHUNK_ROWS,
        protected int $passTransactions = self::PASS_TRANSACTIONS,
        protected int $backstopBytes = Writer::SIZE_BACKSTOP_BYTES,
        protected int $reclaimPages = Deletion::RECLAIM_PAGES,
    ) {
        $this->deletion = new Deletion($writer, $chunkRows, $reclaimPages);
    }

    /**
     * Run a pass after a batch was stored, if no process ran one in the last minute.
     *
     * @throws SQLite3Exception|StoreFailure|StoreUnusable
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
     * Run a pass at once for a store that is full, whichever process claimed the minute.
     *
     * @throws SQLite3Exception|StoreFailure|StoreUnusable
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
     * @throws SQLite3Exception|StoreFailure|StoreUnusable
     */
    protected function unlessBusy(Closure $steps): void
    {
        try {
            $steps();
        } catch (SQLite3Exception|StoreFailure|StoreUnusable $exception) {
            if (FailureKind::of($exception) !== FailureKind::BUSY) {
                throw $exception;
            }
        }
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
     * Read when the last pass was claimed, in a plain read that takes no write lock.
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

        $deleted = $this->writer->transaction(fn (SQLite3 $connection) => UserDirectory::deleteSeenBefore($connection, $cutoff) > 0);
        $deleted = $this->writer->transaction(fn (SQLite3 $connection) => DriftLedger::deleteSeenBefore($connection, $cutoff) > 0) || $deleted;

        $transactions = $this->passTransactions;

        while ($transactions > 0) {
            $removed = $this->deletion->pruneChunk('started_at < :cutoff', ['cutoff' => $cutoff], $this->chunkRows, PruneReason::AGE);
            $deleted = $deleted || $removed > 0;

            // A transaction that found nothing to delete does not count against the pass.
            $transactions -= $removed > 0 ? 1 : 0;

            if ($removed < $this->chunkRows) {
                break;
            }
        }

        $count = $this->count();

        if ($count > $this->configuration->retentionRecords) {
            $excess = $count - (int) floor(static::TRIM_TO * $this->configuration->retentionRecords);

            while ($transactions > 0 && $excess > 0) {
                $transactions--;
                $limit = min($this->chunkRows, $excess);
                $removed = $this->deletion->pruneChunk('1 = 1', [], $limit, PruneReason::CAP);
                $excess -= $removed;
                $deleted = $deleted || $removed > 0;

                if ($removed < $limit) {
                    break;
                }
            }
        }

        $pages = $this->pages();
        $target = (int) floor(static::TRIM_TO * $this->backstopBytes);

        // Once over the backstop the trim goes on until the target, so the boundary does not creep.
        $over = $this->backstopBytes < $pages['live'] * $pages['size'];

        while ($over && $transactions > 0) {
            $transactions--;

            // Freed pages land on the freelist at once, so the counts are read again after each committed chunk.
            if ($this->deletion->pruneChunk('1 = 1', [], $this->chunkRows, PruneReason::SIZE) === 0) {
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
     * Give freed pages back to the file.
     */
    protected function reclaim(bool $deleted): void
    {
        if (! $deleted && $this->pages()['free'] <= $this->reclaimPages) {
            return;
        }

        $this->deletion->reclaim();

        if (! $deleted) {
            return;
        }

        $this->writer->maintain(function (SQLite3 $connection) {
            foreach (['PRAGMA optimize', 'PRAGMA wal_checkpoint(PASSIVE)'] as $statement) {
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
        return Cell::integer($this->reader->snapshot(fn (SQLite3 $connection) => $connection->querySingle('SELECT count(*) FROM records')));
    }
}
