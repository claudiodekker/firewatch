<?php

namespace ClaudioDekker\Firewatch\Actions;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Store\Writer;
use SQLite3;
use SQLite3Stmt;

/**
 * @internal
 */
class AppendBatch
{
    /**
     * The statement that stores one record.
     */
    protected const INSERT = <<<'SQL'
        INSERT INTO records (type, v, started_at, duration, group_hash, trace_id, execution_id, source, job_id, user_id, deploy, server, data)
        VALUES (:type, :v, :started_at, :duration, :group_hash, :trace_id, :execution_id, :source, :job_id, :user_id, :deploy, :server, :data)
        SQL;

    /**
     * The statement that adds a user to the directory, or refreshes one it already holds.
     */
    protected const UPSERT_USER = <<<'SQL'
        INSERT INTO users (id, name, username, first_seen, last_seen)
        VALUES (:id, :name, :username, :seen_at, :seen_at)
        ON CONFLICT (id) DO UPDATE SET name = excluded.name, username = excluded.username, last_seen = excluded.last_seen
        SQL;

    /**
     * Create a new action instance.
     */
    public function __construct(
        protected RecordMapper $mapper,
        protected Writer $writer,
    ) {
        //
    }

    /**
     * Store a batch of wire records in one transaction, in wire order, keeping users in the directory.
     *
     * @param  list<array<mixed>>  $records
     */
    public function handle(array $records): void
    {
        if ($records === []) {
            return;
        }

        $rows = [];
        $users = [];

        foreach ($records as $record) {
            $user = $this->mapper->user($record);

            if ($user === null) {
                $rows[] = $this->mapper->map($record);
            } else {
                $users[] = $user;
            }
        }

        $this->writer->transaction(function (SQLite3 $connection) use ($rows, $users) {
            $this->execute($connection, static::INSERT, $rows);
            $this->execute($connection, static::UPSERT_USER, $users);
        });
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
