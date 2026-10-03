# Firewatch

Firewatch is local telemetry for AI debugging. It stores what Laravel Nightwatch's sensors record in a SQLite file on your machine, and lets an AI assistant query it over the Model Context Protocol. There is no dashboard, and nothing leaves the machine.

## Install

```bash
composer require --dev claudiodekker/firewatch
```

Firewatch registers Nightwatch for you. It needs `ext-sqlite3` with SQLite 3.38.0 or later.

## Connect an assistant

Your assistant starts the server itself, from the project root:

```bash
php artisan firewatch:server
```

In Claude Code:

```bash
claude mcp add firewatch -- php artisan firewatch:server
```

The server speaks MCP over stdio. Use only this command to start it, never `mcp:start` or `mcp:inspector`. To see the tools an assistant would get, run `php artisan firewatch:server --list`.

Four tools exist so far:

- `overview` counts what the store holds.
- `rank` lists the worst groups of one record type by a measure such as `p95_duration`, over a time window, optionally matched by label, split by deploy and paged with a cursor.
- `occurrences` lists individual records for a group, type, execution, trace, job or user, newest first or by duration, memory or queries, with filters that fit each type and a cursor for the rest.
- `execution` shows one request, command, job attempt or scheduled task in full: its outcome, stages, exceptions with their frames and a timeline of its children.

Every tool answers in markdown, or in JSON with `format: json`, and each answer says which records it read and what Firewatch can't see.

## What gets captured

In the `local` and `testing` environments, Firewatch captures every request, command, job and scheduled task, with their queries, logs, exceptions, cache events, mail, notifications and outgoing requests. It overrides Nightwatch's sampling and filters so nothing is skipped, but anything you opt out of in code (`Nightwatch::ignore()`, `pause()`, `dontSample()`, `reject*` callbacks) still stays out. Query records also get their bindings, which Nightwatch itself never records.

Nothing is redacted by default, because the store never leaves your machine and the redacted value is often the answer. Passwords, `Authorization` headers and cookies are stored as sent, so treat the store as sensitive. To redact, list payload fields in `FIREWATCH_REDACT_PAYLOAD_FIELDS` and headers in `FIREWATCH_REDACT_HEADERS`.

Nightwatch's own settings for enabling, tokens, sampling, filtering and redaction have no effect while Firewatch runs. Restart queue workers, Octane and Horizon after changing Firewatch's configuration.

## Modes

Firewatch decides once per process how it runs:

- **Active** captures. Nightwatch is enabled, but its batches go to the store and never to Nightwatch's servers.
- **Off** captures nothing. A `firewatch:` command is always Off, as is any process where `FIREWATCH_ENABLED` is false or SQLite is missing or too old.
- **Stepped aside** applies outside `FIREWATCH_ENVIRONMENTS`. Firewatch registers nothing, so Nightwatch behaves as if Firewatch were absent. If a `firewatch:` command is "not defined", this is why.

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

An invalid value never stops capture: that key falls back to its default, and console commands report the problem once.

A budget entry names an execution type (`request`, `command`, `job-attempt` or `scheduled-task`), optional matchers (`methods` and `path` for requests, `name` otherwise) and a `duration` ceiling in milliseconds, a `memory` ceiling in MB, or both. No tool reads budgets yet, so they change no answer:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
],
```

## The store

The store is a plain SQLite file, created by the first captured batch in a directory only you can read, with a `.gitignore` that keeps it out of commits. Give each store its own directory.

Records older than `retention.age` are pruned, and the store is trimmed when it holds more than `retention.records`. A hard size limit of 512 MiB sits behind both. Answers say from when their history is complete, so a pruned or cleared period never reads as quiet.

Capture never slows down or breaks your application. A batch that can't be written within `busy_timeout` is dropped, and the drop is noted in `failures.jsonl` beside the store, which answers then report. A store from another Firewatch version is rebuilt, and a damaged one is moved aside as `firewatch.sqlite.corrupt`. A file that isn't Firewatch's is never touched.

## Commands

| Command | What it does |
|---|---|
| `firewatch:server` | Runs the MCP server over stdio. `--list` prints its tools. |
| `firewatch:clear` | Removes every record, or with `--type=<type>` one type's records, after a confirmation. `--force` skips the confirmation. |
| `firewatch:clear --drop` | Rebuilds the store from scratch, including its diagnostics. Use it when the store is damaged or from another version. |
| `firewatch:doctor` | Not implemented yet. It checks nothing and exits with a failure. |

## Troubleshooting

**The assistant can't connect.** Something printed to stdout before Firewatch loaded, from `bootstrap/app.php`, a config file or an earlier package provider. Run `php artisan firewatch:server` by hand to find it, and end with Ctrl-D.

**Firewatch reports that Nightwatch's provider registered first.** Remove Nightwatch's provider from your providers and run `php artisan package:discover`.

**Firewatch reports an unverified Nightwatch release.** Firewatch is verified against Nightwatch 1.30. Later releases still work, but answers flag them.
