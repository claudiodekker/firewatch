# Firewatch

[![CI](https://github.com/claudiodekker/firewatch/actions/workflows/ci.yml/badge.svg)](https://github.com/claudiodekker/firewatch/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/claudiodekker/firewatch)](https://packagist.org/packages/claudiodekker/firewatch)

Firewatch is local telemetry for AI debugging. It captures what Laravel Nightwatch's sensors collect into a local SQLite file and lets an AI assistant query it over the Model Context Protocol. There is no dashboard, and nothing leaves your machine.

## Quick start

```bash
composer require --dev claudiodekker/firewatch
claude mcp add firewatch -- php artisan firewatch:server
```

Use the app in `local`, then ask the assistant what happened. Nothing exists to ask until the app has handled a request, command, job or task. Requests driven from a test suite are not recorded, so exercise the app over HTTP (serve, Herd, Sail, a browser).

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

## Requirements

- PHP 8.3 or newer
- Laravel 12.41.1 or newer, or Laravel 13
- `laravel/nightwatch` `^1.30.2`
- `ext-sqlite3` with SQLite 3.38.0 or newer

## Install

Install with `composer require --dev claudiodekker/firewatch`. Nothing else is needed: no install command, migration, publish or `.env` line. The provider is auto-discovered and registers Nightwatch itself.

Nightwatch arrives as a dependency of Firewatch, so it is dev-only unless your application requires `laravel/nightwatch` itself. An application that reports to the hosted service in production already does, and is unaffected. If your own `laravel/nightwatch` constraint excludes `^1.30.2`, the install fails: raise it.

Publishing the configuration is optional: `php artisan vendor:publish --tag=firewatch-config`.

## Connect an assistant

Every client runs `php artisan firewatch:server` in the application's environment, with the project root as working directory. Where a client does not guarantee the working directory, use the absolute form: `<php> /absolute/path/artisan firewatch:server`.

| Client | Configuration | Note |
|---|---|---|
| Claude Code | `.mcp.json` or `~/.claude.json` | `--scope project` writes a committable `.mcp.json` |
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` | absolute paths only; quit and reopen fully after editing |
| Cursor | `.cursor/mcp.json` or `~/.cursor/mcp.json` | `mcpServers` with `type: "stdio"`; no documented `cwd`, so an absolute path |
| VS Code | `.vscode/mcp.json` | top-level `servers`, not `mcpServers`, with `cwd` |
| Codex | `~/.codex/config.toml`, or `.codex/config.toml` in a trusted project | `[mcp_servers.firewatch]` with `command`, `args`, `cwd` |
| Sail / Docker | the client's own file | command `vendor/bin/sail`, args `artisan firewatch:server` |
| Herd, Valet, local PHP | the client's own file | use the project's PHP binary, so `ext-sqlite3` and the SQLite version match the application |

Claude Code:

```bash
claude mcp add firewatch -- php artisan firewatch:server
```

Claude Desktop:

```json
{"mcpServers": {"firewatch": {"command": "/absolute/path/to/php", "args": ["/absolute/path/to/project/artisan", "firewatch:server"]}}}
```

Cursor:

```json
{"mcpServers": {"firewatch": {"type": "stdio", "command": "php", "args": ["/absolute/path/to/project/artisan", "firewatch:server"]}}}
```

VS Code:

```json
{"servers": {"firewatch": {"type": "stdio", "command": "php", "args": ["artisan", "firewatch:server"], "cwd": "${workspaceFolder}"}}}
```

Codex:

```toml
[mcp_servers.firewatch]
command = "php"
args = ["artisan", "firewatch:server"]
cwd = "/absolute/path/to/project"
```

The server must share a kernel and filesystem with the application. A server on the host reading a bind-mounted store file from a container is unsupported. Restart the assistant session after `composer update` or `.env` changes. Queue workers, Octane and Horizon processes read configuration once at boot and need a restart after a configuration change. Run by hand in a terminal, the server waits for a protocol message on stdin (end with Ctrl-D). That is how to look for stray output before the first message.

## Local development only

- Firewatch captures only when `APP_ENV` is in `FIREWATCH_ENVIRONMENTS` (default `local,testing`). Elsewhere it registers nothing and Nightwatch is untouched.
- `composer install --no-dev` leaves it absent.
- Never list `production` or `prod`. If you do, `firewatch:doctor` flags it.
- It vetoes every Nightwatch transmit, so no hosted reporting happens from a capture environment.

## What is captured and stored

- Every execution (request, command, job, scheduled task) is recorded in the capture environments. Nightwatch's sampling and filtering cost controls are overridden. In-code opt-outs (`Nightwatch::ignore()`, `pause()`, `dontSample()` and `reject*` callbacks) are honored.
- Nothing is transmitted. Every Nightwatch transmit is cancelled.
- **Firewatch never redacts URL query strings, SQL, query bindings, log messages, context and extra, exception messages, the Laravel Context or outgoing-request query strings.** For redaction, use Nightwatch's `redact*` callbacks, which always apply, and the opt-in `capture.redact_payload_fields` and `capture.redact_headers`, which are empty by default.
- A request's payload is recorded only for a 500 response by default (`capture.request_payload`). Request headers are recorded for every request. Response headers and bodies are never recorded.
- The store is `storage/firewatch/firewatch.sqlite`, mode `0600` inside a `0700` directory that carries its own VCS ignore file.
- Test runs share the store. `testing` is a capture environment, so a test suite's commands and jobs write beside your browsing. `php artisan firewatch:clear` separates them.
- Do not commit, share or attach the store file.

Locally, the value that redaction would hide is usually the answer the assistant needs.

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

## When an empty answer means blind, not clean

- Test-suite and other console processes record no request records.
- An outgoing HTTP call that received no response (timeout, connection failure) leaves no record.
- A request's payload is stored only for a 500 response.
- `lazy_loads`, `hydrated_models`, `files_read` and `files_written` are always 0, and mail and notification `failed` is always false. Zero does not mean none: use the `n-plus-one` detector.
- Jobs on the sync connection have no attempt record.
- Logs written straight to a named channel are not captured.
- Work still running is absent. An execution is recorded when it finishes.
- Anything the application opted out of (pause, ignore, reject callbacks, never-sample) and processes killed before flushing are absent.
- Under Octane the request bootstrap stage is zero, so do not compare durations across Octane and PHP-FPM.
- A memory peak is the whole process's peak. In a long-lived worker every execution inherits what the process already held, so compare executions to each other, not to an absolute number.

Answers state these themselves, and `clean` means "none among what was captured".

## Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `FIREWATCH_ENABLED` | `true` | the only on/off switch |
| `environments` | `FIREWATCH_ENVIRONMENTS` | `local,testing` | environments where Firewatch captures |
| `database` | `FIREWATCH_DATABASE` | `storage/firewatch/firewatch.sqlite` | store path, not under `public/` |
| `busy_timeout` | `FIREWATCH_BUSY_TIMEOUT` | `300` | milliseconds a write waits for a lock, 0 to 5000 |
| `retention.age` | `FIREWATCH_RETENTION_AGE` | `7d` | maximum record age (`30m`, `12h`, `7d`, `2w`) |
| `retention.records` | `FIREWATCH_RETENTION_RECORDS` | `100000` | maximum record count |
| `deploy` | `FIREWATCH_DEPLOY` | unset | deploy label when Nightwatch has none |
| `capture.logs` | `FIREWATCH_CAPTURE_LOGS` | `true` | capture logs |
| `capture.request_payload` | `FIREWATCH_CAPTURE_REQUEST_PAYLOAD` | `true` | payload for 500 responses |
| `capture.redact_payload_fields` | `FIREWATCH_REDACT_PAYLOAD_FIELDS` | empty | payload fields to redact |
| `capture.redact_headers` | `FIREWATCH_REDACT_HEADERS` | empty | headers to redact |
| `budgets` | none | empty | performance budgets |

The environment variable beats the published file, which beats the default. The published file reads each variable, and a literal you write there replaces it. An invalid value falls back to its default and never stops capture. Issues are reported once per console process and by `firewatch:doctor`.

A specific budget beats a global one. Durations are in milliseconds and memory in MB:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'scheduled-task', 'duration' => 5000],
],
```

Raise `busy_timeout` when the app and several workers write at once. Dropped batches are recorded and the doctor reports them. Two stores must not share a directory.

## Retention and clearing

Records are kept for 7 days and 100,000 records, with a 512 MiB size backstop. The oldest go first, and only the capturing process prunes. Nothing clears automatically or from the assistant.

`php artisan firewatch:clear` clears everything. `--type=<type>` clears one record type, `--drop` drops and rebuilds the store, and `--force` skips the confirmation. Answers tell the assistant what was pruned or cleared, so a shortened history is never mistaken for a quiet one.

## Commands and diagnostics

| Command | Options | Purpose |
|---|---|---|
| `firewatch:server` | `--list`, `--json` | the stdio MCP server; `--list` prints the tools without a session, `--json` with it |
| `firewatch:doctor` | `--json` | checks the install, configuration and store; `--json` for machines |
| `firewatch:clear` | `--type`, `--drop`, `--force` | clears the store |

The doctor prints `[ok]`, `[warn]`, `[fail]` or `[info]` per check, with a one-line fix for warnings and failures. It exits 1 only when a check fails and changes nothing. It does not exist when Firewatch is stepped aside, so the missing command is the signal.

| Symptom | Fix |
|---|---|
| `Command "firewatch:doctor" is not defined` (or any `firewatch:` command) | The environment is not in `FIREWATCH_ENVIRONMENTS` |
| The client shows the server as failed | Run `php artisan firewatch:doctor` |
| The assistant sees no data | Run the doctor and read `store-activity` and `mode` |
| Garbled or failed handshake | Application providers print output at boot: run the launch command by hand and look for output before the first message |
| Behavior changed after upgrading | Restart the assistant session |

## Compatibility

PHP, Laravel, Nightwatch and SQLite are as in Requirements. The verified Nightwatch line is 1.30 (any 1.30.x). A higher minor works, and answers say it is unverified. The doctor warns about a SQLite release with a known write-ahead-log reset bug and says the mitigation is active. Linux, macOS and Windows are supported. On Windows the doctor reports file modes as "not applicable". The server must run on the same host and kernel as the application.

In a capture environment Firewatch takes over Nightwatch's registration, so hosted Nightwatch and Firewatch do not run in the same environment. In production Firewatch is absent and Nightwatch behaves as normal. For production monitoring, use Nightwatch itself.

## Uninstall and upgrade

To uninstall, run `composer remove --dev claudiodekker/firewatch`. Then delete the store directory (`storage/firewatch/` by default: the store, its lock file and its ignore file) and any client entry. Nightwatch is removed too unless the application requires it directly.

To upgrade, run `composer update`, then restart the assistant session. A release that changes the store schema rebuilds the store in place on the next write and history is not carried over, so there is nothing to migrate.

## Contributing and testing

- Run the suite with `vendor/bin/pest`.
- The scenario tests drive the real Nightwatch sensors.
- A pull request that changes behavior updates the README and the changelog.

## Changelog and license

The changelog and the license live in `CHANGELOG.md` and `LICENSE.md`.
