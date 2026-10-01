<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ModeResolver;
use Closure;
use SQLite3;
use SQLite3Exception;

/**
 * @internal
 */
class Reader
{
    /**
     * How long a read waits for a busy store, in milliseconds.
     */
    protected const BUSY_TIMEOUT_MILLISECONDS = 1000;

    /**
     * The SQLite release the store is read with.
     */
    protected string $sqliteVersion;

    /**
     * Create a new store reader instance.
     */
    public function __construct(protected Configuration $configuration, ?string $sqliteVersion = null)
    {
        $this->sqliteVersion = $sqliteVersion ?? SQLite3::version()['versionString'];
    }

    /**
     * Determine if a store has been written, without creating anything.
     */
    public function exists(): bool
    {
        return FileKind::of($this->configuration->database) !== FileKind::Empty;
    }

    /**
     * Run the callback inside one read snapshot of the store.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     *
     * @throws StoreUnusable when the store is not in a state to be read, which the reader never repairs
     */
    public function snapshot(Closure $callback): mixed
    {
        $this->check();

        try {
            $connection = $this->open();

            try {
                $connection->exec('BEGIN');

                $this->checkStamps($connection);

                $result = $callback($connection);

                $connection->exec('COMMIT');
            } finally {
                $connection->close();
            }
        } catch (SQLite3Exception $exception) {
            throw $this->unusable($exception) ?? $exception;
        }

        return $result;
    }

    /**
     * Refuse to open what cannot be a store, from the SQLite release and the header of the file alone.
     */
    protected function check(): void
    {
        if (version_compare($this->sqliteVersion, ModeResolver::MINIMUM_SQLITE_VERSION, '<')) {
            throw new StoreUnusable(StoreState::Unavailable, $this->sqliteVersion);
        }

        match (FileKind::of($this->configuration->database)) {
            FileKind::Empty => throw new StoreUnusable(StoreState::Absent),
            FileKind::NotSqlite => throw new StoreUnusable(StoreState::Foreign),
            default => null,
        };
    }

    /**
     * Refuse a store that Firewatch did not stamp for this schema version, from inside the snapshot.
     */
    protected function checkStamps(SQLite3 $connection): void
    {
        $stamp = StoreStamp::read($connection);

        if ($stamp->isCurrent()) {
            return;
        }

        throw match (true) {
            $stamp->isFirewatch() => new StoreUnusable(StoreState::SchemaMismatch, $stamp->userVersion),
            $stamp->isFresh() => new StoreUnusable(StoreState::Absent),
            default => new StoreUnusable(StoreState::Foreign),
        };
    }

    /**
     * Get the state a SQLite failure puts the store in, or null when the failure is not about the store.
     */
    protected function unusable(SQLite3Exception $exception): ?StoreUnusable
    {
        return match (FailureKind::of($exception)) {
            FailureKind::BUSY => new StoreUnusable(StoreState::Busy),
            FailureKind::FOREIGN => new StoreUnusable(StoreState::Foreign),
            FailureKind::CORRUPT => new StoreUnusable(FileKind::of($this->configuration->database) === FileKind::Firewatch ? StoreState::Corrupt : StoreState::Foreign),
            default => null,
        };
    }

    /**
     * Open a read-only connection to the store.
     */
    protected function open(): SQLite3
    {
        $connection = new SQLite3($this->configuration->database, SQLITE3_OPEN_READONLY);

        $connection->enableExceptions(true);
        $connection->busyTimeout(static::BUSY_TIMEOUT_MILLISECONDS);
        $connection->exec('PRAGMA query_only = 1');
        $connection->exec('PRAGMA trusted_schema = 0');

        return $connection;
    }
}
