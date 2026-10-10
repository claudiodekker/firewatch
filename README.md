# Firewatch

Firewatch is local telemetry for AI debugging. It stores what Laravel Nightwatch's sensors record in a SQLite file on your machine, and lets an AI assistant query it over the Model Context Protocol. There is no dashboard, and Firewatch itself sends nothing anywhere.

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

## Ask your assistant

| Question | Tool |
|---|---|
| What is wrong with this app right now? | `overview` |
| Which routes are slowest? | `rank` |
| Is there an N+1 anywhere? | `detect` |
| What did that failing request do, query by query? | `execution` |
| What did this user hit? | `actor` |
| Did my change make it slower? | `compare` |
| Is this job getting slower over the week? | `trend` |
| Which queries run most often? | `query` |

## Tools

| Tool | Answers |
|---|---|
| `overview` | What is wrong right now, across everything |
| `rank` | Which groups are worst by a measure |
| `detect` | Named problem shapes with evidence |
| `occurrences` | Individual records, filtered and ordered |
| `execution` | One request, command, job attempt or task in full |
| `trace` | A trace's executions and its queued-job lineage |
| `actor` | One signed-in person's work |
| `compare` | Before against after, per group |
| `trend` | A measure over equal time buckets |
| `query` | Read-only SQL over the store: one statement, 10 s limit, run in an isolated child process |
| `describe` | Schema, store facts, deploys, units, examples |
| `fingerprint` | The group id of something read in source |

Detectors: `n-plus-one`, `database-bound`, `failing-routes`, `failing-jobs`, `queue-latency`, `failing-tasks`, `exception-clusters`, `error-logs`, `failing-http`, `cache`, `memory`.

## What gets captured

In the `local` and `testing` environments, Firewatch captures every request, command, job and scheduled task, with their queries, logs, exceptions, cache events, mail, notifications and outgoing requests. It overrides Nightwatch's sampling and filters so nothing is skipped, but anything you opt out of in code (`Nightwatch::ignore()`, `pause()`, `dontSample()`, `reject*` callbacks) still stays out. Query records also get their bindings, which Nightwatch itself never records.

Nothing is redacted by default, because the store stays on your machine and the redacted value is often the answer. Passwords, `Authorization` headers and cookies are kept as sent, and the tools return them, so they reach your assistant's model provider. Treat the store as sensitive. To redact, list payload fields in `FIREWATCH_REDACT_PAYLOAD_FIELDS` and headers in `FIREWATCH_REDACT_HEADERS`.

Nightwatch's own settings for enabling, tokens, sampling, filtering and redaction have no effect while Firewatch runs. Restart queue workers, Octane and Horizon after changing Firewatch's configuration.

## Modes

Firewatch decides once per process how it runs:

- **Active** captures. Nightwatch is enabled, but its batches go to the store and never to Nightwatch's servers.
- **Off** captures nothing. A `firewatch:` command is always Off, as is any process where `FIREWATCH_ENABLED` is false or SQLite is missing or too old.
- **Stepped aside** applies outside `FIREWATCH_ENVIRONMENTS`. Firewatch registers nothing, so Nightwatch behaves as if Firewatch were absent. If a `firewatch:` command is "not defined", this is why.

## Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `FIREWATCH_ENABLED` | `true` | the only on/off switch |
| `environments` | `FIREWATCH_ENVIRONMENTS` | `local,testing` | environments where Firewatch captures |
| `database` | `FIREWATCH_DATABASE` | `storage/firewatch/firewatch.sqlite` | store path, not under `public/` |
| `busy_timeout` | `FIREWATCH_BUSY_TIMEOUT` | `300` | milliseconds a write waits for a lock, 0 to 5000 |
| `retention.age` | `FIREWATCH_RETENTION_AGE` | `7d` | maximum record age (`30m`, `12h`, `7d`, `2w`) |
| `retention.records` | `FIREWATCH_RETENTION_RECORDS` | `100000` | maximum record count |
| `deploy` | `FIREWATCH_DEPLOY` | `unset` | deploy label when Nightwatch has none |
| `capture.logs` | `FIREWATCH_CAPTURE_LOGS` | `true` | capture logs |
| `capture.request_payload` | `FIREWATCH_CAPTURE_REQUEST_PAYLOAD` | `true` | payload for 500 responses |
| `capture.redact_payload_fields` | `FIREWATCH_REDACT_PAYLOAD_FIELDS` | `empty` | payload fields to redact |
| `capture.redact_headers` | `FIREWATCH_REDACT_HEADERS` | `empty` | headers to redact |
| `budgets` | `none` | `empty` | performance budgets |

The environment variable beats the published file, which beats the default. The published file reads each variable, and a literal you write there replaces it. An invalid value falls back to its default and never stops capture. Issues are reported once per console process and by `firewatch:doctor`.

A specific budget beats a global one. Durations are in milliseconds and memory in MB:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'scheduled-task', 'duration' => 5000],
],
```

Raise `busy_timeout` when the app and several workers write at once. Dropped batches are recorded and the doctor reports them. Two stores must not share a directory.

## The store

The store is a plain SQLite file, created by the first captured batch in a directory only you can read, with a `.gitignore` that keeps it out of commits. Give each store its own directory.

Records older than `retention.age` are pruned, and the store is trimmed when it holds more than `retention.records`. A hard size limit of 512 MiB sits behind both. Answers say from when their history is complete, so a pruned or cleared period never reads as quiet.

Capture never slows down or breaks your application. A batch that can't be written within `busy_timeout` is dropped, and the drop is noted in `failures.jsonl` beside the store, which answers then report. A store from an earlier Firewatch version is rebuilt, one from a later version is left alone, and a damaged one is moved aside as `firewatch.sqlite.corrupt`. A file that isn't Firewatch's is never touched.

## Commands and diagnostics

| Command | Options | Purpose |
|---|---|---|
| `firewatch:server` | `--list`, `--json` | the stdio MCP server; `--list` prints the tools without a session, `--json` with it |
| `firewatch:doctor` | `--json` | checks the install, configuration and store; `--json` for machines |
| `firewatch:clear` | `--type`, `--drop`, `--force` | clears the store |

The doctor prints `[ok]`, `[warn]`, `[fail]` or `[info]` per check, with a one-line fix for warnings and failures. It exits 1 only when a check fails and changes nothing. It does not exist when Firewatch is stepped aside, so the missing command is the signal.

| Symptom | Fix |
|---|---|
| `Command "firewatch:doctor"` (or any `firewatch:` command) is not defined | The environment is not in `FIREWATCH_ENVIRONMENTS` |
| The client shows the server as failed | Run `php artisan firewatch:doctor` |
| The assistant sees no data | Run the doctor and read `store-activity` and `mode` |
| Garbled or failed handshake | Application providers print output at boot: run the launch command by hand and look for output before the first message |
| Behavior changed after upgrading | Restart the assistant session |

## Troubleshooting

**Something is wrong and you don't know what.** Run `php artisan firewatch:doctor`. It names the check and the fix.

**The assistant can't connect.** Something printed to stdout before Firewatch loaded, from `bootstrap/app.php`, a config file or an earlier package provider. Run `php artisan firewatch:server` by hand to find it, and end with Ctrl-D.

**Firewatch reports that Nightwatch's provider registered first.** Remove Nightwatch's provider from your providers and run `php artisan package:discover`.

**Firewatch reports an unverified Nightwatch release.** Firewatch is verified against Nightwatch 1.30. Later releases still work, but answers flag them.
