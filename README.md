# Firewatch

Firewatch is local telemetry for AI debugging: it captures what Laravel Nightwatch's sensors collect into a local SQLite file and lets an AI assistant query it over the Model Context Protocol. No dashboard; nothing leaves the machine.

## Install

Install is `composer require --dev claudiodekker/firewatch`:

```bash
composer require --dev claudiodekker/firewatch
```

## Connecting an assistant

An assistant's client starts Firewatch's MCP server with one launch command, run in the application's environment with the project root as working directory:

```bash
php artisan firewatch:server
```

In Claude Code that is `claude mcp add firewatch -- php artisan firewatch:server`. The server speaks MCP over stdio and exits when its input ends. It is a `firewatch:` process, so it is always Off and never captures its own reads, and it opens nothing in the store until a tool asks. Only `overview` exists so far: it answers that no store has been written yet, that the store holds no records, or how many requests it holds.

The server starts only through `firewatch:server`. Firewatch registers no laravel/mcp handle, so never use `mcp:start` or `mcp:inspector` with it. To see what an assistant would get from `tools/list` without a session, run `php artisan firewatch:server --list`, or `--list --json` for the full descriptions and schemas.

Anything an application's providers print while the server boots goes to stderr, so it can't corrupt the protocol on stdout. Output printed before Firewatch's provider registers, from `bootstrap/app.php`, a config file or a package provider registered earlier, still reaches stdout and breaks the handshake. Run the launch command by hand to look for it; end with Ctrl-D.

## Modes

Firewatch decides once per process, when it registers, how it runs:

- **Stepped aside** when the environment is not in `environments`. It registers no command, listener or publish tag and writes no Nightwatch setting, so Nightwatch behaves as if Firewatch were absent. A console process reports this once; web processes stay silent. `Command "firewatch:doctor" is not defined` means the environment is not in `FIREWATCH_ENVIRONMENTS`.
- **Off** in a `firewatch:` command, when `enabled` is false, or when `ext-sqlite3` is missing or SQLite is older than 3.38.0. Nightwatch is disabled, its ingest is swapped for one that sends nothing, and nothing is captured.
- **Active** otherwise. Nightwatch is enabled and its ingest is swapped for Firewatch's, which writes what the sensors record into the store and sends nothing. A placeholder token and a loopback address nothing listens on stay behind it as a second guard.

Firewatch checks Nightwatch's ingest before it swaps it. If a Nightwatch release changed it, a console process reports that once and Nightwatch keeps its own ingest: disabled in Off, and in Active vetoed before every batch and pointed at the placeholder token and address.

Firewatch registers Nightwatch's provider itself, before Nightwatch reads its configuration. If something registered Nightwatch's provider before Firewatch's, such as another package's provider or a stale package manifest, a console process in Active or Off reports it once with the fix: remove the provider from your providers and run `php artisan package:discover`. In Active, a console process also reports once if the `IngestingEvents` event the veto listens on is missing. Neither report stops anything. Firewatch is verified against Nightwatch 1.30; a later minor or major, or a development build, still runs and is unverified.

In Active, every request, command, job and scheduled task is captured: Nightwatch's sample rates are set to 1, its `ignore_*` filters are off and its log level is `debug`, whatever the application configured. A `nightwatch` log channel the application defines itself keeps its own level. What you opt out of in code still stays out: `Nightwatch::ignore()`, `pause()`, `dontSample()`, `Sample::never()` and `Sample::rate()` on a route or a scheduled task, and every `reject*` callback. A job attempt follows the sampling of the execution that dispatched it. Exception frames always carry their source lines. When `deploy` is set it becomes Nightwatch's deployment; unset, Nightwatch resolves its own.

Logs on the default channel are captured without touching `LOG_STACK`. In Active, with `capture.logs` on, Firewatch makes the default a `firewatch` stack of Nightwatch's `nightwatch` channel and your original default, so your logging keeps working as configured. Nightwatch's channel comes first because a handler such as the `null` channel's stops the handlers after it. When the default channel already includes `nightwatch`, directly or in a nested stack, nothing is wrapped, so no log is recorded twice. Logs sent to a named channel, such as `Log::channel('slack')`, are not captured. Nor are logs from a default stack that lists `nightwatch` after a channel that stops the handlers after it. An application channel of its own named `firewatch` is left alone, and then nothing is wrapped. With `capture.logs` off, or in Off, logging is left alone.

