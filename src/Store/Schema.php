<?php

namespace ClaudioDekker\Firewatch\Store;

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
     * The statements that create the schema, in order.
     */
    protected const STATEMENTS = [
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
    ];

    /**
     * Get the statements that create the schema, in order.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return static::STATEMENTS;
    }
}
