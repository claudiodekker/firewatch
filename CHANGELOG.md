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
- `php artisan firewatch:doctor` says it is not implemented yet and exits with a failure, so it is never mistaken for a passed check.
- `php artisan firewatch:clear` removes all records, users and logged failures, or with `--type` one type's records, after a confirmation, and `--drop` rebuilds the store in place. A `--type` without a value is refused.
- The `rank` tool lists the groups of one record type worst first by a measure (`p95_duration`, `occurrences`, `max_memory`, `queries` and others), over a window and an optional deploy. Percentiles are nearest rank and withheld below a sample floor.
- `rank` can match a group by a substring of its label, break one group down by deploy with `group`, and continue a cut list with a `cursor`.
- The `execution` tool shows one request, command, job attempt or scheduled task in full, by id or the latest to finish: outcome, stages, a request's headers and payload, counted-versus-captured accounting, up to five exceptions with their frames, and a timeline with repeated queries collapsed.
- The `occurrences` tool lists individual records for a group, type, execution, trace, job or user, newest first or by duration, memory or queries, with filters that fit each type, a baseline against the median or 95th percentile, and a cursor for the rest.
- The `trace` tool follows one trace or queued job: the executions it touched in start order, and for every queued job its dispatch, its attempts and the wait before each, noting where a dispatch or attempt was not recorded. The `execution` tool now offers it as a next call.
- The `detect` tool runs problem shapes and answers each with findings, clean or not evaluated, over how many records it examined. The first shape, `failing-routes`, lists the routes with a request at or above a status (400 by default), worst first, with the failing statuses and the latest failing execution. The `overview` tool now lists every shape's verdict.
- `detect` also runs `n-plus-one`: the read query one execution ran three or more times (`threshold` sets the runs, from 2), with how many different bindings it had, where it was called from and the worst execution. Executions that began before their queries were cleared or pruned are left out.
- `detect` also runs `database-bound`: the routes whose typical share of a request's time in queries is at least `threshold` percent (60 by default) and whose typical request takes 5 ms or more, with the three queries that took longest. The typical share is the median from three requests, otherwise the aggregate.
