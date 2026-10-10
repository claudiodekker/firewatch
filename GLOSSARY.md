# Firewatch

A local, dev-only telemetry store fed by Laravel Nightwatch's sensors and read by an AI assistant over the Model Context Protocol. Firewatch itself sends nothing anywhere; the assistant is the only interface.

## Language

### Interception

**Interception**:
Taking Nightwatch's records into Firewatch's store instead of letting them be transmitted.
_Avoid_: Tap, hook, proxy

**Seam**:
The one point where Firewatch intercepts Nightwatch's output: Nightwatch's ingest, which Firewatch replaces with its own.
_Avoid_: Hook point, integration point

**Veto**:
Cancelling a transmit of Nightwatch's own ingest by returning `false` from the `IngestingEvents` listener, which guards the case where Firewatch could not replace that ingest; in Active Firewatch always vetoes.
_Avoid_: Block, cancel, tee

**Batch**:
The ordered list of wire records Firewatch's ingest stores at once, on a digest, a full buffer or an immediate write, which may be part of an execution.
_Avoid_: Payload, digest

**Sensor**:
A Nightwatch component that observes one kind of application activity and produces wire records.
_Avoid_: Collector, probe

**Wire record**:
One final array Nightwatch would send, identified by its type `t` and version `v`.
_Avoid_: Event, message

### Modes and capture

**Active**:
The mode in which Firewatch captures: it enables Nightwatch, forces the capture posture, stores every batch through its own ingest and vetoes any transmit of Nightwatch's.
_Avoid_: On, enabled

**Off**:
The mode in which Firewatch disables Nightwatch and captures nothing; every Firewatch process is Off.
_Avoid_: Disabled, paused

**Stepped aside**:
The mode in a disallowed environment in which Firewatch registers no listener, command, publish tag or MCP server and touches no Nightwatch setting, so Nightwatch behaves as if Firewatch were absent and even the doctor does not exist.
_Avoid_: Inactive, bypassed, no-op mode

**Capture environment**:
An application environment name on the allowlist in which Firewatch records telemetry.
_Avoid_: Enabled environment, dev mode

**In-code opt-out**:
A developer-written instruction in application code (ignore, pause, never sample, reject callback) that tells Nightwatch not to record something, and which Firewatch honors.
_Avoid_: Filter, exclusion

**Cost control**:
A Nightwatch setting that limits volume to save hosted cost (sampling rates, `ignore_*` flags), which Firewatch overrides.
_Avoid_: Sampling config, filter

**Truncation marker**:
The suffix `... [truncated, N bytes total]` placed at the end of a cut string value to record its original length.
_Avoid_: Truncated flag, ellipsis

**Blind spot**:
Something Firewatch cannot see or deliberately does not record, stated on answers so an absence is never read as proof.
_Avoid_: Limitation, gap

### Compatibility

**Verified line**:
The Nightwatch major.minor release line whose records Firewatch's contract table was built and tested against.
_Avoid_: Supported version, compatible range

**Drift**:
Any difference between what Nightwatch sends or how it is installed and what Firewatch's contract table expects, named by kind, type and field.
_Avoid_: Mismatch, breakage, schema error

**Drift kind**:
The class of a drift: `unknown_type`, `unknown_version`, `unknown_field`, `missing_field`, `structure` or `version`.
_Avoid_: Error type, drift level

**Contract table**:
The list of known record types and versions with each one's fields and accepted types, against which every wire record is checked.
_Avoid_: Schema, whitelist

**Partly interpreted**:
The state of a captured record whose shape differs from the contract table, so some of its data is kept but not understood. A value of a type the contract does not accept is stored as sent, a list or object bound for a column as its JSON, and answers read it as absent.
_Avoid_: Corrupt, invalid

### Records

**Record**:
One stored Nightwatch event of any type, including unknown ones, or an error placeholder for input that could not be read as a record; user directory entries are not records.
_Avoid_: Row, event (for the stored thing), entry

