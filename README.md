# Firewatch

Firewatch is local telemetry for AI debugging: it captures what Laravel Nightwatch's sensors collect into a local SQLite file and lets an AI assistant query it over the Model Context Protocol. No dashboard; nothing leaves the machine.

## Install

Install is `composer require --dev claudiodekker/firewatch`:

```bash
composer require --dev claudiodekker/firewatch
```

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

A budget entry names an execution type (`request`, `command`, `job-attempt` or `scheduled-task`), optional matchers (`methods` and `path` for requests, `name` otherwise) and a `duration` ceiling in milliseconds, a `memory` ceiling in MB, or both:

```php
'budgets' => [
    ['type' => 'request', 'methods' => ['POST'], 'path' => 'checkout/*', 'duration' => 800, 'memory' => 64],
    ['type' => 'request', 'duration' => 300, 'memory' => 32],
    ['type' => 'command', 'name' => 'reports:*', 'duration' => 60000],
    ['type' => 'scheduled-task', 'duration' => 5000],
],
```
