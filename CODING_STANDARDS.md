# Coding Standards

The reviewer reads this file. Apply every rule to each changed hunk in the diff. Skip anything the repo's tooling already enforces (Pint, PHPStan/Larastan, arch tests).

This is a Laravel package with no HTTP layer of its own. Its entry points are Artisan commands (`firewatch:*`) and MCP tools; its state is a SQLite store; it takes Nightwatch's output through one seam (its own ingest, swapped in for Nightwatch's, with a veto on `IngestingEvents` behind it) and runs assistant SQL in a child process. The design lives in the closed decision issues, `GLOSSARY.md` and `docs/adr/`; these rules govern how it is implemented.

## 1. Sibling changes

- A change to one member of a sibling set covers the other members in the same diff. Firewatch's sibling sets are its MCP tools, detectors, doctor checks, commands, listeners of the same kind, and record types with their view and contract-table entry.
- A closed set of the design (detector shapes, blind-spot ids, error codes, doctor check ids, drift kinds, empty kinds, store states, config keys) changes only by changing its contract, and the test that pins it changes in the same diff.

## 2. Actions

- Actions are the state-changing use cases a command, tool or Firewatch's ingest triggers: `AppendBatch` (append a batch) and `ClearStore` (clear or drop the store). The prune pass is not an Action: Firewatch's ingest runs `Store\Pruner` after a batch. Nothing else writes to the store: the writers are Firewatch's ingest (a batch, then the prune pass) and `firewatch:clear`. Reading never writes.
- The store is reached only through its own raw `SQLite3` connection, never Laravel's database layer (no `DB`, PDO, Eloquent or migrations), so its queries are never observed by Nightwatch (ADR 0005).
- Every write runs in an explicit, short `BEGIN IMMEDIATE` transaction on that connection, so `DB::transaction()` is not used. A batch is one transaction; pruning and clearing work in chunks, each with its coverage marker in the same transaction (ADR 0006).

## 3. Entry points

- Artisan commands and MCP tools validate their input, call one Action or query, and return. They hold no business logic.
- Tool and command arguments are read through typed accessors, with no `(int)` casts, and validated at the boundary. Numbers such as `limit` and `window` are refused with an actionable error when out of range, never silently clamped, so the answer always reflects what was asked. A malformed time is refused, never read as unbounded.
- An unknown, misspelt or inapplicable argument is refused with the accepted values, never ignored. Enumerated values are matched exactly.
- Output is an array or value object, never a raw driver result. An empty object result serializes as `{}`, not `[]`.
- Every tool answers with the one fixed envelope. A failed call is a plain-text tool error with a closed code and no envelope, never a protocol error, stack trace or path. A valid selector that matches nothing is an empty answer, not an error.
- Tool and command names say what they do. Errors reach the caller as a message it can act on; an install fix says "run `php artisan firewatch:doctor`".
- Commands and the server exist only where Firewatch is not stepped aside, and every signature starts with `firewatch:`. The server starts only through `firewatch:server`; no laravel/mcp handle is registered (ADR 0010).
- The doctor is read-only: it uses the reader connection or none and creates nothing (no store file, directory or lock file). Its checks are a closed set of independent ids; a check that throws is reported as `fail`, and `warn` and `fail` carry a one-line fix.

## 4. Queries

- Queries use bound parameters. Values are never interpolated into SQL text. The one exception is the SQL tool, whose single statement is the assistant's own (section 18).
- Queries behind an entry point are bounded by a row limit (fetch `limit + 1`, so exactly `limit` rows is a complete list) or by design to one execution, one trace or one job lineage. An absent window bound means unbounded, so the limit is what bounds the answer.
- One tool call reads inside one deferred snapshot, so every query of one answer sees the same data.
- Instants are bound as floats, never text. Durations are integer microseconds and computed unrounded; rounding happens only when a value is written into an answer.
- Windows are half-open (`[since, until)`) on the record's own `started_at`. An execution-scoped analysis selects executions by their own `started_at` and reads their children whole by `execution_id`, with no window.
- Records join on `execution_id`, `trace_id`, `group_hash` and `job_id`. Queued work joins only on `job_id`, never on trace or execution id.