**Execution**:
A unit of work that owns child events and counters: a request, a command, a job attempt or a scheduled task.
_Avoid_: Run, transaction, span

**Execution id**:
The single identifier by which children join to their execution: the trace id for requests, commands and scheduled tasks, the attempt id for job attempts.
_Avoid_: Run id, span id

**Source**:
The kind of execution (request, command, job, schedule), derived from the execution's record type.
_Avoid_: Kind, origin

**Child event**:
A record belonging to an execution: query, exception, log, cache event, mail, notification, outgoing request or queued job.
_Avoid_: Sub-event, detail record

**Store id**:
The single integer handle of a stored record, unique across all types, used only to page and prune, never as a link or identity; ids restart at 1 after any rebuild (a drop, a schema-mismatch rebuild or a damaged-file replacement) and continue after a clear.
_Avoid_: Primary key (as domain identity), record id

**Trace**:
The set of records sharing a trace id across a request and the work it caused; derived, never stored as its own entity.
_Avoid_: Session, chain (as an entity)

**Started at / ended at**:
A record's normalized start instant in Unix seconds with microsecond resolution and its derived end.
_Avoid_: Timestamp, created at, received at

**Deploy**:
The identity of the deployed code a record was captured under, an unordered string from Nightwatch's deployment setting or `FIREWATCH_DEPLOY`, matched exactly; a record with an empty or no deploy belongs to none.
_Avoid_: Release, version, build

### Queued work

**Job id**:
The identifier tying a queued-job dispatch to all attempts of that job.
_Avoid_: Task id, queue id

**Dispatch**:
The queued-job record made when work is put on a queue; at most one per job id.
_Avoid_: Enqueue event, job record

**Attempt**:
One run of a queued job, a job-attempt execution numbered in order for its job id.
_Avoid_: Retry (as the record), run

**Partial lineage**:
A queued job seen as only a dispatch or only attempts, which is a valid state and not a failure.
_Avoid_: Broken link, orphan job

**Caused by**:
The execution that dispatched a queued job, given by the dispatch's execution id.
_Avoid_: Parent, triggered by

### Grouping and completeness

**Group hash**:
Nightwatch's own grouping hash for a record, stored verbatim so a group here is the group Nightwatch shows; one hash can be held by a job's dispatches and its attempts.
_Avoid_: Fingerprint (the tool that computes one from source), group key

**Group label**:
The human-readable name of a group: a fixed display field per record type, read from the group's latest record in the window.
_Avoid_: Group name, title

**Not measured**:
The meaning of any value the sensors never populate (four counters, mail and notification failure flags); never "clean". Answers state it through blind spots, not by a marker on the value.
_Avoid_: Zero, none, clean, healthy

**Incomplete**:
A stated condition of an execution or child whose counterpart records are missing or fewer than counted; never an error.
_Avoid_: Corrupt, lost, error

**Counted versus captured**:
The comparison between an execution's counters and the child records actually stored.
_Avoid_: Coverage, completeness ratio

### Data

**Bindings**:
The query parameter values captured from the framework's query events and paired to a query record, or absent when unpaired.
_Avoid_: Parameters, params

**User directory**:
The small table of user id, name, username, first-seen and last-seen instants, never an event and never counted as a record, aged out with the records.
_Avoid_: Actors table, users event

**Actor**:
The person behind a user id recorded on a request, job attempt or child event, named through the user directory when a row exists.
_Avoid_: Account, principal, customer

### Store

**Store**:
The single SQLite file holding all captured records, the user directory, drift counts and a few facts about the capture.
_Avoid_: Database (except as the name of the `database` setting), cache, log file

**Record view**:
One of the twelve per-type views over the store's records, with typed columns named as on the wire, which are the documented query surface.
_Avoid_: Table, model, schema table

**Raw table**:
The single table of all records that the record views read from, queryable directly for work across types.
_Avoid_: Events table, main table

