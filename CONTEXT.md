# Firewatch

A local, dev-only telemetry store fed by Laravel Nightwatch's sensors and read by an AI assistant over the Model Context Protocol. Nothing leaves the machine; the assistant is the only interface.

## Language

### Interception

**Interception**:
Taking Nightwatch's records into Firewatch's store instead of letting them be transmitted.
_Avoid_: Tap, hook, proxy

**Seam**:
The one public point where Firewatch intercepts Nightwatch's output, the `IngestingEvents` event.
_Avoid_: Hook point, integration point

**Veto**:
Cancelling Nightwatch's transmit of a batch by returning `false` from the seam listener; Firewatch always vetoes.
_Avoid_: Block, cancel, tee

**Batch**:
The ordered list of wire records Nightwatch offers at the seam in one dispatch, which may be part of an execution.
_Avoid_: Payload, digest

**Sensor**:
A Nightwatch component that observes one kind of application activity and produces wire records.
_Avoid_: Collector, probe

**Wire record**:
One final array Nightwatch would send, identified by its type `t` and version `v`.
_Avoid_: Event, message

### Modes and capture

**Active**:
The mode in which Firewatch captures: it enables Nightwatch, forces the capture posture and vetoes every batch.
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
The state of a captured record whose shape differs from the contract table, so some of its data is kept but not understood.
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
The single integer handle of a stored record, unique across all types and never reused except after a drop, used only to page and prune, never as a link or identity.
_Avoid_: Primary key (as domain identity), record id

**Trace**:
The set of records sharing a trace id across a request and the work it caused; derived, never stored as its own entity.
_Avoid_: Session, chain (as an entity)

**Started at / ended at**:
A record's normalized start instant in Unix seconds with microsecond resolution and its derived end.
_Avoid_: Timestamp, created at, received at

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
_Avoid_: Fingerprint, group key

**Group label**:
The human-readable name of a group, taken from the group's latest record.
_Avoid_: Group name, title

**Not measured**:
The meaning of any value the sensors never populate (four counters, mail and notification failure flags); never "clean".
_Avoid_: Zero, none, clean, healthy

**Incomplete**:
A stated condition of an execution or child whose counterpart records are missing or fewer than counted; never an error.
_Avoid_: Corrupt, lost, error

**Counted versus captured**:
The comparison between an execution's counters and the child records actually stored.
_Avoid_: Coverage, completeness ratio

### Data

**Bindings**:
The query parameter values captured at Firewatch's seam and paired to a query record, or absent when unpaired.
_Avoid_: Parameters, params

**User directory**:
The small table of user id, name, username, first-seen and last-seen instants, never an event and never counted, aged out with the records.
_Avoid_: Actors table, users event

**Actor**:
The person behind a user id recorded on a request, job attempt or child event, named through the user directory when a row exists.
_Avoid_: Account, principal, customer

### Store

**Store**:
The single SQLite file holding all captured records, the user directory, drift counts and a few facts about the capture.
_Avoid_: Database, cache, log file

**Record view**:
One of the twelve per-type views over the store's records, with typed columns named as on the wire, which are the documented query surface.
_Avoid_: Table, model, schema table

**Raw table**:
The single table of all records that the record views read from, queryable directly for work across types.
_Avoid_: Events table, main table

**Store state**:
The named condition of the store a reader reports when it cannot be read as usual: absent, schema mismatch, corrupt, foreign, busy or unavailable.
_Avoid_: Health, error

**Schema version**:
The integer stamped in the store that says which shape it was created with; any other value causes a rebuild, not a migration.
_Avoid_: Migration number, database version

**Rebuild**:
Dropping and recreating everything in the store in place when its schema version differs, which discards its data.
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
The half-open interval on records' start times over which a windowed answer is computed; an absent bound means unbounded, never recent.
_Avoid_: Time range, period, default range

**Split point**:
An instant strictly inside a window that divides it into a before side and an after side, the record exactly at it being after.
_Avoid_: Anchor, checkpoint

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
An opaque string that continues a ranked or occurrence listing for the same tool, arguments and store, and fails after a clear or rebuild.
_Avoid_: Offset, page token

**Tool error**:
A failed call reported as plain text with a closed code and no answer shape, as opposed to an empty answer.
_Avoid_: Exception, failure answer

### Actors and attribution

**Identification**:
Resolving free text to one actor by ranked stages (exact id, username, name, contains), where the first stage with any row decides and several rows are never resolved by guessing.
_Avoid_: Lookup, search, matching

**Attribution**:
Assigning an execution or record to an actor by a recorded user or one of the attribution links, never by inference.
_Avoid_: Ownership, blame, tracking

**Attribution link**:
The reason an execution is attributed to an actor: direct (its own recorded user), dispatch (its dispatch names the actor) or inside (a command or scheduled task that had a child carrying the actor).
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

**Observed span**:
The time from the first to the last start among a side's selected records, unknown below two records.
_Avoid_: Window length, duration of the side

**Volume measure**:
A measure that grows with how long a side observed (occurrences, total duration, the queries counter), judged only when the two spans are within a factor of two.
_Avoid_: Count measure

**Direction**:
The statement of a trend as rose, fell or held, from the medians of its first and last halves under the change rule.
_Avoid_: Trend verdict, slope

**Dominant stage**:
The stage of an execution group with the highest mean duration.
_Avoid_: Bottleneck, slowest phase

**Slow filter**:
The restriction of a group's records to those at or above the group's own p50 or p95, applied only when that baseline is above its floor.
_Avoid_: Outlier filter

### Tools and drill-down

**Ladder**:
The fixed order in which the assistant's tools are listed and linked, from the overview down to single records and schema lookups.
_Avoid_: Menu, workflow

**Overview**:
The entry-point tool that checks every problem shape and states the store's coverage at once.
_Avoid_: Dashboard, summary tool

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
A window bound the tool filled in from the selected records because none was given, named on the window.
_Avoid_: Default window, implied range

**Accounting**:
The per-counter comparison of an execution's counted total with the rows captured, stated as match, fewer or more.
_Avoid_: Reconciliation, audit

**Recipe check**:
A fingerprint's statement whether recomputing a group's recipe from a stored record reproduces that record's stored group hash: agrees, disagrees or not evaluated.
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
