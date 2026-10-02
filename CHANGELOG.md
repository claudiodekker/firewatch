# Changelog

## Unreleased

### Added

- Install as a dev dependency. Firewatch registers Nightwatch's provider and `Nightwatch` alias itself, and has an optional `config/firewatch.php` (publish tag `firewatch-config`) whose keys, except `budgets`, can be set from `FIREWATCH_*` environment variables. An invalid value falls back to its default.
- One mode per process: Active, Off or stepped aside. Active stores every batch Nightwatch's sensors record in a local SQLite file and sends nothing; the ingest swap is checked by reflection and guarded by a veto on `IngestingEvents`.
- Capture of every request, command, job and scheduled task, with Nightwatch's sampling and filters overridden, exception source lines, optional deploy identity, default-channel logs, and query bindings. Redaction is off by default, with opt-in lists for payload fields and headers.
- Every record type is mapped through one contract table into common columns and a JSON `data` column, with a view per type. Signed-in users go to a `users` directory. Differences from the contract are counted in a `drift` table, and nothing is dropped.
- Long values are cut with a truncation marker, and an oversized record's `data` is shrunk before it is stored.
- Failed batches are dropped, never retried, and logged to `failures.jsonl`. The writer survives forks, replaced or deleted files, SQLite's WAL-reset bug, schema mismatches and damaged files.
- Retention by age and record count, plus a size backstop, with coverage markers so answers state how far their history reaches.
- `php artisan firewatch:server` runs the MCP server over stdio (`--list` prints its tools), with the `overview` tool. Answers share one envelope in markdown or JSON, a strict time grammar, closed error codes, size bounds, coverage, and structural and condition blind spots.
- `php artisan firewatch:clear` removes all records or one type's records after a confirmation, and `--drop` rebuilds the store in place.