## 5. Null-safety

- Every nullable value is guarded before it is dereferenced. In Firewatch that covers optional wire payload keys, nullable store columns and Nightwatch event properties. Check that a key exists before indexing a wire record or tool argument.
- A new column doesn't duplicate one the store already keeps.
- A stored value keeps the wire's word: a wire `0` or `''` is stored as sent, and NULL only where the wire omits the field. NULL means unknown, never zero and never clean.

## 6. Types and values

- Enum values are spelled exactly as the design spells them (`job-attempt`, `not_evaluated`). Only an enum that renders text has a `label()` method.
- Answer fields carry the unit as a suffix (`_ms`, `_mb`, `_bytes`, `_pct`, and `_at` for instants). The store holds Unix seconds and integer microseconds and bytes; conversion happens only when an answer is written, and the SQL tool returns raw values. An instant held as Unix seconds is named for the moment it marks (`$now`, `$since`, `$cutoff`) and needs no suffix.
- Each step that does real work (reads rows, plans, writes, hashes, calls another class) gets its own statement and a named variable. Don't nest it inside another call's argument, where a reader skims past it. Resolving a dependency isn't real work in this sense: don't split a call's arguments into single-use locals, e.g. `new DefaultLogChannel($this->app->make('config'))` stays inline.
- Record data is JSON-encoded with the seam's flag set, so wire floats keep their fraction.
- A string is cut only by the design's rules: 65,535 bytes at a UTF-8 boundary with the `... [truncated, N bytes total]` suffix counted in the limit, bindings and answer cells with their own caps, and a record's `data` capped as a whole. The trace and JSON-string fields are exempt. Limits are constants, and no `truncated` flag is stored.

## 7. Errors and integrations

- Two seams exist, each with a real and a fake adapter: the SQL runner (`Sql\SqlRunner`: `ChildRunner` spawns the child process, `Tests\Support\FakeSqlRunner` stands in) and the provider's notices (`Notices`, which the test case fakes for every application it creates). Other collaborators are not put behind a contract for the sake of testing; they are exercised for real through the feature they belong to.
- The boundaries that may catch `Throwable` and `report()` it are Firewatch's ingest, provider boot, process entry points and the tool layer.
- Nothing is thrown into the host application. The tool layer turns an unexpected failure into the `internal` tool error, also when `app.debug` is on, because a rethrow ends the stdio process.
- Calls that could act on nothing (an empty batch, an empty result set) check for empty input first.

## 8. Long-running processes