**Store state**:
The named condition of the store a reader reports when it cannot be read as usual: absent, schema mismatch, corrupt, foreign, busy or unavailable. In answers, absent gives the no-store empty kind, and foreign, schema mismatch and an unavailable floor give an unusable store with a reason, while corrupt and busy give an unusable store with the reason unreadable.
_Avoid_: Health, error

**Schema version**:
The integer stamped in the store that says which shape it was created with; an earlier value causes a rebuild, not a migration, and a later one, from a newer release, is never written or rebuilt except by a drop.
_Avoid_: Migration number, database version

**Rebuild**:
Dropping and recreating everything in the store in place when its schema version is earlier than the writer's, which discards its data.
_Avoid_: Migration, upgrade, reset

**Dropped batch**:
A batch of wire records Firewatch could not store and discarded without retry, recorded as a store failure.
_Avoid_: Lost event, failed write

**Store failure**:
One line recorded beside the store when a write, rebuild or recovery failed, kept apart from the store so it survives the store's own failure.
_Avoid_: Error log, exception

**Foreign file**:
A file at the store's path that is not a Firewatch store, which Firewatch never writes, moves or deletes.
_Avoid_: Bad file, wrong database

### Retention

**Retention**:
The rules by which Firewatch removes old records to keep the store bounded: a maximum age, a maximum record count and a size backstop.
_Avoid_: Expiry, garbage collection, rotation

**Pruning**:
The automatic removal of the oldest records, users and drift counts once a retention rule is exceeded, done only by the capturing writer.
_Avoid_: Cleanup, eviction, purge

**Size backstop**:
The fixed-purpose live-size limit that prunes only when payloads are pathologically large, never a tuning knob.
_Avoid_: Size cap, quota, disk limit

**Clear**:
The developer's deliberate removal of history with `firewatch:clear`: all records, or all records of one type.
_Avoid_: Reset, wipe, flush

**Drop**:
Rebuilding the store in place as a fresh one, discarding everything including drift, failure lines and the id sequence.
_Avoid_: Reset, recreate, delete the file

**Coverage start**:
For a record type, the newest instant before which history was removed on purpose or by retention, or the store's creation if nothing was; a window before it is "no data", never clean.
_Avoid_: Retention horizon, data start

**Coverage reason**:
The recorded cause of a coverage start: created, cleared, cleared-type, pruned-age, pruned-cap or pruned-size.
_Avoid_: Cause, prune type

**Children outside coverage**:
The stated condition of an execution whose children of some type lie before that type's coverage start, which is not loss and not incompleteness.
_Avoid_: Missing children, orphaned

### Answers

**Answer**:
The single fixed-shape result of a tool call: the store clock, the window, a summary, the result, coverage, blind spots and the ways to go on.
_Avoid_: Response, output, report

**Store clock**:
The wall clock of the machine running the MCP server, shared with the writers, read once per call and stated on every answer.
_Avoid_: Server time, current time

**Window**:
The half-open interval on records' start times over which a windowed answer is computed; an absent bound means unbounded, never recent, except on compare (coverage start and the store clock) and trend (a derived bound, named on the window).
_Avoid_: Time range, period, default range

**Boundary**:
What divides a compared window into a before side and an after side: a split point or a deploy pair, exactly one per comparison.

**Split point**:
An instant strictly inside a window that divides it into a before side and an after side, the record exactly at it being after.
_Avoid_: Anchor, checkpoint

**Deploy pair**:
Two different deploys compared over one window: every record of the first is on the before side and every record of the second on the after side, whenever each started, so two deploys served side by side may interleave. It cannot separate an uncommitted edit, because the deploy does not change with one.
_Avoid_: Release diff, deploy range

**Straddling**:
Executions of the compared type that started shortly before a split point and finished after it, which sit on the before side.
_Avoid_: Overlap, spanning work

**Coverage**:
The stated span, size and history of the store behind an answer, present on every answer including empty ones.
_Avoid_: Completeness, health

