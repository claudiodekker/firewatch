<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Closure;
use SQLite3;

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
     * Create a new store reader instance.
     */
    public function __construct(protected Configuration $configuration)
    {
        //
    }

    /**
     * Determine if a store has been written, without creating anything.
     */
    public function exists(): bool
    {
        $path = $this->configuration->database;

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Run the callback inside one read snapshot of the store.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     */
    public function snapshot(Closure $callback): mixed
    {
        $connection = $this->open();

        try {
            $connection->exec('BEGIN');

            $result = $callback($connection);

            $connection->exec('COMMIT');
        } finally {
            $connection->close();
        }

        return $result;
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
