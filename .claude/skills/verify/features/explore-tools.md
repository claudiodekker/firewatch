# Explore tools

Five tools let an assistant go from "what does the store hold" to one record. `overview` counts the store, `rank` lists the worst groups of a type, `occurrences` lists single records, `execution` opens one request, command or job attempt, and `trace` follows a request into the jobs it queued. Every answer comes in markdown or JSON and says what Firewatch read and can't see.

## Sub-features

- `overview-counts` counts the records by type and gives each detector's verdict.
- `overview-empty` says so when there is no store or it holds no records.
- `rank-groups` ranks the groups of one type by a measure.
- `occurrences-filter` lists records by type, status, substring or id.
- `execution-open` shows one execution with its exceptions and timeline.
- `trace-follow` lists a trace's executions in start order and each job's attempts.
- `answer-json` returns the same answer as JSON with `format: json`.

## How to get to it (user POV)

- The assistant calls `overview`, `rank`, `occurrences`, `execution` or `trace` on the Firewatch MCP server.
- Each answer's `next` entries name the follow-up call, so an assistant reaches `execution` and `trace` from ids in an earlier answer.

## Driving it with app.sh

Preconditions:

- A fresh run. For `overview-empty`, call `app.sh mcp <run> overview` before anything else. It answers `Nothing to report: no store has been written yet.` and `Store: absent`.
- Then `app.sh get <run>` for `/products`, `/purchases`, `/invoices/42` and `/exports`, and `app.sh artisan <run> queue:work --stop-when-empty --no-interaction`.

- **Overview.** `app.sh mcp <run> overview` starts with `Findings: ` and prints, in this order, `error_rate`, a `### slowest_by_total_time` table of at most ten rows, `records`, a `### records_by_type` table of twelve rows, `user_directory`, `actors`, `budgets` and a `### detectors` table of eleven rows. Its `error_rate` counts 4 requests and 1 server error.
- **Rank.** `app.sh mcp <run> rank '{"type":"request","by":"max_duration"}'` answers `Ranked 4 request groups by max_duration, worst first.` with one row per route. `/products` has `queries` 25.
- **Occurrences by status.** `app.sh mcp <run> occurrences '{"type":"request","status":"5xx","format":"json"}' | jq '.result.rows[] | {name, status: .detail.status_code}'` prints one row: `/invoices/{invoice}`, `500`.
- **Occurrences by substring.** `app.sh mcp <run> occurrences '{"type":"request","matching":"purchases","format":"json"}' | jq -r '.result.rows[0].trace_id'` prints the trace id of the `/purchases` request. Keep it for the next two steps.
- **Execution.** `app.sh mcp <run> execution '{"execution_id":"<trace id>"}'` answers `Showed the request <trace id>: outcome 200.` and its `caused` entry counts `"jobs_queued":3`. A request's trace id is its execution id.
- **Trace.** `app.sh mcp <run> trace '{"trace_id":"<trace id>"}'` answers `Trace <trace id>: 6 executions, 3 queued jobs.` The executions are the request, then `ShipOrder` `processed`, `ChargeCard` `released`, `SyncInventory` `released`, `ChargeCard` `failed` and `SyncInventory` `processed`.
- **JSON.** `app.sh mcp <run> overview '{"format":"json"}' | jq -c 'keys_unsorted'` prints `tool`, `now`, `window`, `summary`, `empty`, `result`, `coverage`, `blind_spots`, `notes`, `truncated` and `next`.

## Gotchas

- `p50_ms` and `p95_ms` are withheld with `sample_too_small` until a group has 3 records. Rank by `max_duration`, or send each request three times.
- `rank` needs `type` unless `group` is given, and `occurrences` needs at least one selector.
- Ids change every run. Read them from an answer. Never paste one from an old run.
- The 71 records are 4 requests, 51 queries, 3 queued jobs, 5 job attempts, 4 exceptions and 4 logs. Only 25 of the queries are the application's. The other 26 are the `database` queue's own, run against the run's `jobs` table. A Laravel release can change that number without Firewatch changing.