**Structural blind spot**:
A blind spot from the fixed catalogue, attached to every answer that examined the record types it applies to.
_Avoid_: Limitation, caveat

**Condition blind spot**:
A blind spot measured from the store at call time (pruned or cleared history, rebuild, dropped records, drift, unverified Nightwatch version, active redaction).
_Avoid_: Warning, alert

**Empty kind**:
The named reason an answer has nothing to show: no store, unusable store, empty store, empty window or no match.
_Avoid_: No results, not found

**Verdict**:
The outcome of a judgement over records, exactly one of findings, clean or not evaluated, always stated with how many records were examined.
_Avoid_: Status, result, pass/fail

**Examined**:
The number of records a verdict was judged over.
_Avoid_: Sample size, coverage

**Clean**:
A verdict meaning nothing was found among the records captured, which says nothing about blind spots.
_Avoid_: Healthy, ok, no problems

**Not evaluated**:
A verdict meaning a judgement could not run, with a closed reason.
_Avoid_: Unknown, skipped, inconclusive

**Withheld**:
A statistic left out because too few records support it, given as a reason with what was had and needed.
_Avoid_: Estimated, partial

**Truncated entry**:
The stated record of one cut in an answer (rows, size, cell cap or a producer that stopped early), naming what was shown and how to get the rest.
_Avoid_: Truncated flag, page

**Cursor**:
An opaque string that continues a ranked or occurrence listing for the same tool, arguments and store, and fails only after a rebuild of the store; a clear keeps ids and cursors valid.
_Avoid_: Offset, page token

**Tool error**:
A failed call reported as plain text with a closed code and no answer shape, as opposed to an empty answer.
_Avoid_: Exception, failure answer

### Actors and attribution

**Identification**:
Resolving free text to one actor by ranked stages (exact id, username, name, contains), where the first stage with any row decides and several rows are never resolved by guessing. When no stage finds anyone, an id that no directory row holds is identified from a record that carries it.
_Avoid_: Lookup, search, matching

**Attribution**:
Assigning an execution or record to an actor by a recorded user or one of the attribution links, never by inference.
_Avoid_: Ownership, blame, tracking

**Attribution link**:
The reason an execution is attributed to an actor: direct (its own recorded user), dispatch (its dispatch names the actor) or inside (a command or scheduled task that had a child carrying the actor). An execution with no link to the actor asked about is another actor's when its own recorded user or its dispatch names someone else, and unattributable otherwise.
_Avoid_: Provenance, relationship, caused by

**No recorded user**:
The state of a record whose user id is empty: a guest, a user of a non-default guard, work with no actor, or one Nightwatch could not resolve; never "not signed in".
_Avoid_: Anonymous, guest (as a fact), signed out

**Unattributable**:
Work that no attribution link ties to the actor asked about, stated as a count next to what was attributed.
_Avoid_: Unknown, orphan, lost

### SQL access

**SQL tool**:
The tool through which the assistant runs its own single read-only statement against the store and receives raw values.
_Avoid_: Query endpoint, SQL console

**SQL child**:
The short-lived framework-free PHP process that runs one SQL tool call under fixed ceilings and is killed by its parent at the deadline.
_Avoid_: Worker, sandbox process, subprocess

**Readable set**:
The fixed list of views, tables and table-valued functions a statement may read, described by the describe tool.
_Avoid_: Whitelist, exposed tables, schema surface

**Ceiling**:
A fixed resource limit on one SQL call (time, memory, SQL size, output) that no setting can loosen.
_Avoid_: Quota, budget, limit setting

**Query stop**:
The named reason a SQL result ends: complete, limit, budget, deadline, memory, aborted or error; the last four are abnormal and mark the rows partial.
_Avoid_: Status, exit reason

**Raw values**:
Values returned by the SQL tool exactly as stored, without unit conversion or renaming.
_Avoid_: Formatted values, converted values

**SQL availability**:
Whether the SQL tool's isolation can be established on this machine, stated with a closed reason and identical wherever it is reported.
_Avoid_: SQL health, SQL support

