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
     * Create a new action instance.
     */
    public function __construct(
        protected RecordMapper $mapper,
        protected Writer $writer,
    ) {
        //
    }

    /**
     * Store a batch of wire records in one transaction, in wire order.
     *
     * @param  list<array<mixed>>  $records
     */
    public function handle(array $records): void
    {
        if ($records === []) {
            return;
        }

        $rows = array_map($this->mapper->map(...), $records);

        $this->writer->transaction(fn (SQLite3 $connection) => $this->insert($connection, $rows));
    }

    /**
     * Insert the rows through one prepared statement.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insert(SQLite3 $connection, array $rows): void
    {
        /** @var SQLite3Stmt $statement the connection throws rather than return false */
        $statement = $connection->prepare(static::INSERT);

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
