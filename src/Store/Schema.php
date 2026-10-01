<?php

namespace ClaudioDekker\Firewatch\Store;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class Schema
{
    /**
     * The schema version the store is stamped with; any other value is rebuilt, never migrated.
     */
    public const VERSION = 1;

    /**
     * The application id that marks a file as a Firewatch store ("FWTC").
     */
    public const APPLICATION_ID = 0x46575443;

    /**
     * The common columns a record fills from the wire, beside its data.
     */
    public const COLUMNS = [
        'type',
        'v',
        'started_at',
        'duration',
        'group_hash',
        'trace_id',
        'execution_id',
        'source',
        'job_id',
        'user_id',
        'deploy',
        'server',
    ];

    /**
     * The statements that create the raw table, its indexes, the user directory, the drift counts and the facts about the capture, in order.
     */
    protected const TABLES = [
        <<<'SQL'
            CREATE TABLE records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT,
                v INTEGER,
                started_at REAL,
                duration INTEGER,
                ended_at REAL AS (started_at + duration / 1e6) VIRTUAL,
                group_hash TEXT,
                trace_id TEXT,
                execution_id TEXT,
                source TEXT,
                job_id TEXT,
                user_id TEXT,
                deploy TEXT,
                server TEXT,
                data TEXT NOT NULL
            )
            SQL,
        'CREATE INDEX records_type_started ON records (type, started_at)',
        'CREATE INDEX records_started ON records (started_at)',
        'CREATE INDEX records_group ON records (group_hash) WHERE group_hash IS NOT NULL',
        'CREATE INDEX records_execution ON records (execution_id) WHERE execution_id IS NOT NULL',
        'CREATE INDEX records_trace ON records (trace_id) WHERE trace_id IS NOT NULL',
        'CREATE INDEX records_job ON records (job_id) WHERE job_id IS NOT NULL',
        'CREATE INDEX records_user ON records (user_id) WHERE user_id IS NOT NULL',
        <<<'SQL'
            CREATE TABLE users (
                id TEXT PRIMARY KEY,
                name TEXT,
                username TEXT,
                first_seen REAL,
                last_seen REAL
            )
            SQL,
        <<<'SQL'
            CREATE TABLE drift (
                kind TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT '',
                v TEXT NOT NULL DEFAULT '',
                detail TEXT NOT NULL DEFAULT '',
                count INTEGER NOT NULL,
                first_seen REAL NOT NULL,
                last_seen REAL NOT NULL,
                PRIMARY KEY (kind, type, v, detail)
            )
            SQL,
        'CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)',
    ];

    /**
     * Get the statements that create the schema, in order.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $types = array_filter(RecordType::cases(), fn (RecordType $type) => $type->view() !== null);
        $views = array_map($this->view(...), $types);

        return array_values([...static::TABLES, ...$views]);
    }

    /**
     * Get the statement that creates a type's record view, with the type's own columns named as on the wire.
     */
    protected function view(RecordType $type): string
    {
        $columns = [...$this->commonColumns($type), ...$this->dataColumns($type), 'data'];

        return sprintf(
            "CREATE VIEW %s AS SELECT %s FROM records WHERE type = '%s'",
            $type->view(),
            implode(', ', $columns),
            $type->value,
        );
    }

    /**
     * Get the common columns that apply to a type, in the raw table's order.
     *
     * @return list<string>
     */
    protected function commonColumns(RecordType $type): array
    {
        $fields = $type->fields();
        $hasDuration = in_array('duration', $fields, strict: true);

        return array_values(array_filter([
            'id',
            'v',
            'started_at',
            $hasDuration ? 'duration' : null,
            $hasDuration ? 'ended_at' : null,
            in_array('group_hash', $fields, strict: true) ? 'group_hash' : null,
            'trace_id',
            'execution_id',
            $type->source() !== null ? 'source' : 'source AS execution_source',
            in_array('job_id', $fields, strict: true) ? 'job_id' : null,
            'user_id',
            'deploy',
            'server',
        ]));
    }

    /**
     * Get the columns a type reads from its data, one per contract field without a common column and one per field Firewatch adds.
     *
     * @return list<string>
     */
    protected function dataColumns(RecordType $type): array
    {
        $names = [...array_diff(array_filter($type->fields()), static::COLUMNS), ...$type->addedFields()];

        return array_values(array_map(fn (string $name) => "json_extract(data, '$.{$name}') AS \"{$name}\"", $names));
    }
}
