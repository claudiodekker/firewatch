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

Logs on the default channel are captured without touching `LOG_STACK`. In Active, with `capture.logs` on, Firewatch makes the default a `firewatch` stack of Nightwatch's `nightwatch` channel and your original default, so your logging keeps working as configured. Nightwatch's channel comes first because a handler such as the `null` channel's stops the handlers after it. When the default channel already includes `nightwatch`, directly or in a nested stack, nothing is wrapped, so no log is recorded twice. Logs sent to a named channel, such as `Log::channel('slack')`, are not captured. With `capture.logs` off, or in Off, logging is left alone.

In Active and Off, `NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN` and the `NIGHTWATCH_INGEST_*` variables have no effect. In Active, neither have the `NIGHTWATCH_*_SAMPLE_RATE`, `NIGHTWATCH_IGNORE_*`, `NIGHTWATCH_LOG_LEVEL` and `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE` variables, nor `NIGHTWATCH_DEPLOY` while `deploy` is set. Queue workers, Octane and Horizon read the mode at boot, so restart them after changing it.

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

`busy_timeout` bounds only the capture side: a batch that can't be written within it is dropped rather than stalling the request, and `0` drops it at once.

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
