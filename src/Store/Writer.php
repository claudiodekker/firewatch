<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Closure;
use RuntimeException;
use SQLite3;
use Throwable;

/**
 * @internal
 */
class Writer
{
    /**
     * The mode of the store's directory.
     */
    protected const DIRECTORY_MODE = 0700;

    /**
     * The mode of the store file.
     */
    protected const FILE_MODE = 0600;

    /**
     * The contents of the directory's VCS ignore file, which covers every companion file.
     */
    protected const GITIGNORE = "*\n";

    /**
     * The page size a new store file is created with.
     */
    protected const PAGE_SIZE = 4096;

    /**
     * The largest size the write-ahead log is truncated back to, in bytes.
     */
    protected const JOURNAL_SIZE_LIMIT_BYTES = 33554432;

    /**
     * The open connection, once a batch has been written.
     */
    protected ?SQLite3 $connection = null;

    /**
     * Create a new store writer instance.
     */
    public function __construct(protected Configuration $configuration)
    {
        //
    }

    /**
     * Run the callback in one write transaction, creating the store first if there is none.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     */
    public function transaction(Closure $callback): mixed
    {
        $connection = $this->connection ??= $this->open();

        return $this->transactionOn($connection, fn () => $callback($connection));
    }

    /**
     * Open the store, creating its directory, file and schema when they are missing.
     */
    protected function open(): SQLite3
    {
        $path = $this->configuration->database;

        $this->createDirectory(dirname($path));
        $this->createFile($path);

        $connection = new SQLite3($path, SQLITE3_OPEN_READWRITE);

        $connection->enableExceptions(true);
        $connection->busyTimeout($this->configuration->busyTimeoutMilliseconds);

        $this->configure($connection);
        $this->createSchema($connection);

        return $connection;
    }

    /**
     * Create the store's private directory and its VCS ignore file.
     */
    protected function createDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, static::DIRECTORY_MODE, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException("Firewatch could not create the store directory [{$directory}].");
        }

        $gitignore = $directory.'/.gitignore';

        if (! is_file($gitignore) && @file_put_contents($gitignore, static::GITIGNORE) === false) {
            throw new RuntimeException("Firewatch could not write [{$gitignore}].");
        }
    }

    /**
     * Create the private store file.
     */
    protected function createFile(string $path): void
    {
        if (is_file($path)) {
            return;
        }

        if (! @touch($path) || ! @chmod($path, static::FILE_MODE)) {
            throw new RuntimeException("Firewatch could not create the store file [{$path}].");
        }
    }

    /**
     * Set the pragmas every writer connection runs with.
     */
    protected function configure(SQLite3 $connection): void
    {
        $connection->exec('PRAGMA synchronous = NORMAL');
        $connection->exec('PRAGMA trusted_schema = 0');
        $connection->exec('PRAGMA journal_size_limit = '.static::JOURNAL_SIZE_LIMIT_BYTES);
    }

    /**
     * Create the schema and its stamps in a new store.
     */
    protected function createSchema(SQLite3 $connection): void
    {
        if ($this->isStamped($connection)) {
            return;
        }

        // The page size and auto-vacuum mode can only be set before the first table exists.
        $connection->exec('PRAGMA page_size = '.static::PAGE_SIZE);
        $connection->exec('PRAGMA auto_vacuum = INCREMENTAL');
        $connection->exec('PRAGMA journal_mode = WAL');

        $this->transactionOn($connection, function () use ($connection) {
            if ($this->isStamped($connection)) {
                return;
            }

            foreach ((new Schema)->statements() as $statement) {
                $connection->exec($statement);
            }

            $connection->exec('PRAGMA application_id = '.Schema::APPLICATION_ID);
            $connection->exec('PRAGMA user_version = '.Schema::VERSION);
        });
    }

    /**
     * Determine if the store carries Firewatch's stamps for this schema version.
     *
     * @throws RuntimeException when the file is stamped by something else
     */
    protected function isStamped(SQLite3 $connection): bool
    {
        $applicationId = $connection->querySingle('PRAGMA application_id');
        $userVersion = $connection->querySingle('PRAGMA user_version');

        if ($applicationId === Schema::APPLICATION_ID && $userVersion === Schema::VERSION) {
            return true;
        }

        if ($applicationId === 0 && $userVersion === 0 && $connection->querySingle('SELECT count(*) FROM sqlite_master') === 0) {
            return false;
        }

        throw new RuntimeException('The file at ['.$this->configuration->database.'] is not a store of this Firewatch schema.');
    }

    /**
     * Run the callback in one write transaction on the given connection.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function transactionOn(SQLite3 $connection, Closure $callback): mixed
    {
        $connection->exec('BEGIN IMMEDIATE');

        try {
            $result = $callback();

            $connection->exec('COMMIT');
        } catch (Throwable $exception) {
            $connection->exec('ROLLBACK');

            throw $exception;
        }

        return $result;
    }
}
