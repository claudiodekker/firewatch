# Firewatch

Firewatch is local telemetry for AI debugging: it captures what Laravel Nightwatch's sensors collect into a local SQLite file and lets an AI assistant query it over the Model Context Protocol. No dashboard; nothing leaves the machine.

## Install

Install is `composer require --dev claudiodekker/firewatch`:

```bash
composer require --dev claudiodekker/firewatch
```

## Modes

Firewatch decides once per process, when it registers, how it runs:

- **Stepped aside** when the environment is not in `environments`. It registers no command, listener or publish tag and writes no Nightwatch setting, so Nightwatch behaves as if Firewatch were absent. A console process reports this once; web processes stay silent. `Command "firewatch:doctor" is not defined` means the environment is not in `FIREWATCH_ENVIRONMENTS`.
- **Off** in a `firewatch:` command, when `enabled` is false, or when `ext-sqlite3` is missing or SQLite is older than 3.38.0. Nightwatch is disabled, its ingest is swapped for one that sends nothing, and nothing is captured.
- **Active** otherwise. Nightwatch is enabled and its ingest is swapped for one that sends nothing. A placeholder token and a loopback address nothing listens on stay behind it as a second guard.

Firewatch checks Nightwatch's ingest before it swaps it. If a Nightwatch release changed it, a console process reports that once and Nightwatch keeps its own ingest: disabled in Off, and pointed at the placeholder token and address in Active.

In Active and Off, `NIGHTWATCH_ENABLED`, `NIGHTWATCH_TOKEN` and the `NIGHTWATCH_INGEST_*` variables have no effect. Queue workers, Octane and Horizon read the mode at boot, so restart them after changing it.

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

Give each store its own directory: two stores in one directory would share their companion files.

A budget entry names an execution type (`request`, `command`, `job-attempt` or `scheduled-task`), optional matchers (`methods` and `path` for requests, `name` otherwise) and a `duration` ceiling in milliseconds, a `memory` ceiling in MB, or both:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'request', 'duration' => 300, 'memory' => 32],
    ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
    ['type' => 'scheduled-task', 'duration' => 5000],
],
```
