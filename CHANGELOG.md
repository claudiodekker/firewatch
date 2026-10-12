# Changelog

## Unreleased

The first release.

### Added

- Install as a dev dependency with nothing else to run. Firewatch registers Nightwatch itself, and the optional `config/firewatch.php` (publish tag `firewatch-config`) can be set from `FIREWATCH_*` environment variables. An invalid value falls back to its default.
- Firewatch captures only in the `local` and `testing` environments by default and steps aside everywhere else. It is Off below SQLite 3.41.0 and refuses a store path under the public directory.
- Every request, command, job attempt and scheduled task is captured with everything Nightwatch's sensors record about it, with sampling and filters overridden, and stored in a local SQLite file. Nothing is sent anywhere.
- Each environment keeps its own store, `storage/firewatch/firewatch-{environment}.sqlite`, so an environment added to `environments` doesn't mix with local browsing.
- Under `testing`, each request and command a feature test runs is recorded as an execution of its own, so `detect` can audit a test run. `capture.tests` turns it off.
- A test run's setup reads apart from its endpoints: `scope` on `overview`, `rank`, `detect` and `occurrences` keeps one side, `rank` with `within` ranks the queries and cache events of one route or command, and `occurrences(order: "run")` lists the run in order.
- Every record of a test run carries its test, and its worker in a parallel run: `occurrences(order: "run")` and `execution` name them, and `test` limits `rank` and `occurrences` to one test.
- A user id that several workers of a parallel test run signed in is one actor per worker: `overview` and a finding's reaches count each, `actor` lists them as candidates, and `worker` on `actor` and beside `user_id` on `occurrences` reads one. A call without it notes how many workers hold the id.
- `execution` opens an execution by a unique prefix of its id.
- Redaction is off by default, with opt-in lists for payload fields and headers. Long values are cut with a truncation marker.
- The store keeps every record type in one table with a view per type, a directory of signed-in users, and a `drift` table that counts differences from Nightwatch's record contract instead of dropping data.
- Records are labelled with a deploy from `FIREWATCH_DEPLOY` or Nightwatch, else with the commit git HEAD points to, so two commits can be compared.
- A failed batch is dropped, never retried, and logged to `failures.jsonl`. Firewatch's own failures go to the PHP error log, never to the exception handler, so they never appear as the application's exceptions. The writer recovers from forks, replaced or deleted files, schema mismatches and damaged stores.
- A test run waits up to 5 seconds for a busy store, so the workers of a parallel run lose no records, whether or not the suite fakes `Sleep`.
- `failures.jsonl` counts every dropped batch and record, however many it has dropped, so answers and the doctor state the whole loss.
- Retention by age and record count, with a size backstop. Coverage markers let every answer state how far its history reaches.
- `php artisan firewatch:server` runs the MCP server over stdio, and `--list` prints its tools.
- `php artisan firewatch:doctor` runs read-only checks on PHP, SQLite, Nightwatch, the configuration, the store and the server, with a fix for every warning and failure. `--json` prints the report as JSON.
- `php artisan firewatch:clear` removes all records, or one type's with `--type`, after a confirmation. `--drop` rebuilds the store in place.
- Twelve MCP tools: `overview`, `rank`, `detect`, `occurrences`, `execution`, `trace`, `actor`, `compare`, `trend`, `query`, `describe` and `fingerprint`.
- `detect` runs eleven problem shapes: `failing-routes`, `n-plus-one`, `database-bound`, `memory`, `failing-jobs`, `queue-latency`, `failing-tasks`, `exception-clusters`, `error-logs`, `failing-http` and `cache`. Each answers with findings, clean or not evaluated.
- An `n-plus-one` finding states its `pattern`: `loop` when one call site ran the read at least the threshold times in one execution, `repeated` when the runs came from several call sites, and `unknown` when only a vendor call site, or one with no file, reached the threshold, since it may stand for several application callers. Loops are listed first, then repeated reads, then unknown ones.
- `query` runs one read-only SQL statement in a framework-free PHP child under a closed authorizer, with a deadline and limits on memory, output, rows and cell size. `describe` documents every object and column it can read.
- Performance budgets per record type, judged in `execution`, `rank` and `overview`.
- Every answer comes in markdown or JSON with its window, coverage, blind spots and closed error codes. Every next call it offers fits the tool's published input schema, which declares its allowed values, defaults and limits.
- An empty or clean answer spells out each blind spot. Beside a result that lists what was found, a blind spot is named by id, and `describe` holds every sentence.
- Runs on Linux, macOS and Windows, without the `intl` extension.
