<?php

namespace ClaudioDekker\Firewatch\Actions;

use ClaudioDekker\Firewatch\Capture\Drift;
use ClaudioDekker\Firewatch\Capture\DriftKind;
use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Capture\TestRecord;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Store\DriftLedger;
use ClaudioDekker\Firewatch\Store\Instant;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\UserDirectory;
use ClaudioDekker\Firewatch\Store\Writer;
use SQLite3;
use SQLite3Stmt;

/**
 * @internal
 */
class AppendBatch
{
    /**
     * Whether a batch has been stored since the process started.
     */
    protected bool $storedFirstBatch = false;

    /**
     * Create a new action instance.
     */
    public function __construct(
        protected RecordMapper $mapper,
        protected Writer $writer,
        protected NightwatchInstall $install,
    ) {
        //
    }

    /**
     * Store a batch of wire records in one transaction.
     *
     * @param  list<array<mixed>>  $records
     * @param  array<int, list<mixed>|null>  $bindings  the bindings paired to each record, by its position in the batch
     * @param  array<int, TestRecord|null>  $testRecords  where each record fell in a test run, by its position in the batch
     * @param  array<int, list<string>|null>  $invalidFields  the fields that failed validation paired to each record, by its position in the batch
     */
    public function handle(array $records, array $bindings = [], array $testRecords = [], array $invalidFields = []): void
    {
        if ($records === []) {
            return;
        }

        $drift = $this->newBatchDrift();
        $rows = [];
        $users = [];

        foreach ($records as $position => $record) {
            $mapped = $this->mapper->map(
                $record,
                $drift,
                bindings: $bindings[$position] ?? null,
                testRecord: $testRecords[$position] ?? null,
                invalidFields: $invalidFields[$position] ?? null,
            );

            if ($mapped->user !== null) {
                $users[] = $mapped->user;
            } elseif ($mapped->record !== null) {
                $rows[] = $mapped->record;
            }
        }

        $receivedAt = Instant::now();

        $this->writer->transaction(function (SQLite3 $connection) use ($rows, $users, $drift, $receivedAt) {
            $this->execute($connection, $this->insert(), $rows);
            UserDirectory::upsert($connection, $users);
            DriftLedger::record($connection, $drift, $receivedAt);
            Markers::recordNightwatch($connection, $this->install->version, $this->install->isVerified());
        });

        $this->storedFirstBatch = true;
    }

    /**
     * Get the statement that stores one record.
     */
    protected function insert(): string
    {
        $columns = [...Schema::COLUMNS, 'data'];

        return 'INSERT INTO records ('.implode(', ', $columns).') VALUES (:'.implode(', :', $columns).')';
    }

    /**
     * Start a batch's drift with how Nightwatch is installed.
     */
    protected function newBatchDrift(): Drift
    {
        $drift = new Drift;

        if (! $this->install->isVerified()) {
            $drift->record(DriftKind::VERSION, detail: $this->install->version);
        }

        if ($this->install->registeredFirst && ! $this->storedFirstBatch) {
            $drift->record(DriftKind::STRUCTURE, detail: 'provider order');
        }

        return $drift;
    }

    /**
     * Run one prepared statement once per row.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function execute(SQLite3 $connection, string $sql, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $statement->bindValue(':'.$column, $value);
            }

            $statement->execute();
            $statement->reset();
        }

        $statement->close();
    }
}
