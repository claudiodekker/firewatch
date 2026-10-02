<?php

namespace ClaudioDekker\Firewatch\Actions;

use ClaudioDekker\Firewatch\Capture\Drift;
use ClaudioDekker\Firewatch\Capture\DriftKind;
use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Writer;
use SQLite3;
use SQLite3Result;
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
     * The statement that adds a user to the directory, or refreshes one it holds without moving its last sighting back.
     */
    protected const UPSERT_USER = <<<'SQL'
        INSERT INTO users (id, name, username, first_seen, last_seen)
        VALUES (:id, :name, :username, :seen_at, :seen_at)
        ON CONFLICT (id) DO UPDATE SET
            name = excluded.name,
            username = excluded.username,
            last_seen = max(coalesce(last_seen, excluded.last_seen), coalesce(excluded.last_seen, last_seen))
        SQL;

    /**
     * The statement that adds a batch's count of one drift, keeping when it was first seen.
     */
    protected const UPSERT_DRIFT = <<<'SQL'
        INSERT INTO drift (kind, type, v, detail, count, first_seen, last_seen)
        VALUES (:kind, :type, :v, :detail, :count, :seen_at, :seen_at)
        ON CONFLICT (kind, type, v, detail) DO UPDATE SET
            count = count + excluded.count,
            last_seen = excluded.last_seen
        SQL;

    /**
     * The most drift rows kept apart; any new one beyond is folded into its kind's overflow row.
     */
    protected const DRIFT_ROWS = 500;

    /**
     * The detail of the row a kind's drift beyond the cap is folded into.
     */
    protected const DRIFT_OVERFLOW = '... [overflow]';

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
     * Store a batch of wire records in one transaction, in wire order, with the bindings paired to its queries, keeping users in the directory and counting drift.
     *
     * @param  list<array<mixed>>  $records
     * @param  array<int, list<mixed>|null>  $bindings  the bindings paired to each record, by its position in the batch
     */
    public function handle(array $records, array $bindings = []): void
    {
        if ($records === []) {
            return;
        }

        $drift = $this->newBatchDrift();
        $rows = [];
        $users = [];

        foreach ($records as $position => $record) {
            $mapped = $this->mapper->map($record, $drift, bindings: $bindings[$position] ?? null);

            if ($mapped->user !== null) {
                $users[] = $mapped->user;
            } elseif ($mapped->record !== null) {
                $rows[] = $mapped->record;
            }
        }

        $receivedAt = (float) now()->format('U.u');

        $this->writer->transaction(function (SQLite3 $connection) use ($rows, $users, $drift, $receivedAt) {
            $this->execute($connection, static::INSERT, $rows);
            $this->execute($connection, static::UPSERT_USER, $users);
            $driftRows = $this->driftRows($connection, $drift, $receivedAt);

            $this->execute($connection, static::UPSERT_DRIFT, $driftRows);
            Markers::recordNightwatch($connection, $this->install->version, $this->install->isVerified());
        });

        $this->storedFirstBatch = true;
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
     * Get the drift rows to add a batch's counts to, folding each new one beyond the cap into its kind's overflow row.
     *
     * @return list<array{kind: string, type: string, v: string, detail: string, count: int, seen_at: float}>
     */
    protected function driftRows(SQLite3 $connection, Drift $drift, float $receivedAt): array
    {
        $counted = $drift->all();

        if ($counted === []) {
            return [];
        }

        $kept = $this->keptDrift($connection);
        $rows = [];

        foreach ($counted as $occurrence) {
            $key = $this->driftKey($occurrence);

            if (! isset($kept[$key]) && count($kept) >= static::DRIFT_ROWS) {
                $occurrence = [
                    ...$occurrence,
                    'type' => '',
                    'v' => '',
                    'detail' => static::DRIFT_OVERFLOW,
                ];
                $key = $this->driftKey($occurrence);
            } else {
                $kept[$key] = true;
            }

            $rows[$key] ??= [
                ...$occurrence,
                'count' => 0,
                'seen_at' => $receivedAt,
            ];
            $rows[$key]['count'] += $occurrence['count'];
        }

        return array_values($rows);
    }

    /**
     * Get the keys of the drift rows the store keeps apart, beside the overflow rows.
     *
     * @return array<string, true>
     */
    protected function keptDrift(SQLite3 $connection): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT kind, type, v, detail FROM drift WHERE detail <> :overflow');

        $statement->bindValue(':overflow', static::DRIFT_OVERFLOW);

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $kept = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $kept[$this->driftKey($row)] = true;
        }

        $statement->close();

        return $kept;
    }

    /**
     * Get the key a drift row is kept under.
     *
     * @param  array<string, mixed>  $drift
     */
    protected function driftKey(array $drift): string
    {
        return implode("\0", [$drift['kind'], $drift['type'], $drift['v'], $drift['detail']]);
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
