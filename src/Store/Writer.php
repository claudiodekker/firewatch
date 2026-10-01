<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Closure;
use Illuminate\Support\Sleep;
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
     * The SQLite releases with the WAL-reset bug, each from its first affected release up to the release that fixed it.
     */
    protected const WAL_RESET_BUG = [
        ['3.7.0', '3.44.6'],
        ['3.45.0', '3.50.7'],
        ['3.51.0', '3.51.3'],
    ];

    /**
     * The interval the lock file is polled at, in milliseconds.
     */
    protected const LOCK_POLL_MILLISECONDS = 5;

    /**
     * The open connection, once a batch has been written on a SQLite release without the WAL-reset bug.
     */
    protected ?SQLite3 $connection = null;

    /**
     * The process the open connection was made in.
     */
    protected ?int $connectionPid = null;

    /**
     * The device and inode of the store file when the open connection was made, or null when it had none.
     */
    protected ?string $connectionIdentity = null;

    /**
     * The connections inherited across a fork, held so that nothing closes them before the child exits.
     *
     * @var list<SQLite3>
     */
    protected array $abandoned = [];

    /**
     * The SQLite release the store is written with.
     */
    protected string $sqliteVersion;

    /**
     * Reads the identity of the store file.
     */
    protected FileIdentity $identity;

    /**
     * Reads the id of the process.
     *
     * @var Closure(): int
     */
    protected Closure $pid;

    /**
     * Create a new store writer instance.
     */
    public function __construct(
        protected Configuration $configuration,
        ?string $sqliteVersion = null,
        ?FileIdentity $identity = null,
        ?Closure $pid = null,
    ) {
        $this->sqliteVersion = $sqliteVersion ?? SQLite3::version()['versionString'];
        $this->identity = $identity ?? new FileIdentity;
        $this->pid = $pid ?? static fn (): int => getmypid() ?: 0;
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
        if (! $this->hasWalResetBug()) {
            $connection = $this->connection();

            return $this->transactionOn($connection, fn () => $callback($connection));
        }

        // A connection kept across the lock could reset the write-ahead log under another writer.
        return $this->locked(function (int $remainingMilliseconds) use ($callback) {
            $connection = $this->open($remainingMilliseconds);

            try {
                return $this->transactionOn($connection, fn () => $callback($connection));
            } finally {
                $connection->close();
            }
        });
    }

    /**
     * Get the open connection, making a new one when there is none, it was inherited across a fork, or the store file was deleted or replaced.
     */
    protected function connection(): SQLite3
    {
        $pid = ($this->pid)();
        $identity = $this->identity->of($this->configuration->database);

        if ($this->connection !== null && ($this->connectionPid !== $pid || $this->connectionIdentity !== $identity)) {
            $this->release($this->connection);
        }

        if ($this->connection === null) {
            $this->connection = $this->open($this->configuration->busyTimeoutMilliseconds);
            $this->connectionPid = $pid;

            // Read before the open, so a file swapped in after it is seen by the next batch; a store that did not exist yet was created by the open, and is read after it.
            $this->connectionIdentity = $identity ?? $this->identity->of($this->configuration->database);
        }

        return $this->connection;
    }

    /**
     * Let go of the open connection: a connection inherited across a fork is held unused until the child exits, rather than closed from the child, and any other is closed.
     */
    protected function release(SQLite3 $connection): void
    {
        if ($this->connectionPid !== ($this->pid)()) {
            $this->abandoned[] = $connection;
        } else {
            $connection->close();
        }

        $this->connection = null;
    }

    /**
     * Determine if the SQLite release can reset the write-ahead log under a concurrent writer.
     */
    protected function hasWalResetBug(): bool
    {
        foreach (static::WAL_RESET_BUG as [$affected, $fixed]) {
            if (version_compare($this->sqliteVersion, $affected, '>=') && version_compare($this->sqliteVersion, $fixed, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the callback holding the exclusive lock file, polled within the busy timeout, with what is left of it.
     *
     * @template TResult
     *
     * @param  Closure(int): TResult  $callback
     * @return TResult
     *
     * @throws StoreFailure when the lock is not acquired within the busy timeout
     */
    protected function locked(Closure $callback): mixed
    {
        $path = $this->configuration->database.'.lock';
        $budget = $this->configuration->busyTimeoutMilliseconds;

        $this->createDirectory(dirname($path));
        $handle = $this->openLockFile($path);

        // The budget counts down by what was slept, so a frozen or faked clock in the host can't stretch it.
        $remaining = $budget;

        try {
            while (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if (! $wouldBlock) {
                    throw new StoreFailure(FailureKind::IO, "Firewatch could not lock [{$path}].");
                }

                if ($remaining <= 0) {
                    throw new StoreFailure(FailureKind::BUSY, "Firewatch could not lock [{$path}] within {$budget} ms.");
                }

                $poll = min($remaining, static::LOCK_POLL_MILLISECONDS);

                Sleep::usleep($poll * 1_000);

                $remaining -= $poll;
            }

            return $callback($remaining);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Open the private lock file, creating it when it is missing.
     *
     * @return resource
     */
    protected function openLockFile(string $path): mixed
    {
        $created = ! file_exists($path);
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new StoreFailure(FailureKind::IO, "Firewatch could not open [{$path}].");
        }

        if ($created && ! @chmod($path, static::FILE_MODE)) {
            fclose($handle);

            throw new StoreFailure(FailureKind::IO, "Firewatch could not open [{$path}].");
        }

        return $handle;
    }

    /**
     * Open the store with the given busy timeout, creating its directory, file and schema when they are missing.
     */
    protected function open(int $busyTimeoutMilliseconds): SQLite3
    {
        $path = $this->configuration->database;

        $this->createDirectory(dirname($path));
        $this->createFile($path);

        $connection = new SQLite3($path, SQLITE3_OPEN_READWRITE);

        // A connection left to the exception's trace could close, and checkpoint, after the lock is released.
        try {
            $connection->enableExceptions(true);
            $connection->busyTimeout($busyTimeoutMilliseconds);

            $this->configure($connection);
            $this->createSchema($connection);
        } catch (Throwable $exception) {
            $connection->close();

            throw $exception;
        }

        return $connection;
    }

    /**
     * Create the store's private directory and its VCS ignore file.
     */
    protected function createDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, static::DIRECTORY_MODE, recursive: true) && ! is_dir($directory)) {
            throw new StoreFailure(FailureKind::IO, "Firewatch could not create the store directory [{$directory}].");
        }

        $gitignore = $directory.'/.gitignore';

        if (! is_file($gitignore) && @file_put_contents($gitignore, static::GITIGNORE) === false) {
            throw new StoreFailure(FailureKind::IO, "Firewatch could not write [{$gitignore}].");
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
            throw new StoreFailure(FailureKind::IO, "Firewatch could not create the store file [{$path}].");
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
     * @throws StoreFailure when the file is another schema version's store or not a store at all
     */
    protected function isStamped(SQLite3 $connection): bool
    {
        $applicationId = $connection->querySingle('PRAGMA application_id');
        /** @var int $userVersion */
        $userVersion = $connection->querySingle('PRAGMA user_version');

        if ($applicationId === Schema::APPLICATION_ID && $userVersion === Schema::VERSION) {
            return true;
        }

        if ($applicationId === 0 && $userVersion === 0 && $connection->querySingle('SELECT count(*) FROM sqlite_master') === 0) {
            return false;
        }

        if ($applicationId === Schema::APPLICATION_ID) {
            throw new StoreFailure(FailureKind::SCHEMA, 'The store at ['.$this->configuration->database.'] has schema version '.$userVersion.', not '.Schema::VERSION.'.');
        }

        throw new StoreFailure(FailureKind::FOREIGN, 'The file at ['.$this->configuration->database.'] is not a Firewatch store.');
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