### Analysis

**Occurrence**:
One stored record of one type as a single observation in a statistic.
_Avoid_: Sample, data point

**Group**:
The records that share one group hash, shown under its group label and named by the hash in the tools; a job's dispatches and its attempts share one hash.
_Avoid_: Cluster, bucket, category

**Nearest rank**:
The percentile method that returns the observed value at rank `max(1, ceil(n·p/100))` in ascending order, so the result is always an actual record's value.
_Avoid_: Interpolated percentile, estimate

**Sample floor**:
The fewest occurrences a statistic needs before it is shown (3 for the median, 20 for p95), below which it is withheld.
_Avoid_: Minimum sample, confidence threshold

**Typical value**:
A group's representative number: the median, or the mean below three occurrences, always stated with its basis.
_Avoid_: Average, normal value

**Change**:
The outcome of comparing one group's measure between two sides: slower, faster, heavier, lighter, more_calls, fewer_calls, steady, new, gone, zero_baseline, or not evaluated; descriptive, never a verdict.
_Avoid_: Regression, verdict, delta

**Change rule**:
The requirement that a value moved only if it exceeds both a 10% band and the measure's absolute noise floor.
_Avoid_: Significance test, tolerance

**Zero baseline**:
A change for a group present on both sides whose before value is 0 and after value is above 0.
_Avoid_: New, infinite change

**Side**:
One of the two parts a compared window is divided into, before or after: either part of a split point, or the records of one deploy of a deploy pair. Each is clipped to the type's coverage start and states its records and observed span; a side that begins at the coverage start, the before side of a split or either side of a deploy pair, also counts the records of the type, of its own deploy on a pair, that started before it, which are on neither side.
_Avoid_: Half, bucket, period

**Empty side**:
A side that holds no record of the compared type at all, so that nothing is evaluated and no absence of change is claimed; a single group missing from a side that holds its type is new or gone instead.
_Avoid_: No data, missing side

**Rollup**:
The count of every change over all compared groups, shown or cut, with the groups present on one side only and the groups the answer does not show.
_Avoid_: Summary row, totals

**Noise floor**:
The absolute change a measure must exceed to count as moved under the change rule: 1 ms for durations, 2 MiB for memory, 1 for counts.
_Avoid_: Threshold, tolerance

**Step-down**:
Comparing a group by its median instead of its 95th percentile because either side has fewer than 20 records, stated as measured on p50; never down to the maximum.
_Avoid_: Fallback, downgrade

**Observed span**:
The time from the first to the last start among a side's selected records, unknown below two records.
_Avoid_: Window length, duration of the side

**Volume measure**:
A measure that grows with how long a side observed (occurrences, total duration, the queries counter), judged only when the two spans are within a factor of two.
_Avoid_: Count measure

**Unequal spans**:
The reason a volume measure is not evaluated for a group: the longer observed span is more than twice the shorter, or one of the two is unknown.
_Avoid_: Uneven windows, skew

**Direction**:
The statement of a trend as rose, fell or held, from the medians of its first and last halves under the change rule.
_Avoid_: Trend verdict, slope

**Bucket**:
One of the equal slices a trend cuts its window into, half-open like the window except that the last one takes a derived until; a bucket with a value, records and no partial flag is valued, and only valued buckets decide the direction and the peak.
_Avoid_: Interval, bin, period

**Partial bucket**:
A bucket that starts before the type's coverage start, so it holds only the records from that start on; it is flagged and left out of the direction and the peak.
_Avoid_: Incomplete bucket, clipped bucket

**Peak**:
The valued bucket with the highest value of a trend's measure, the earliest on a tie; there is none below two valued buckets or when they all hold the same value.
_Avoid_: Maximum, spike

**Dominant stage**:
The stage of an execution group with the highest mean duration.
_Avoid_: Bottleneck, slowest phase

