# Changelog

## 1.0.0 - 2026-10-13

The first release.

### Added

- Requires PHP 8.3, Laravel 12.41.1 or newer or Laravel 13, `laravel/nightwatch` `^1.30.2` and SQLite 3.41.0. Runs on Linux, macOS and Windows, without the `intl` extension.
- Install as a dev dependency with nothing else to run. Firewatch registers Nightwatch itself, and the optional `config/firewatch.php` (publish tag `firewatch-config`) can be set from `FIREWATCH_*` environment variables. An invalid value falls back to its default.
- Firewatch captures only in the `local` and `testing` environments by default and steps aside everywhere else. It is Off below SQLite 3.41.0 and refuses a store path under the public directory.
- Every request, command, job attempt and scheduled task is captured with everything Nightwatch's sensors record about it, with sampling and filters overridden, and stored in a local SQLite file. Nothing is sent anywhere.
- Firewatch counts each difference from Nightwatch's record contract instead of dropping data.
- Records are labelled with a deploy from `FIREWATCH_DEPLOY` or Nightwatch, else with the commit git HEAD points to, so two commits can be compared.
- Redaction is off by default, with opt-in lists for payload fields and headers. Long values are cut with a truncation marker.
- Under `testing`, each request and command a feature test runs is recorded as an execution of its own, with its test, and its worker in a parallel run. `capture.tests` turns it off.
- Each environment keeps its own store, `storage/firewatch/firewatch-{environment}.sqlite`, so an environment added to `environments` doesn't mix with local browsing.
- Retention by age and record count, with a size backstop. Coverage markers let every answer state how far its history reaches.
- Capture waits `busy_timeout` (300 milliseconds by default) for a busy store. It then drops the batch, never retries it, and logs it to `failures.jsonl`. A test run waits 5 seconds, so the workers of a parallel run lose no records.
- `failures.jsonl` keeps its newest 100 lines. Answers and `firewatch:doctor` state how many records were lost, as "at least" that many once the file is full.
- Firewatch's own failures go to the PHP error log, never to the exception handler, so they never appear as the application's exceptions. The writer recovers from forks, replaced or deleted files, schema mismatches and damaged stores.
- `php artisan firewatch:server` runs the MCP server over stdio. `--list` prints its tools, and `--json` prints that listing as JSON.
- `php artisan firewatch:doctor` runs read-only checks on PHP, SQLite, Nightwatch, the configuration, the store and the server, with a fix for every warning and failure. `--json` prints the report as JSON.
- `php artisan firewatch:clear` removes all records, or one type's with `--type`, after a confirmation that `--force` skips. `--drop` rebuilds the store in place.
- Twelve MCP tools: `overview`, `rank`, `detect`, `occurrences`, `execution`, `trace`, `actor`, `compare`, `trend`, `query`, `describe` and `fingerprint`.
- `detect` runs eleven problem shapes: `failing-routes`, `n-plus-one`, `database-bound`, `memory`, `failing-jobs`, `queue-latency`, `failing-tasks`, `exception-clusters`, `error-logs`, `failing-http` and `cache`. Each answers with findings, clean or not evaluated.
- An `n-plus-one` finding states its `pattern`: `loop` for one call site, `repeated` for several, and `unknown` when only a vendor call site, or one with no file, reached the threshold.
- `execution` opens an execution by a unique prefix of its id.
- `query` runs one read-only SQL statement in a framework-free PHP child under a closed authorizer, with a deadline and limits on memory, output, rows and cell size. `describe` documents every object and column it can read.
- Performance budgets per record type, judged in `execution`, `rank` and `overview`.
- A test run's setup reads apart from its endpoints: `scope` on `overview`, `rank`, `detect` and `occurrences` keeps one side, and `rank` with `within` ranks the queries and cache events of one route or command.
- `occurrences(order: "run")` lists a test run in order and names each record's test and worker, as `execution` does. `test` limits `rank` and `occurrences` to one test.
- Once the store holds test runs of more than one worker, answers that count or name actors say one user id can be several people.
- Every answer comes in markdown or JSON with its window, coverage, blind spots and closed error codes. Every next call it offers fits the tool's published input schema, which declares its allowed values, defaults and limits.
- An empty or clean answer spells out each blind spot. A `rank`, `occurrences`, `detect` or `compare` answer that lists what it found can name a blind spot by id instead, and `describe` holds every sentence.