- Queue workers and Octane run Firewatch's ingest, and the MCP server reuses its process too, so call-specific statics and singletons are reset between executions and tool calls.
- The mode and the configuration are read once per process. A store connection is keyed by `getmypid()`: after a fork the inherited handle is abandoned unused and a new one is opened. Every write batch and reader call checks the file's identity, so a file deleted or replaced under a live connection is reopened; where no identity exists (Windows) the check does nothing and never throws.
- The MCP server and the SQL child write only protocol output to stdout; diagnostics go to stderr or the log. The server forces `display_errors` to stderr and `app.debug` off, uses no console output helper, and holds no state between calls. Its boot touches nothing in the store.
- Windows is supported: no code assumes POSIX (file modes, `stream_select()` on `proc_open` pipes) without a Windows path that the platform job (planned, #88) runs (ADR 0012).

## 9. Configuration

- Config values are cacheable: scalars, class-strings and arrays of them, never objects or closures.
- Config is read only through the configuration normaliser, never with `config('firewatch.…')` elsewhere. An invalid value falls back to its default, alone, with an issue; it never throws, never stops capture and never changes the mode.
- The closed key set is the only configuration. Limits, ceilings, thresholds, sample floors, chunk sizes and the size backstop are named constants, never settings. `FIREWATCH_ENABLED` is the only on/off switch, and the values forced onto Nightwatch are not configurable.
- New options default to off or the least surprising behaviour and are documented in the config file with a Laravel `|` header block.

## 10. Store

- The store is rebuilt, never migrated: no migration files, no `down()` methods, no backfills. On schema change, bump the schema version; the next writer drops and recreates every table, view and index in place inside one transaction. A writer never writes or rebuilds a store a later release stamped; only a drop does. It never unlinks, renames or replaces a file other processes may hold open, and readers never create, migrate or rebuild anything.
- Any change to the generated DDL (a contract field, a view, an index) bumps the schema version; a hash of the DDL pins it in a test.
- A filter or sort on a common column uses an existing index. A new index, or a `data` field promoted to an indexed generated column, is added only when a real tool or detector query needs it, measured, in the same schema version.
- Records mirror the wire: wire names verbatim, except `user_id` (the wire `user`) and `event` (a cache event's wire `type`), and the wire group hash verbatim (ADR 0003). Fields without a common column go in `data`, unknown fields are kept, and every deviation is in the one list of the record model.
- No `STRICT` tables and no `CHECK (json_valid(...))`: a rejected insert would drop a record.
- The store id only pages and prunes. It is never an identity, a link or a shown value. Links resolve at read time (no foreign keys), the store is append-only, and nothing is deduplicated or merged.
- Removal is recorded, not inferred: a prune or a clear writes its coverage marker in the same transaction as the delete (a clear writes it first), and readers derive every coverage start from the markers, never from configuration (ADR 0006). Only the capturing writer prunes, after a successful batch commit or at once after a batch the full store refused, never on a read path.
- The store file is `0600` in a `0700` directory with its own VCS ignore file, created lazily by the first writer; a read creates nothing. A file that lacks the Firewatch stamp is never written, moved or deleted; only a damaged file that bears it is moved aside.

## 11. Tests

- The tests each design decision lists are the definition of what must be covered; boundaries are tested from both sides (19 vs 20 records, 59 vs 60 percent, exactly the change band).
- Tests have five layers with one job each. A scenario test (`tests/Scenario`, the default) builds a store from real Nightwatch sensor traffic, walks the tool ladder as an assistant would and asserts the structured answer, with a positive, a negative and a blind-spot case. A feature test (`tests/Feature`) drives one thing a user, an MCP client or Nightwatch triggers (a command, an ingest, a retention pass, a spawned second process) end to end and asserts the state it leaves. A contract test (`tests/Contract`) pins Nightwatch's output, the fixtures and Firewatch's fixed text and shapes, and holds no behaviour. A unit test (`tests/Unit`) covers only what a feature test can't reach (arithmetic tables, grammars, polling, error classification), and a unit test that a feature or scenario test already covers is deleted. An arch test (`tests/Arch`) enforces an invariant, not a style preference.
- Telemetry comes from the real sensors driven through the workbench. A synthetic record is allowed only for exact durations or timestamps, volume, many groups, deploy identities, drift shapes and other-process writers; it derives from a committed wire fixture through the record builder and goes through Firewatch's real ingest. Nothing inserts into the store except a corruption or foreign-file test. A wire fixture is generated by the workbench command, never edited by hand.
- A real-sensor test asserts counts, relations, verdicts and shapes, never exact instants or durations.
- Fixed wording (blind-spot sentences, empty kinds, error messages, detector caveats, doctor messages and other user-facing text) is asserted through its language key, `__('firewatch::messages.key')`, never a copy of the translated string. A contract test (`tests/Contract`) is the exception: it pins the English text itself, so a reworded sentence fails a test. Ids, error codes and closed sets are written as literals in the test, so changing one fails a test. Long text (the server instructions, tool descriptions) is asserted structurally, and limits numerically (40 words per blind spot, 150 per tool description, `tools/list` under 6,000 tokens). No snapshot files are committed.
- JSON answers are asserted in full; the markdown rendering once per tool through the shared helper. Every `next` call an answer offers is executed and returns a non-error answer.
- A test that spawns a real process carries the `process` tag, and one that asserts a file mode or another POSIX-only fact carries `posix`. Real processes run with shortened deadlines passed through constructor arguments, never a real wait.
- Each test uses its own temporary store path. A test that needs a clock moves Laravel's (`travelTo()`, with `Sleep` faked to follow it).
- No test asserts timing, sleeps or belongs to a performance group. Correctness is asserted through bounds that are behaviour (bounded batches, ceilings, a deadline that returns control).
- A test that changes Nightwatch's per-process state restores it in teardown.

## 12. Methods and classes

- Traits live in a `Concerns` subfolder of their area (`Console/Concerns`, `Mcp/Concerns`), never beside the classes they serve.
- Exceptions the package defines carry state in `public readonly` properties promoted in the constructor, with no setters, and keep a short message. One with several cases is built through named static constructors (`Refusal::missing()`).

## 13. Code hygiene

- Sample entries in the published config file may stay commented out, as in Laravel's own.
- Where an `@param` or `@return` tag is kept, it gets a description only for a constraint the name can't express.
- A guard clause that doesn't fit on one line gets a shorter message, not a wrap.
- No PHPStan baseline: fix, don't baseline.

## 14. Domain language and user-facing text

- Long user-facing text (blind-spot sentences, tool descriptions, instructions, doctor messages) is a key in the package's `messages` language file, read through the package namespace (`__('firewatch::messages.failed')`). The file is loaded only where Firewatch is Active or Off. Tool classes override `description()` to read it, and set explicit tool names rather than relying on the default kebab-cased class name.
- Notices written while the provider registers (stepped aside, ingest not replaced, provider order, a missing veto event, configuration issues) go to the PHP error log through `Notices`, never through `report()`, so they stay out of the application's own telemetry. They are literals in the provider, because the language file loads only after they fire. Command descriptions stay literals, as in Laravel.
- Code, answers and text use the glossary term, not its _Avoid_ words. The word verdict is reserved for detectors and budgets: compare rows carry a change token and trends a direction. In prose, "the `query` tool" is the SQL tool and "the `query` record type" is the record.

## 15. Packages

- The public surface is the commands, the config keys, the tool names, arguments and answer shape, and the seam.
- The lowest supported versions are PHP 8.3, Laravel 12.41.1, Nightwatch 1.30.2 and SQLite 3.41.0. The Laravel floor lives only in the CI and canary matrices (`.github/workflows/ci.yml`, `.github/workflows/canary.yml`), which run on 12.41.1; `composer.json` allows `illuminate/support` `^12.0`. Depend on the `illuminate/*` components Firewatch uses, not on `laravel/framework`.
- Nightwatch's output reaches Firewatch only through Firewatch's own ingest; the public `IngestingEvents` event only vetoes. The one `@internal` Nightwatch surface `src` touches is `Core::$ingest`, swapped for Firewatch's own `Contracts\Ingest` only after reflection confirms the interface's signatures and the property, so a changed Nightwatch leaves its ingest in place behind the dead values instead of crashing the host (ADR 0001). The test harness touches a second, `NightwatchServiceProvider::flushState()`, to give each application its own trace. The Octane contract tests dispatch Octane's public `RequestReceived` event, which `laravel/octane` provides as a dev dependency only. The verified line, the wire fixtures and the contract tests move together in one PR.
- A user-facing change adds one line to `CHANGELOG.md`, about one feature, not one ticket. The README's config and command tables equal the code.
- The README is a short guide: install, connect, configure, commands and troubleshooting. Internals, wire details and design rationale go in `GLOSSARY.md` or an ADR, never the README. `tests/Contract/DocumentationTest.php` caps its length.

## 16. Capture and ingest

- Firewatch's ingest holds nothing but its buffer and filters nothing: every record, `user` included, reaches the mapper unchanged and in order. A failed batch or prune pass never reaches the host, and what the sensors record while a batch is stored is ignored, as in Nightwatch's own ingest.
- The veto listener returns `false` on every path and writes nothing (ADR 0001). It guards Nightwatch's own ingest when the swap is refused.
- Nothing is retried, spooled or blocked on. A batch that can't be written within `busy_timeout` is dropped and recorded as one line beside the store, and the failure path itself swallows its own failure (ADR 0005).
- Firewatch registers Nightwatch's provider and alias in every mode and writes its Nightwatch config with `config()->set` in its own `register()`, before Nightwatch's provider registers. The mode is resolved once: not on the allowlist is stepped aside, else a `firewatch:` process, disabled, or an unusable SQLite is Off, else Active. Stepped aside registers no listener, command, publish tag or MCP server.
- A `firewatch:` process is always Off, so Firewatch never observes itself, and Off processes never prune.
- The mapper never throws on an odd shape. It normalises each record by a JSON round trip, stores what it cannot read as an error placeholder with a `structure` drift, and drops or quarantines nothing: unknown fields stay in `data` and missing ones are NULL. Drift is counted by kind, type and field, aggregated per batch and upserted in the batch's transaction. A record's `timestamp` comes from the original array, not the round trip.
- The contract table defines each field once (name, accepted types, destination), and the mapper, the drift checks and the views all read it.
- Redaction and truncation happen at ingest, before the store write, never at read time.
- Query bindings are the one second capture path. A binding belongs to its exact query or is NULL: pairing is by order within an execution, checked on `sql` and `connection`, and an entry that matches no record is skipped.

## 17. Answers and analysis

- Empty is not clean. Every answer states the store clock, the window, coverage and the blind spots of the record types it examined, also when empty, and no tool can switch a blind spot off.
- A detector answers only `findings`, `clean` or `not_evaluated`, always with `examined`. Empty input is `not_evaluated`, never `clean`. A budget answers `within`, `exceeded` or `not_evaluated` and is never `within` by absence. `execution` judges one execution, and `rank` and `overview` judge a group on its p95 when every measured quantity has 20 executions that ran, else on its maximum, stating `measured_on`; a skipped task is `not_run`. Slowness is never a detector (ADR 0009).
- Values the sensors never populate (four counters, two failure flags) are reported as stored and never presented as healthy; the blind spots say so.
- An absent window bound means unbounded, never recent, except on compare (coverage start and the store clock) and trend (a derived bound the window names).
- Coverage is read from the recorded markers. A window before the coverage start is "no data", never zero or clean; ranking, compare and trend clip to it, and detectors exclude the executions whose needed children were removed.
- A statistic is per occurrence. Percentiles are nearest rank, computed in SQL. A statistic below its sample floor (3 for p50, 20 for p95) is NULL with a `withheld` reason, never replaced by the maximum; the budget verdict on a group is the one stated exception. Order statistics and counts decide, so one outlier never flips a change token. A trend's direction is the one median taken in PHP: it is over at most 60 bucket values the SQL already computed, not over records, and has no floor beyond the four valued buckets it needs.
- A person is attributed only by the recorded user, a job attempt's dispatch (one hop) or a child that carried the user inside a command or task, never by trace, `caused_by`, IP or timing, and every actor answer (planned, #73 and #74) counts what it could not attribute (ADR 0007).
- Detectors run on the server's reader, never through the SQL tool, and their threshold is per call only, stated on the result.
- Identifiers print in full and go back into tools unchanged.

## 18. SQL access

#80 built the child, its authorizer and allow-lists, the deadline and the completeness rule; #81 added the memory, output and row-budget ceilings and partial rows; #82 added the availability check and the temp-file exchange that runs on Windows.

- The assistant's SQL runs only in the short-lived child that boots no framework, opens the store read-only and installs a closed authorizer: read actions on a fixed set of objects and an allow-list of functions, never a deny-list. It runs one statement through `prepare()`, never `query()` or `exec()` (ADR 0008).
- If the isolation can't be established the tool refuses with `unavailable`; there is no in-process or degraded mode. The tool stays registered.
- Ceilings and allow-lists are constants, never settings. The deadline is a constructor argument that defaults to the fixed 10 seconds, so tests can shorten it.
- The parent trusts a result as complete only when the final line arrives and its row count matches; rows already streamed are returned as partial, never as complete, and a partial result with no rows is the matching tool error.
- The child script and its policy classes reference no `Illuminate` and no application class. The parent exchanges with the child through private temp files polled against the deadline, one mechanism on every platform, because `stream_select()` does not work on `proc_open` pipes on Windows (ADR 0012).