**Slow filter**:
The restriction of a group's records to those at or above the baseline of the selection (the group's own p50 or p95 when one group is selected), applied only when that baseline is above its floor.
_Avoid_: Outlier filter

### Tools and drill-down

**Ladder**:
The fixed order in which the assistant's tools are listed and linked, from the overview down to single records and schema lookups.
_Avoid_: Menu, workflow

**Overview**:
The entry-point tool that checks every problem shape and states the store's coverage at once.
_Avoid_: Dashboard, summary tool

**Fixed sections**:
The parts of the overview that are read before any detector runs and that no deadline skips: the error rate, the slowest groups by total time, the record counts, the user directory and the actors.
_Avoid_: Header, statistics

**Selector**:
An argument that says which records a listing is about: group, type, execution id, trace id, job id or user id.
_Avoid_: Filter (a filter narrows a selection, a selector defines it)

**Next call**:
A ready-to-run tool call offered at the end of an answer, built from that answer's own values.
_Avoid_: Suggestion, hint

**Baseline**:
The percentile of a measure over a selection, computed before other filters, against which an at-or-above filter cuts.
_Avoid_: Average, norm

**Derived bound**:
A window bound the tool filled in from the selected records because none was given, named on the window: a derived since is the first selected record, and a derived until is the last one and includes it.
_Avoid_: Default window, implied range

**Accounting**:
The per-counter comparison of an execution's counted total with the rows captured, stated as match, fewer or more.
_Avoid_: Reconciliation, audit

**Recipe check**:
A fingerprint's statement whether recomputing a group's recipe from a stored record reproduces that record's stored group hash: agrees, disagrees or not evaluated. The `fingerprint` tool recomputes the recipe for this check and skips a record whose recipe fields were cut.
_Avoid_: Validation, hash test

