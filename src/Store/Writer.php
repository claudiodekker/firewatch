<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use SQLite3;
use SQLite3Exception;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Writer
{
    /**
     * The mode of the store's directory.
     */
    public const DIRECTORY_MODE = 0700;

    /**
     * The mode of the store file.
     */
    public const FILE_MODE = 0600;

    /**
     * The contents of the directory's VCS ignore file.
     */
    protected const GITIGNORE = "*\n";

    /**
     * The page size a new store file is created with.
     */
    protected const PAGE_SIZE = 4096;

    /**
     * The live bytes past which a pruning pass trims the oldest records.
     */
    public const SIZE_BACKSTOP_BYTES = 536870912;

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
     * The share of the backstop's pages a burst between two passes may reach, as a multiple.
     */
    protected const BURST_HEADROOM = 1.25;

    /**
     * The interval the lock file is polled at, in milliseconds.
     */
    protected const LOCK_POLL_MILLISECONDS = 5;

    /**
     * The open connection, once a batch has been written on a SQLite release without the WAL-reset bug.
     */
    protected ?SQLite3 $connection = null;

    /**
     * Why the next store this writer creates replaces one that was lost, until it stamps it.
     */
    protected ?string $rebuildReason = null;

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
     * The reader of the identity of the store file.
     */
    protected FileIdentity $identity;

    /**
     * The reader of the id of the process.
     *
     * @var Closure(): int
     */
    protected Closure $pid;

    /**
     * The log of the store's recoveries.
     */
    protected FailureLog $failures;

    /**
     * Create a new store writer instance.
     */
    public function __construct(
        protected Configuration $configuration,
        ?string $sqliteVersion = null,
        ?FileIdentity $identity = null,
        ?Closure $pid = null,
        ?FailureLog $failures = null,
    ) {
        $this->sqliteVersion = $sqliteVersion ?? SQLite3::version()['versionString'];
        $this->identity = $identity ?? new FileIdentity;
        $this->pid = $pid ?? static fn (): int => getmypid() ?: 0;
        $this->failures = $failures ?? new FailureLog($configuration);
    }

    /**
     * Run the callback in one write transaction, replacing a damaged Firewatch store once.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     *
     * @throws StoreFailure
     */
    public function transaction(Closure $callback): mixed
    {
        $identity = $this->identity->of($this->configuration->database);

        $guarded = function (SQLite3 $connection) use ($callback) {
            // A later release may also have rebuilt the store in place under a connection this writer kept.
            if (StoreStamp::read($connection)->isNewer()) {
                throw new StoreFailure(FailureKind::SCHEMA, 'The store at ['.$this->configuration->database.'] was written by a later release of Firewatch.');
            }

            return $callback($connection);
        };

        try {
            return $this->write($guarded);
        } catch (SQLite3Exception $exception) {
            // A store damaged in its header reads to SQLite as a file that is not a database; the stamp tells them apart.
            if (! in_array(FailureKind::of($exception), [FailureKind::CORRUPT, FailureKind::FOREIGN], true)) {
                throw $exception;
            }

            // A store another process already replaced is not this damage, so it is not moved aside again.
            if ($this->identity->of($this->configuration->database) === $identity) {
                if (FileKind::of($this->configuration->database) !== FileKind::FIREWATCH) {
                    throw $this->foreign();
                }

                $this->moveAside($exception);
            }

            return $this->write($guarded);
        }
    }

    /**
     * Run the callback on the store's connection under the same lock as a write, without a transaction.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     */
    public function maintain(Closure $callback): mixed
    {
        return $this->run($callback, transactional: false);
    }

    /**
     * Run the callback in one write transaction on the store.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     */
    protected function write(Closure $callback): mixed
    {
        return $this->run($callback, transactional: true);
    }

    /**
     * Run the callback on the store, in a write transaction or not, under the lock the SQLite release calls for.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3): TResult  $callback
     * @return TResult
     */
    protected function run(Closure $callback, bool $transactional): mixed
    {
        $run = fn (SQLite3 $connection) => $transactional ? $this->transactionOn($connection, fn () => $callback($connection)) : $callback($connection);

        if (! static::hasWalResetBug($this->sqliteVersion)) {
            return $run($this->connection());
        }

        // A connection kept across the lock could reset the write-ahead log under another writer.
        return $this->locked(function (int $remainingMilliseconds) use ($run) {
            $connection = $this->open($remainingMilliseconds);

            try {
                return $run($connection);
            } finally {
                $connection->close();
            }
        });
    }

    /**
     * Get the open connection, making a new one when the kept one is stale.
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
     * Let go of the open connection.
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
     * Determine if a SQLite release can reset the write-ahead log under a concurrent writer.
     */
    public static function hasWalResetBug(string $sqliteVersion): bool
    {
        foreach (static::WAL_RESET_BUG as [$affected, $fixed]) {
            if (version_compare($sqliteVersion, $affected, '>=') && version_compare($sqliteVersion, $fixed, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the callback holding the exclusive lock file, with what is left of the busy timeout.
     *
     * @template TResult
     *
     * @param  Closure(int): TResult  $callback
     * @return TResult
     *
     * @throws StoreFailure
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

                Sleep::usleep($poll * Microseconds::PER_MILLISECOND);

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
     * Open the store, creating its directory, file and schema when they are missing.
     */
    protected function open(int $busyTimeoutMilliseconds): SQLite3
    {
        $path = $this->configuration->database;

        $this->createDirectory(dirname($path));
        $this->createFile($path);

        if (FileKind::of($path) === FileKind::NOT_SQLITE) {
            throw $this->foreign();
        }

        $connection = new SQLite3($path, SQLITE3_OPEN_READWRITE);

        $ready = false;

        // A connection left to the exception's trace could close, and checkpoint, after the lock is released.
        try {
            $connection->enableExceptions(true);
            $connection->busyTimeout($busyTimeoutMilliseconds);

            $this->configure($connection);
            $this->createSchema($connection);

            $ready = true;
        } finally {
            if (! $ready) {
                $connection->close();
            }
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

        $connection->exec('PRAGMA max_page_count = '.(int) floor(static::SIZE_BACKSTOP_BYTES * static::BURST_HEADROOM / static::PAGE_SIZE));
    }

    /**
     * Create the schema and its stamps in a new store, or rebuild a store of an earlier schema version.
     */
    protected function createSchema(SQLite3 $connection): void
    {
        $stamp = StoreStamp::read($connection);

        if ($stamp->isCurrent() || $stamp->isNewer()) {
            return;
        }

        if ($stamp->isFresh()) {
            // The page size and auto-vacuum mode can only be set before the first table exists, and never change across schema versions.
            $connection->exec('PRAGMA page_size = '.static::PAGE_SIZE);
            $connection->exec('PRAGMA auto_vacuum = INCREMENTAL');
            $connection->exec('PRAGMA journal_mode = WAL');
        }

        $this->transactionOn($connection, function () use ($connection) {
            // Another writer may have created or rebuilt the store since the first read.
            $stamp = StoreStamp::read($connection);

            if ($stamp->isCurrent() || $stamp->isNewer()) {
                return;
            }

            if (! $stamp->isFresh() && ! $stamp->isFirewatch()) {
                throw $this->foreign();
            }

            $this->build($connection, $stamp->isFresh() ? $this->rebuildReason : 'schema');
        });
    }

    /**
     * Drop everything in the store and build the current schema and its stamps in its place.
     */
    protected function build(SQLite3 $connection, ?string $why): void
    {
        $this->dropEverything($connection);

        foreach ((new Schema)->statements() as $statement) {
            $connection->exec($statement);
        }

        $now = Date::now();

        Markers::markCreated($connection, $now);

        if ($why !== null) {
            Markers::markRebuilt($connection, $now, $why);
        }

        $this->rebuildReason = null;
        $connection->exec('PRAGMA application_id = '.Schema::APPLICATION_ID);
        $connection->exec('PRAGMA user_version = '.Schema::VERSION);
    }

    /**
     * Rebuild the store in place as a fresh one, without unlinking its file.
     */
    public function rebuild(): void
    {
        $this->transaction(fn (SQLite3 $connection) => $this->build($connection, null));
    }

    /**
     * Rebuild a store of another schema version in place, one a later release wrote too.
     */
    public function replaceOtherSchema(): void
    {
        $this->write(fn (SQLite3 $connection) => StoreStamp::read($connection)->isCurrent() ? null : $this->build($connection, 'schema'));
    }

    /**
     * Move a damaged store aside and create a new one in its place.
     */
    public function replaceDamaged(): void
    {
        $this->moveAside(new SQLite3Exception('database disk image is malformed', FailureKind::SQLITE_CORRUPT));

        $this->transaction(fn () => null);
    }

    /**
     * Drop every view and table of the store, and with the tables their indexes, in place.
     */
    protected function dropEverything(SQLite3 $connection): void
    {
        foreach (['view', 'table'] as $type) {
            /** @var SQLite3Stmt $statement */
            $statement = $connection->prepare(<<<'SQL'
                SELECT group_concat('DROP ' || upper(type) || ' "' || replace(name, '"', '""') || '"', ';')
                FROM sqlite_master
                WHERE type = :type AND name NOT LIKE 'sqlite\_%' ESCAPE '\'
                SQL);
            $statement->bindValue(':type', $type);

            /** @var SQLite3Result $result */
            $result = $statement->execute();
            $row = $result->fetchArray(SQLITE3_NUM);
            $statement->close();

            if (is_array($row) && is_string($row[0])) {
                $connection->exec($row[0]);
            }
        }
    }

    /**
     * Move the damaged store, its write-ahead log and its shared memory aside, and record it.
     */
    protected function moveAside(SQLite3Exception $exception): void
    {
        if ($this->connection !== null) {
            $this->release($this->connection);
        }

        $path = $this->configuration->database;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                @rename($path.$suffix, $path.$suffix.'.corrupt');
            } else {
                @unlink($path.$suffix.'.corrupt');
            }
        }

        $this->failures->recovered($exception);

        $this->rebuildReason = 'corrupt';
    }

    /**
     * Get the failure for a file that is not a Firewatch store.
     */
    protected function foreign(): StoreFailure
    {
        return new StoreFailure(FailureKind::FOREIGN, 'The file at ['.$this->configuration->database.'] is not a Firewatch store.');
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

        $committed = false;

        try {
            $result = $callback();

            $connection->exec('COMMIT');

            $committed = true;
        } finally {
            // SQLite rolls a transaction back itself when the store is full, and there is then nothing left to roll back.
            if (! $committed) {
                try {
                    $connection->exec('ROLLBACK');
                } catch (SQLite3Exception) {
                    //
                }
            }
        }

        return $result;
    }
}