Queries carry their bindings, which Nightwatch never records. In Active, Firewatch listens to `QueryExecuted` around Nightwatch's own query listener and gives each query record the values its query sent to the database, as a JSON list in the `queries` view's `bindings` column. A binding set is paired only to the record Nightwatch writes for that very query, and only when its SQL and connection still match, so a query Nightwatch never records, because it was ignored, rejected or paused, lends its bindings to no other. A query record without a certain pair, such as one whose SQL a `redactQueries()` callback changed, has `bindings` NULL. Bindings are stored raw, as a list in the order the query was given them, named bindings included: a string is cut to 1,024 bytes with the truncation marker, one that is not UTF-8 reads `[binary N bytes]`, an infinite or NaN float is stored as its string (`INF`, `NAN`), a stringable value as its string and any other value, such as an array or an object, as its type in brackets (`[array]`). A query whose bindings can't be read, and every query while Nightwatch's provider was registered before Firewatch's, has `bindings` NULL. A query's bindings keep up to 16,384 bytes of JSON, and the ones that don't fit are replaced by one `... [N more bindings]` element.

Redaction is relaxed, because the store never leaves your machine and the redacted value is usually the answer. In Active, Nightwatch's built-in lists are off, so payload fields such as `password` and headers such as `Authorization` and `Cookie` are stored as sent. List payload fields in `capture.redact_payload_fields` to replace their string values, matched by exact key at any depth, and headers in `capture.redact_headers`, matched in any case. A replaced value reads `[N bytes redacted]`, `Authorization` keeps a registered scheme (`Bearer [N bytes redacted]`) and `Cookie` keeps each cookie's name. Header redaction runs before your own `Nightwatch::redactRequests()` callbacks, which still apply, as do every other `redact*` callback. Firewatch never redacts URL query strings, SQL, log text, exception messages or the Context. The `php-auth-*` headers are never stored, and userinfo is stripped from outgoing request URLs. Payloads are kept for responses with status 500, while `capture.request_payload` is on.

In Active and Off, `NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN` and the `NIGHTWATCH_INGEST_*` variables have no effect. In Active, neither have the `NIGHTWATCH_*_SAMPLE_RATE`, `NIGHTWATCH_IGNORE_*`, `NIGHTWATCH_LOG_LEVEL`, `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE`, `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD`, `NIGHTWATCH_REDACT_PAYLOAD_FIELDS` and `NIGHTWATCH_REDACT_HEADERS` variables, nor `NIGHTWATCH_DEPLOY` while `deploy` is set. Queue workers, Octane and Horizon read the mode at boot, so restart them after changing it.

## Configuration

Every key except `budgets` can be set from an environment variable, so publishing the file is optional:

```bash
php artisan vendor:publish --tag=firewatch-config
```

| Key | Environment variable | Default | Accepted values |
|---|---|---|---|
| `enabled` | `FIREWATCH_ENABLED` | `true` | `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off` |
| `environments` | `FIREWATCH_ENVIRONMENTS` | `local,testing` | names of letters, digits, `_`, `.` and `-`, comma-separated or a list |
| `database` | `FIREWATCH_DATABASE` | `storage/firewatch/firewatch.sqlite` | a file path outside `public/`; relative paths resolve against the base path |
| `busy_timeout` | `FIREWATCH_BUSY_TIMEOUT` | `300` | milliseconds, 0 to 5000 |
| `retention.age` | `FIREWATCH_RETENTION_AGE` | `7d` | digits then `s`, `m`, `h`, `d` or `w` |
| `retention.records` | `FIREWATCH_RETENTION_RECORDS` | `100000` | 1 to 10000000 |
| `deploy` | `FIREWATCH_DEPLOY` | unset | any string, cut at 255 bytes |
| `capture.logs` | `FIREWATCH_CAPTURE_LOGS` | `true` | a boolean |
| `capture.request_payload` | `FIREWATCH_CAPTURE_REQUEST_PAYLOAD` | `true` | a boolean |
| `capture.redact_payload_fields` | `FIREWATCH_REDACT_PAYLOAD_FIELDS` | none | comma-separated or a list |
| `capture.redact_headers` | `FIREWATCH_REDACT_HEADERS` | none | comma-separated or a list |
| `budgets` | none | none | a list of budget entries |

An invalid value never stops capture: that key alone falls back to its default, and console commands report the problem once.

