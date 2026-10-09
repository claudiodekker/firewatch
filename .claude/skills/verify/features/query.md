# Query

`query` runs one read-only SQL statement of the assistant in a separate PHP process that boots no framework. That process opens the store read-only under a closed authorizer and a function allow-list, and it is killed at a 10-second deadline. Values come back raw: times in epoch seconds, durations in microseconds. Rows are positional to `columns`, and duplicate names are kept. `coverage.types_read` lists the record types the authorizer saw the statement read. Coverage is read in a second snapshot after the child returns. A refusal is a plain-text error: `not_allowed`, `invalid_sql`, `aborted`, `unavailable` or `failed`. It takes `sql` and `limit` (1 to 500, default 50).

## Sub-features

- `query-rows` answers the rows of a `SELECT`, `WITH ... SELECT`, `VALUES` or `EXPLAIN` statement, in a markdown table or as JSON lists, and in a `.result.elapsed_ms` that is a whole number.
- `query-types-read` derives `.coverage.types_read` from what the statement read. A view gives its type. `records` read directly, or through a common table expression, gives all twelve types. `users` gives `user`. `VALUES` and `meta` give none.
- `query-limit` returns exactly `limit` rows as `complete`. One row more is `stop: limit`, with a `limit` truncated entry and a `next` call of the same statement with `limit: 500`.
- `query-no-rows` answers no rows as `no_match` and keeps the columns.
- `query-refusal` refuses a write, a pragma, `ATTACH`, a second statement, a table outside the readable set and a function off the allow-list as `not_allowed`, naming what was refused. It never echoes the SQL.
- `query-deadline` kills a statement still running at 10 seconds and answers `aborted`, and the server carries on.
- `query-store` answers a missing or unusable store the way every tool does, without spawning a child.

## How to get to it (user POV)

- The ladder has no tool for the developer's question, such as which request headers were sent. The assistant calls `query` with its own statement and reads the rows, the types it read and the blind spots of those types.

## Driving it with app.sh

Preconditions:

- A fresh run. `app.sh mcp <run> query '{"sql":"SELECT 1"}'` answers `Nothing to report: no store has been written yet.` before any request.
- Then `app.sh get <run>` for `/`, `/products` and `/catalog`, which store 30 records.

- **Rows.** `app.sh mcp <run> query '{"sql":"SELECT type, count(*) AS n FROM records GROUP BY type ORDER BY n DESC"}'` prints `Returned 3 rows (2 columns).`, a table under `### rows` with `query | 25`, `request | 3` and `cache-event | 2`, the note `Values are raw: times are epoch seconds, durations microseconds.`, and the blind spots of all twelve types.
- **Types read.** `{"sql":"SELECT j.key, count(*) AS n FROM requests, json_each(requests.headers) AS j GROUP BY j.key ORDER BY j.key","format":"json"}` has `.result.rows` `[["accept",3],["host",3],["user-agent",3]]` and `.coverage.types_read` `["request"]`. `{"sql":"WITH c AS (SELECT id FROM requests) SELECT count(*) AS n FROM c","format":"json"}` has `.result.rows` `[[3]]` and `.coverage.types_read` `["request"]`.
- **Limit.** `{"sql":"SELECT id FROM requests","limit":2,"format":"json"}` has two rows, `.result.stop` `limit`, one `truncated` entry with reason `limit`, and `.next[0].arguments` `{"sql":"SELECT id FROM requests","limit":500}`. Run that call. It returns all three rows with `stop: complete`.
- **Refusal.** `{"sql":"DELETE FROM records"}` prints `error: not_allowed` and ``action `DELETE` is not allowed.``. `{"sql":"SELECT 1; DROP TABLE records"}` prints `one statement only.`, and `{"sql":"SELECT * FROM sqlite_master"}` prints ``table `sqlite_master` is not readable.``. Afterwards `md5 -q .verify/runs/<run>/store/firewatch.sqlite` is unchanged and `app.sh store <run> "SELECT count(*) FROM records"` still prints 30.
- **Deadline.** `{"sql":"WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c) SELECT count(*) FROM c"}` prints `error: aborted` and `The query process ended unexpectedly.` about 10 seconds after the request. Then `pgrep -fl Sql/Child/run.php` prints nothing.

## Gotchas

- `app.sh mcp` exits `1` when the answer is an error, so chain a refusal with `;`, not `&&`.
- A statement that reads `records` attaches the blind spots of all twelve types, because the authorizer can't tell which types a direct read of `records` touched.
- Text that holds a NUL byte comes back cut at the NUL, which is how PHP's SQLite driver reads it. A blob comes back as `<blob N bytes>`.