**Job outcome**:
The state of a queued job derived from its last attempt: processed, failed, retrying or pending.
_Avoid_: Job status (that is an attempt's own field)

**Wait**:
The time between a dispatch ending, or the previous attempt ending, and the next attempt starting.
_Avoid_: Latency, delay

### Detectors

**Detector**:
One of a closed set of fixed-shape judgements over the store that answers with a verdict and findings.
_Avoid_: Check, rule, alert, monitor

**Finding**:
One group a detector judged to meet its threshold, ordered worst first and carrying the detector's evidence.
_Avoid_: Issue, violation, hit

**Threshold**:
The one tunable value of a detector, with a stated unit, range and loose default, always stated on the result.
_Avoid_: Limit, setting, budget

**Reaches**:
The pair of figures a finding states about people, the distinct signed-in actors among its records and the records with no actor, never added.
_Avoid_: Users affected, impact

**Count mode**:
The run of all detectors at default thresholds that yields one cheap row per detector on the overview.
_Avoid_: Summary run, dashboard

**Run (of queries)**:
The queries of one query group inside one execution.
_Avoid_: Burst, repetition

**Typical share**:
A request group's share of time spent in queries, the median of per-request shares from three requests, else the aggregate ratio, stated with its basis.
_Avoid_: Average share, query time percentage

**Pending dispatch**:
A queued job with no stored attempt, aged against the store clock.
_Avoid_: Stuck job, lost job, waiting job

**Inline connection**:
A queue connection that runs work in the dispatching process (sync, deferred, background, null), so its dispatches have no wait.
_Avoid_: Sync queue

**Terminal failure**:
A job whose last stored attempt failed.
_Avoid_: Dead job, permanent failure

**Recovered job**:
A job that was released at least once and whose last attempt was processed.
_Avoid_: Flaky job

**Escaped exception**:
An exception that reached the framework's handler, as opposed to one caught and passed to report.
_Avoid_: Unhandled crash

**Fatal error**:
An exception record stored without a trace, which is how Nightwatch sends an error that ended the process; never inferred from a class or a code.
_Avoid_: Crash

**Application frame**:
The first frame of a stored trace whose file is the application's own, not a vendor's or an internal function, shown as file and line.
_Avoid_: User frame

**Message shape**:
A log message with UUIDs, hex runs of eight or more, and digit runs replaced by placeholders, used to group lines.
_Avoid_: Log pattern, fingerprint

**Fragment**:
The longest literal run of a message shape, the text an assistant can use to find the lines.
_Avoid_: Snippet, search key

**Activity**:
The per-store cache counters returned beside cache findings, so silence can be told from a healthy cache.
_Avoid_: Cache stats

### Diagnostics and lifecycle

**Doctor**:
The read-only command that runs a closed set of independent checks on the installation and reports each as ok, warn, fail or info, existing only where Firewatch is not stepped aside.
_Avoid_: Health check, diagnostics tool, status command

**Doctor check**:
One line of the doctor's report, with an id, a status, a message and, for warn and fail, a one-line fix.
_Avoid_: Test, probe, validation

**Launch command**:
The one command an assistant's client runs to start Firewatch's server, `php artisan firewatch:server`, from the project root.
_Avoid_: Start command, connect command

**Tool listing**:
What the server command prints with `--list`: exactly what `tools/list` would return, without a session and without touching the store.
_Avoid_: Inspector, tool catalogue

**Server session**:
One run of the server process from an assistant's launch to end of its input, holding no state between calls.
_Avoid_: Connection, daemon

### Configuration

**Configuration issue**:
A stated problem with one configuration value or budget entry, naming the key, the reason and what is used instead, which never stops capture.
_Avoid_: Config error, validation failure

**Fallback**:
The default a key takes when its value is invalid, applied to that key alone.
_Avoid_: Recovery, coercion

**Busy timeout**:
How long the capture side waits for a busy store before dropping the batch.
_Avoid_: Lock timeout, write timeout

### Budgets

**Budget**:
A ceiling on the duration or peak memory of one kind of execution, set by the developer.
_Avoid_: Threshold (that is a detector's), SLA, limit

**Budget entry**:
One item of the budgets list: an execution type, optional matchers and one or two ceilings.
_Avoid_: Rule, budget rule

**Global entry**:
The budget entry of an execution type that has no matchers and governs every execution the type's specific entries do not.
_Avoid_: Default budget, catch-all

**Governing entry**:
The one budget entry that judges an execution or group: the first matching specific entry, else the type's global entry.
_Avoid_: Applied rule, winning entry

**Budget verdict**:
The outcome of judging an execution or group against its governing entry, exactly one of within, exceeded or not evaluated, never within by absence.
_Avoid_: Budget status, pass/fail

**Measured on**:
The statement of which figure a verdict or compare row used: the p95, the p50 or the maximum (for a budget on a group, the maximum below 20 executions).
_Avoid_: Basis, statistic

**Ignored entries**:
The count of budget entries dropped as invalid, stated beside a budget verdict.
_Avoid_: Bad entries, skipped rules

### Testing

**Scenario**:
A named test that builds a store for one question an assistant asks and walks the tool ladder as the assistant would, asserting the structured answer.
_Avoid_: Integration test, end-to-end test

**Wire fixture**:
A committed, normalised example of one wire record type, generated from the real sensors and never edited by hand.
_Avoid_: Sample, mock record, golden file

**Synthetic record**:
A wire record built from a wire fixture with chosen values and sent through Firewatch's real ingest, used only where the sensors cannot give exact values or volume.
_Avoid_: Fake record, seeded row

**Canary**:
The scheduled run against the newest Nightwatch release that reports drift before a release reaches users.
_Avoid_: Nightly, smoke test

**Unverified state**:
The condition of a Nightwatch release on a higher minor than the verified line, or a development build, which every answer states and which never stops capture; patch releases of the verified minor and lower versions are not in it.
_Avoid_: Unsupported, incompatible

**Store identity**:
The recognisable identity of the store file, remembered by a connection so that a file deleted or replaced under it is noticed and reopened.
_Avoid_: File lock, checksum