On SQLite releases with the WAL-reset bug (3.7.0 up to 3.44.6, 3.45.0 up to 3.50.7 and 3.51.0 up to 3.51.3), every write is serialized through an exclusive lock on `firewatch.sqlite.lock` beside the store, created `0600`. The lock is polled every 5 ms within `busy_timeout`, the write gets what is left of it, and the connection is opened and closed inside the lock for each batch. A batch that can't take the lock in time is dropped as `busy`. On other releases no lock file is taken and one connection is kept per process.

`busy_timeout` bounds only the capture side: a batch that can't be written within it is dropped rather than stalling the request, and `0` drops it at once. A batch that can't be stored, because the store is busy past `busy_timeout`, full, corrupt, another schema version's or not a Firewatch store at all, is dropped, never retried or thrown into your application. Each dropped batch adds one line to `failures.jsonl` beside the store, unless the store's directory is missing or another process holds the file's lock: `at` (Unix seconds), `kind` (`busy`, `full`, `corrupt`, `foreign` for a file that is not a Firewatch store, `schema` for another schema version's store, `io` for a filesystem error, or `other`), `code` (the SQLite result code, or null), `message` and `dropped` (its record count). The file keeps the latest 100 lines, so older drops are no longer counted there. A process reports its first dropped batch through the exception handler, and a failure to write the line or to report is swallowed.

Give each store its own directory: two stores in one directory would share their companion files. The first captured batch creates the directory, readable only by its owner, with a `.gitignore` that keeps it out of commits, and the store file inside it, also readable only by its owner. Reading never creates anything. The store is a plain SQLite file, not a Laravel database connection, so capturing it records none of its own queries.

Every record lands in one `records` table under Nightwatch's own field names, with its type-specific fields in a JSON `data` column. Twelve views read it per type (`requests`, `commands`, `job_attempts`, `scheduled_tasks`, `queries`, `exceptions`, `logs`, `cache_events`, `mail`, `notifications`, `outgoing_requests` and `queued_jobs`), with each field as its own column. A cache event's kind is `event` rather than the wire's `type`, and `started_at` is when a record started: mail, notifications and queued jobs, which Nightwatch stamps when they end, start one duration earlier.

Signed-in users are not records. Each one Nightwatch sees is kept once in a `users` table with its `id`, `name`, `username`, when it was `first_seen` and when it was `last_seen`; a later sighting updates everything but `first_seen`. A user record without an id stays in `records` instead.

Nothing Nightwatch sends is dropped. Every difference from the fields and types Firewatch was built against is counted in a `drift` table by `kind`, record `type`, version `v` and `detail`, with how often and when it was first and last seen: a record of an unknown type (`unknown_type`) or version (`unknown_version`), a field Firewatch doesn't know (`unknown_field`, kept in `data`) or that is missing (`missing_field`, stored as NULL), a field of an unexpected type (`structure`, stored as sent), each batch captured with a Nightwatch release off the verified 1.30 line (`version`), and a process's first batch when Nightwatch's provider was registered before Firewatch's (`structure`, detail `provider order`). Input that can't be read as a record, such as one that isn't an object, has no string `t` or can't be encoded, is kept as a `records` row holding only its `type` and an `error` in `data`, and counted as `structure`. The table keeps at most 500 rows; new drift beyond that is added to one `... [overflow]` row per kind. A `meta` table keeps the Nightwatch release of the latest batch and whether it is verified.

Long values are cut, never dropped. A string over 65,535 bytes, in a common column or in `data`, is cut on a UTF-8 character boundary and ends with `... [truncated, N bytes total]`, where N is its original length; the marker counts toward the 65,535 bytes. Fields Nightwatch sends as JSON strings (the exception `trace`, request `headers` and `payload`, `context` and `extra`) are kept whole, including one Nightwatch already cut mid-value. When a record's `data` is still over 1 MiB, its largest strings are cut to 4,096 bytes, largest first, until it fits, and then the code snippets in an exception's trace frames are set to null. A record still over 1 MiB after that is kept as it is. Nightwatch cuts some fields at 65,535 bytes itself without a marker (log and exception messages, outgoing request URLs); those arrive at the limit and are kept as sent, unless its cut split a character, whose replacement pushes them over and gets them the marker.

A budget entry names an execution type (`request`, `command`, `job-attempt` or `scheduled-task`), optional matchers (`methods` and `path` for requests, `name` otherwise) and a `duration` ceiling in milliseconds, a `memory` ceiling in MB, or both:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'request', 'duration' => 300, 'memory' => 32],
    ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
    ['type' => 'scheduled-task', 'duration' => 5000],
],
```
