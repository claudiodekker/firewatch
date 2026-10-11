<?php

namespace ClaudioDekker\Firewatch\Actions;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Deletion;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Instant;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use ClaudioDekker\Firewatch\Store\Writer;
use SQLite3;
use SQLite3Stmt;

/**
 * @internal
 */
class ClearStore
{
    /**
     * How long the clear waits for a busy store, in milliseconds, whatever capture is configured to wait.
     */
    public const BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * The deletion the clear removes records and reclaims pages with.
     */
    protected Deletion $deletion;

    /**
     * Create a new clear store action instance.
     *
     * @param  Writer  $writer  a writer of its own, which waits for a busy store as long as the clear does
     */
    public function __construct(
        protected Reader $reader,
        protected Configuration $configuration,
        protected FailureLog $failures,
        protected Writer $writer,
        protected int $chunkRows = Deletion::CHUNK_ROWS,
    ) {
        $this->deletion = new Deletion($writer, $chunkRows);
    }

    /**
     * Get why the store can't be cleared, or null when it holds a store this release can write.
     */
    public function unusable(): ?StoreUnusable
    {
        try {
            // A store that is damaged inside its file is found before anything is asked of the developer.
            $this->reader->snapshot(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA quick_check(1)') === 'ok' ?: throw new StoreUnusable(StoreState::CORRUPT));
        } catch (StoreUnusable $unusable) {
            return $unusable;
        }

        return null;
    }

    /**
     * Clear the store of every record, user and failure line, or of the records of one type.
     *
     * @return array{records: int, users: int, before: int, after: int, logInUse: bool}
     */
    public function clear(?RecordType $type): array
    {
        $before = $this->size();
        $instant = Instant::now();

        // Records that arrive while the clear runs have newer ids and stay.
        $through = $this->newestId();
        $records = 0;

        // Every chunk writes the marker of the clear, so the first one stamps the clear even when nothing is left to delete.
        do {
            $removed = $this->deletion->deleteThrough($through, $type, $instant);
            $records += $removed;
        } while ($removed === $this->chunkRows);

        $users = 0;

        if ($type === null) {
            $users = $this->deleteUsers($instant);

            $this->failures->clear();
        }

        $this->deletion->reclaimAll();

        $truncated = $this->truncateLog();

        return [
            'records' => $records,
            'users' => $users,
            'before' => $before,
            'after' => $this->size(),
            'logInUse' => ! $truncated,
        ];
    }

    /**
     * Rebuild the store in place as a fresh one.
     *
     * @param  StoreState|null  $state  why the store was unusable, or null when it was healthy
     * @return array{damaged: bool, before: int, after: int, logInUse: bool}
     */
    public function drop(?StoreState $state): array
    {
        $before = $this->size();

        match ($state) {
            StoreState::CORRUPT => $this->writer->replaceDamaged(),
            StoreState::SCHEMA_MISMATCH => $this->writer->replaceOtherSchema(),
            default => $this->writer->rebuild(),
        };

        $this->writer->maintain(fn (SQLite3 $connection) => $connection->exec('VACUUM'));

        $this->failures->clear();

        $this->deletion->reclaimAll();

        $truncated = $this->truncateLog();

        return [
            'damaged' => $state === StoreState::CORRUPT,
            'before' => $before,
            'after' => $this->size(),
            'logInUse' => ! $truncated,
        ];
    }

    /**
     * Read the newest id the store holds.
     */
    protected function newestId(): int
    {
        return Cell::integer($this->writer->transaction(fn (SQLite3 $connection) => $connection->querySingle('SELECT coalesce(max(id), 0) FROM records')));
    }

    /**
     * Delete the users last seen up to the instant the clear started.
     */
    protected function deleteUsers(float $instant): int
    {
        return $this->writer->transaction(function (SQLite3 $connection) use ($instant) {
            /** @var SQLite3Stmt $statement */
            $statement = $connection->prepare('DELETE FROM users WHERE last_seen <= :instant');

            $statement->bindValue(':instant', $instant, SQLITE3_FLOAT);
            $statement->execute();

            return $connection->changes();
        });
    }

    /**
     * Truncate the write-ahead log, and determine if it was: a reader that still uses the log keeps it from being truncated.
     */
    protected function truncateLog(): bool
    {
        $checkpoint = $this->writer->maintain(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', entireRow: true));

        return ! (is_array($checkpoint) && Cell::integer($checkpoint['busy']) === 1);
    }

    /**
     * Get the bytes the store takes on disk, the write-ahead log included.
     */
    protected function size(): int
    {
        clearstatcache();

        return Cell::integer(@filesize($this->configuration->database)) + Cell::integer(@filesize($this->configuration->database.'-wal'));
    }
}
