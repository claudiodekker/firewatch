<?php

namespace ClaudioDekker\Firewatch\Actions;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Markers;
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
    protected const BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * The most records one delete transaction removes.
     */
    protected const CHUNK_ROWS = 2000;

    /**
     * The pages one incremental vacuum frees.
     */
    protected const RECLAIM_PAGES = 1024;

    /**
     * The writer the clear runs with, once it is first needed.
     */
    protected ?Writer $writer = null;

    /**
     * Create a new clear store action instance.
     */
    public function __construct(
        protected Reader $reader,
        protected Configuration $configuration,
    ) {
        //
    }

    /**
     * Get why the store can't be cleared, or null when it holds a store this release can write; nothing is created or changed.
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
     * @return array{records: int, users: int, before: int, after: int, truncated: bool}
     */
    public function clear(?RecordType $type): array
    {
        $before = $this->size();
        $instant = Instant::now();

        // Stamped before anything is deleted, so a reader that sees a half-cleared store already clips its history; records that arrive meanwhile have newer ids and stay.
        $through = $this->stamp($type, $instant);
        $records = 0;

        do {
            $removed = $this->deleteChunk($type?->value, $through);
            $records += $removed;
        } while ($removed === static::CHUNK_ROWS);

        $users = 0;

        if ($type === null) {
            $users = $this->deleteUsers($instant);

            (new FailureLog($this->configuration))->clear();
        }

        $truncated = $this->reclaim();

        return [
            'records' => $records,
            'users' => $users,
            'before' => $before,
            'after' => $this->size(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Rebuild the store in place as a fresh one, moving a damaged file aside first, vacuum it and empty the failure log; the state is why the store was unusable, or null when it was healthy.
     *
     * @return array{damaged: bool, before: int, after: int, truncated: bool}
     */
    public function drop(?StoreState $state): array
    {
        $before = $this->size();
        $writer = $this->writer();

        match ($state) {
            StoreState::CORRUPT => $writer->replaceDamaged(),
            StoreState::SCHEMA_MISMATCH => $writer->replaceOtherSchema(),
            default => $writer->rebuild(),
        };

        $writer->maintain(fn (SQLite3 $connection) => $connection->exec('VACUUM'));

        (new FailureLog($this->configuration))->clear();

        $truncated = $this->reclaim();

        return [
            'damaged' => $state === StoreState::CORRUPT,
            'before' => $before,
            'after' => $this->size(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Write the marker of the clear and read the newest id, in one transaction before any row is deleted.
     */
    protected function stamp(?RecordType $type, float $instant): int
    {
        return $this->writer()->transaction(function (SQLite3 $connection) use ($type, $instant) {
            $through = Cell::integer($connection->querySingle('SELECT coalesce(max(id), 0) FROM records'));

            if ($type === null) {
                Markers::markCleared($connection, $instant);
            } else {
                Markers::markTypeCleared($connection, $type, $instant);
            }

            return $through;
        });
    }

    /**
     * Delete one chunk of the records up to the newest id the clear saw, of one type or of all, and get how many went.
     */
    protected function deleteChunk(?string $type, int $through): int
    {
        return $this->writer()->transaction(function (SQLite3 $connection) use ($type, $through) {
            /** @var SQLite3Stmt $statement */
            $statement = $connection->prepare('DELETE FROM records WHERE id IN (SELECT id FROM records WHERE id <= :through AND (:type IS NULL OR type = :type) ORDER BY id LIMIT :limit)');

            $statement->bindValue(':through', $through, SQLITE3_INTEGER);
            $statement->bindValue(':type', $type, $type === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $statement->bindValue(':limit', static::CHUNK_ROWS, SQLITE3_INTEGER);
            $statement->execute();

            return $connection->changes();
        });
    }

    /**
     * Delete the users last seen up to the instant the clear started, so one that signed in meanwhile stays.
     */
    protected function deleteUsers(float $instant): int
    {
        return $this->writer()->transaction(function (SQLite3 $connection) use ($instant) {
            /** @var SQLite3Stmt $statement */
            $statement = $connection->prepare('DELETE FROM users WHERE last_seen <= :instant');

            $statement->bindValue(':instant', $instant, SQLITE3_FLOAT);
            $statement->execute();

            return $connection->changes();
        });
    }

    /**
     * Give every freed page back to the file, then truncate the log: true when a reader kept the log from being truncated.
     */
    protected function reclaim(): bool
    {
        $writer = $this->writer();

        $freePages = Cell::integer($writer->transaction(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA freelist_count')));

        // One step frees a fixed number of pages, so the steps needed are known and the loop always ends.
        for ($steps = intdiv($freePages + static::RECLAIM_PAGES - 1, static::RECLAIM_PAGES); $steps > 0; $steps--) {
            $writer->transaction(fn (SQLite3 $connection) => $connection->exec('PRAGMA incremental_vacuum('.static::RECLAIM_PAGES.')'));
        }

        $checkpoint = $writer->maintain(fn (SQLite3 $connection) => $connection->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', entireRow: true));

        return is_array($checkpoint) && Cell::integer($checkpoint['busy']) === 1;
    }

    /**
     * Get the bytes the store takes on disk, the write-ahead log included.
     */
    protected function size(): int
    {
        clearstatcache();

        return Cell::integer(@filesize($this->configuration->database)) + Cell::integer(@filesize($this->configuration->database.'-wal'));
    }

    /**
     * Get the writer the clear runs with, which waits for a busy store as long as the clear does.
     */
    protected function writer(): Writer
    {
        return $this->writer ??= new Writer($this->configuration->withBusyTimeout(static::BUSY_TIMEOUT_MILLISECONDS));
    }
}
