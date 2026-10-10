<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Instructions
    |--------------------------------------------------------------------------
    |
    | The text the server sends once when an assistant connects. Each
    | tool description repeats the rules that matter to that tool,
    | because a few clients drop these instructions altogether.
    |
    */

    'instructions' => <<<'TEXT'
        Firewatch is a local, dev-only record of what a Laravel application did while it was developed: requests, commands, queued jobs and scheduled tasks, and the queries, exceptions, logs, cache events, mail, notifications and outgoing requests inside them. It only reads, and Firewatch itself sends nothing anywhere.

        Start with `overview`, then drill down: `overview` (what the store holds), `detect` (named problem shapes and their evidence), `rank` (worst routes, queries, jobs), `occurrences` (individual records), `execution` (one request, command, job attempt or task in full), `trace` (a trace's executions and the lineage of its queued jobs), `actor` (one signed-in person), `compare` (before against after), `trend` (a measure over equal buckets of time), `query` (your own read-only SQL, last resort), `describe` (store facts, deploys, units and every column `query` reads with example statements; `type` gives one object's columns and recent values), `fingerprint` (the group id of a route, command, job, task, query, cache key, host or mail class you read in source, and whether the store holds it). Every answer ends with `next`: calls you can run as written.

        Reading answers: empty is not clean. Every answer states the store clock, the window, coverage and blind spots (what Firewatch cannot see). Null means unknown, not zero. Truncated means only the worst rows are shown: narrow the call or use the cursor. Durations end in _ms, memory in _mb; times are in the application timezone, named on the window; identifiers print in full and go straight back into tools. There is no default window: leave since and until out and everything stored is used.
        TEXT,

    /*
    |--------------------------------------------------------------------------
    | Tool Descriptions
    |--------------------------------------------------------------------------
    |
    | The description each tool sends in the listing, from which an
    | assistant picks a tool and its arguments. Every description
    | stays within the ceiling of one hundred and fifty words.
    |
    */

    'tools' => [
        'overview' => 'Entry point. Answers "what is wrong in this application?" in a fixed order: the error rate (server errors 500 and above and client errors 400 to 499 among the window\'s requests, with their shares of the requests that have a status), the slowest groups by total time (up to 10, at most 3 per type), the records of all twelve types, the user directory (the whole store, never windowed), the actors (distinct signed-in actors and executions with no user, never summed), then the verdict of every problem shape, each checked at its default threshold within five seconds. Nothing is wrong only when every shape ran and is clean; a shape that did not run says so. Use it first, then `detect` for one shape, `rank` for the worst groups of a type, `occurrences` for individual records and `execution` for one in full. Windowed by since/until; without them everything stored counts. Empty is not clean.',
        'rank' => 'Ranks the groups of one type (routes, queries, jobs, exceptions and so on) by a measure, worst first, to answer "what is slow, heavy or frequent?". Pass `type`, or `group` to break one group down by deploy (rows in first-seen order) to see whether it changed. `matching` finds a group by a substring of its label. `by` picks the measure, p95_duration by default and occurrences for exceptions. Percentiles are null with a `withheld` object when too few records support them; when no group has enough for the percentile, the order falls back to the maximum and a note says so. Rows carry when the group was first and last seen, its deploys and its slowest execution, and failure_pct where the type has a notion of failure. Windowed by since/until; `deploy` restricts the records; a cursor continues a cut list. Empty is not clean.',
        'execution' => 'One execution in full: a request, command, job attempt or scheduled task. Without arguments it returns the latest one that finished (greatest end time); `type` picks the latest of one kind; `execution_id` picks a specific one (a request\'s trace id is also its execution id). Shows outcome, stages, request headers and payload as captured, counted-versus-captured accounting for eight counters, up to five exceptions with application frames and source lines, and the child timeline. Not windowed. For every attempt of a job use `occurrences` with `job_id`. Recorded when finished: running work is absent.',
        'occurrences' => 'Lists individual records, newest first by default, for the selectors you give (at least one): `group`, `type`, `execution_id`, `trace_id`, `job_id`, `user_id`. Order by recent, slowest, memory or queries. Filters (a filter that does not fit the type is refused): method, status, outcome, level, slower_than_ms, at_or_above (median or p95 of the selection), matching (substring). Rows carry group, name, location (file:line), user and a `detail` object; a query group also lists its distinct call sites. Windowed; cursor for more. Empty is not clean.',
        'trace' => 'Follows one trace: its executions in start order and the lineage of every queued job. A lineage shows the dispatch, attempts in order, wait before each attempt (wait_ms), outcome (processed, failed, retrying, pending) and partial states (no_dispatch, no_attempts). Give exactly one of `trace_id` or `job_id`. Lineage joins on job id, so it is complete even when attempts carry other traces. A job on an inline connection (sync, deferred, background, null) runs in the dispatching process and the sensors record no dispatch for it; a dispatch recorded on one shows no attempts and no outcome. Not windowed. Use `execution` for children, exceptions and source lines.',
        'detect' => 'Runs named problem shapes and returns evidence, worst first. `n-plus-one`: read query one execution ran 3+ times, in runs. `database-bound`: request groups typically spending 60+ percent in queries. `failing-routes`: request groups answering 400+. `failing-jobs`: job groups with 1+ failed or released attempts. `queue-latency`: job groups with first-attempt wait or pending age of 5000+ milliseconds. `failing-tasks`: scheduled-task groups with failed or skipped tasks (no threshold). `exception-clusters`: exception groups with 1+ occurrences, escaped first. `error-logs`: error-level logs by message shape, 1+ occurrences (no `group`). `failing-http`: outgoing-request hosts answering 400+. `cache`: cache keys with a hit rate below 50 percent over 3+ reads, or a failed write or delete. `memory`: execution groups peaking at 64+ megabytes. Without `shape` all run; `threshold` and `group` need one. Each returns a verdict (findings, clean, not_evaluated) over what it examined, its threshold, unit and range, exact total, up to `limit` findings (1 to 100, default 20) and caveats. Clean: none among what was captured, weak over few records. Windowed.',
        'compare' => 'Compares each group across a split or a deploy pair: "did my change help?". Give exactly one boundary. split_at is a time, such as the now of an earlier answer; before is since to split_at, after is split_at to until. Or deploy_before and deploy_after, exact deploy strings: each side is every record of its deploy in the window. A deploy pair cannot separate an uncommitted edit; a time split can. Without since: the type\'s coverage start; without until: now. Pass `type`, or `group` for one group. Rows are ordered by absolute change, so the largest change in either direction survives the limit; a rollup counts every group, cut or not. Too few records is not evaluated, never guessed; an empty side is never "no regression". Work spanning `split_at` counts as before.',
        'actor' => 'Identifies one signed-in person and the work of the window tied to them. `who` is a user id, a username or a name, tried in that order, then as a part of a name or username: the first stage that finds anyone decides. An id must be exact; elsewhere case is ignored, for ASCII letters only. An email works only where the username is the email. Several matches are listed as candidates, never guessed: repeat with an id. An actor exists only once recorded acting. An execution is theirs by its own user, its job\'s dispatch, or a child inside a command or task. Attribution is partial: what no link reaches is counted, never guessed. Windowed by since/until; identity is read over the whole store.',
        'trend' => 'Cuts the window into equal buckets and reports a measure per bucket, to see whether something rose, fell or held, and where it peaked. Pass `type` or `group`. `by`: occurrences (default), max_duration, avg_duration, total_duration, max_memory (execution types only). Missing since/until are derived from the selected records, and the window says which. Empty bucket: 0 for occurrences, null for other measures. Buckets that start before coverage are partial. Bucket edges can be passed back as since/until. Windowed.',
        'query' => 'Last resort: runs your own read-only SQL on Firewatch\'s store. One SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the twelve record views (requests, commands, job_attempts, scheduled_tasks, queries, exceptions, logs, cache_events, mail, notifications, outgoing_requests, queued_jobs), records, users, drift, meta, json_each and json_tree; anything else, and a function off the allow-list, is refused. Values are raw: times epoch seconds, durations microseconds, memory bytes. `limit` 1 to 500 (default 50). Blind spots follow the record types read. Prefer `rank`, `occurrences` and `detect`, which convert units. Not windowed.',
        'describe' => 'Schema, units, deploys and examples for `query`.',
        'fingerprint' => 'The group id of something read in source, and whether the store holds it. Not windowed.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Answers
    |--------------------------------------------------------------------------
    |
    | The fixed lines an answer is built from. The store clock line
    | is on every answer, so that an assistant can pass the epoch
    | straight back into a tool as either bound of its window.
    |
    */

    'no_store' => 'No application process has written a store at :path yet: exercise the application, then ask again.',

    'store_empty' => 'The store at :path holds no records: nothing was captured yet, or it was cleared. Exercise the application, then ask again.',

    'store_unusable' => [
        'foreign_file' => 'The file at :path is not a Firewatch store, and Firewatch will not touch it: set `database` to another path.',
        'older_schema' => 'The store at :path was written by an older Firewatch schema (version :found, this release reads version :expected). The next captured batch rebuilds it; it holds no readable data until then.',
        'newer_schema' => 'The store at :path was written by a newer Firewatch schema (version :found, this release reads version :expected). This release never rebuilds it and drops what it captures; upgrade Firewatch to read it.',
        'sqlite_too_old' => 'SQLite :version is older than the :minimum Firewatch needs, so nothing is captured and the store at :path can not be read.',
        'unreadable' => 'The store at :path can not be read: :cause',
    ],

    'store_causes' => [
        'corrupt' => 'the file is damaged. The next captured batch moves it aside and starts a new one.',
        'busy' => 'it stayed busy for 1000 ms, so try again.',
    ],

    'store_clock' => 'Store clock: :time (epoch :epoch) - pass that number as since or until to measure what happens next against what came before',

    'overview_summary' => 'In the window: records :records, requests with a status :with_status, server errors :server_errors, client errors :client_errors.',

    'overview_summary_no_status' => 'In the window: records :records, requests with a status 0, so no error rate.',

    'overview_unknown_types' => ':count record in the window is of no known type: it counts in records and in no row of records_by_type.|:count records in the window are of no known type: they count in records and in no row of records_by_type.',

    'overview_directory_unwindowed' => 'user_directory counts the whole store: a user has no start to window by.',

    'overview_next_rank' => 'Rank every :type group by total time: this answer lists at most three groups of a type.',

    'overview_next_execution' => 'Open the execution of the window that finished last, in full.',

    'overview_detectors_findings' => 'Findings: :shapes.',

    'overview_detectors_not_evaluated' => 'Not evaluated: :shapes.',

    'overview_detectors_clean' => 'No findings: the shape is clean over what was captured.|No findings: all :count shapes are clean over what was captured.',

    'overview_budgets' => 'Budgets: :groups exceeded, :within within, :not_evaluated not evaluated:ignored. Groups not listed are not proven within budget unless counted as within.',

    'overview_budgets_groups' => ':count group|:count groups',

    'overview_budgets_unevaluated' => 'Budgets: not evaluated (:reason):ignored',

    'overview_budgets_over' => ':count group over budget.|:count groups over budget.',

    'overview_budgets_truncated_how' => 'Rank a type of execution: each of its groups carries a budget verdict.',

    'window_empty' => 'No records fall in this window, and the store holds :population records: widen the window or move it.',

    'no_match' => 'No record matched the filters (:filters), among :population records before filtering.',

    'empty_summary' => [
        'no_store' => 'Nothing to report: no store has been written yet.',
        'store_unusable' => 'Nothing to report: the store can not be used.',
        'store_empty' => 'Nothing to report: the store holds no records.',
        'window_empty' => 'Nothing to report: no records fall in the window.',
        'no_match' => 'Nothing to report: no records matched the filters.',
    ],

    'window_description' => 'Half-open on started_at: a record at since is in, a record at until is out; an absent bound is unbounded.',

    'window_unbounded' => 'Window: none (unbounded)',

    'window_bounded' => 'Window: since :since until :until (:timezone, half-open)',

    'window_none' => 'none (unbounded)',

    'window_not_windowed' => 'Not windowed: :reason',

    'window_description_derived' => 'Half-open on started_at, except a derived until: it is the last selected record and includes it.',

    'window_resolved' => 'Window: since :since until :until (:timezone, half-open; :derived)',

    'window_derived_since' => 'since derived from the first record',

    'window_derived_until' => 'until derived from the last record and included',

    'store_line' => 'Store: :store',

    'store_history' => 'history complete from :from (:reason); retention :age, :records records',

    'store_unlimited' => 'unlimited',

    'store_to' => 'to',

    'store_records' => ':count records',

    'store_straddling' => ':count straddling the split',

    'truncated' => 'Truncated: :section shows :shown of :matched (:reason). :how',

    'truncated_cap' => 'Truncated: :section had cells cut at :characters characters, :shown in all (:reason). :how',

    'cap_how' => 'Cells are cut at :characters characters, and no tool shows the rest of a value.',

    'size_how' => 'The answer is over its budget of :characters characters: narrow the call (a shorter window, a lower limit or a filter) to see the rest.',

    'cell_truncated' => '... [truncated, :count characters]',

    'truncated_unknown' => 'Truncated: :section shows :shown of more (:reason). :how',

    'blind_spots' => [
        'console-requests' => 'Requests are recorded only while the application serves HTTP; test runs and console processes record none. An empty request answer may mean the app was never exercised over HTTP.',
        'unanswered-outgoing-requests' => 'An outgoing request that received no response (timeout, connection failure) leaves no record.',
        'payload-on-server-error-only' => 'A request\'s payload is stored only when the response status is 500.',
        'dead-counters' => 'lazy_loads, hydrated_models, files_read and files_written are always 0 because the sensors never fill them; 0 does not mean none. A lazy load shows as one query repeated in an `execution` timeline.',
        'failed-flag-unpopulated' => 'The failed flag on mail and notification records is always false; failures are not recorded.',
        'mail-by-notification' => 'Mail sent by a notification is recorded as a notification, not as mail.',
        'sync-jobs-unrecorded' => 'Jobs on the sync connection run inside the dispatching execution and have no attempt record.',
        'vendor-defaults-unrecorded' => 'Vendor commands and framework cache keys on Nightwatch\'s default exclusion lists are not recorded.',
        'exceptions-unreported' => 'Exceptions the application does not report are not recorded, nor are exceptions that a scheduled task running in the scheduler\'s own process reports without throwing.',
        'named-log-channels' => 'Logs written directly to a named channel are not captured; only the default channel is.',
        'memory-is-process-peak' => 'Memory is the whole process\'s peak since the sensor last reset it: requests, scheduled tasks and job attempts reset it, a command reports its whole process; it is not attributable to code.',
        'query-bindings-unpaired' => 'A query\'s bindings are null when they could not be paired with certainty; null means unknown, not none.',
        'uninstrumented-dispatcher' => 'A job dispatched where Nightwatch was not running has attempts but no dispatch, and may have a fresh trace per attempt; join on job id, not trace id.',
        'actor-partial' => 'Commands and scheduled tasks carry no actor; requests with no recorded user may be guests or users of a non-default guard; ids are keys, not people; names come from the application\'s user callback, and impersonation shows the impersonated user.',
        'visible-at-completion' => 'An execution is recorded when it finishes: work still running is absent, and work spanning a boundary sits on the side where it started.',
        'application-opt-outs' => 'Anything the application\'s opt-outs excluded (pause, ignore, reject callbacks, never-sample) and processes killed before flushing are absent.',
        'values-truncated' => 'Long values are cut at ingest (:field_bytes bytes per string, bindings :bindings_bytes bytes in all) and again to :characters characters when printed; the SQL tool shows the stored value up to that cap, and substr() reads the rest of a cell.',
        'octane-bootstrap' => 'Under Octane the request bootstrap stage is always 0, because the worker is already booted; compare stage shares only between requests served the same way.',
    ],

    'conditions' => [
        'history-pruned' => 'History before :from was pruned (:reason); this window starts before it.',
        'history-cleared' => 'History before :from was cleared; this window starts before it.',
        'store-rebuilt' => 'The store was rebuilt at :at (:why); earlier data is gone.',
        'records-dropped' => ':n records were not stored between :from and :to (last reason: :reason); results may be incomplete.',
        'drift' => ':count :kind drift on :type, last :last; fields may be null or missing.',
        'nightwatch-unverified' => 'Nightwatch :version is newer than the verified line :line; records may be partly interpreted.',
        'redaction-active' => 'Some request headers or payload fields are redacted and read [N bytes redacted].',
    ],

    'clear' => [
        'nothing' => 'Nothing to clear.',
        'cleared' => 'Cleared :records records and :users users. Store size :before -> :after.',
        'cleared_type' => 'Cleared :records :type records. Store size :before -> :after.',
        'log_in_use' => 'The write-ahead log was not truncated because the store is in use.',
        'confirm' => 'This deletes all captured records, users and failure lines from :path. Continue?',
        'confirm_type' => 'This deletes all :type records from :path. Continue?',
        'confirm_drop' => 'This drops and rebuilds the store at :path, discarding everything including diagnostics. Continue?',
        'drop_with_type' => '`--drop` can\'t be combined with `--type`.',
        'rebuilt' => 'Rebuilt the store at :path. Store size :before -> :after.',
        'replaced_damaged' => 'The store file was damaged; moved to :file and created a new store.',
        'declined' => 'Aborted.',
        'not_forced' => 'Aborted: pass --force to clear without confirmation.',
        'unknown_type' => 'Unknown type ":type". Valid types: :types.',
        'busy' => 'The store is busy; try again.',
        'schema' => 'The store was written by another Firewatch schema (found :found, expected :expected). Run with --drop to rebuild it now; a captured batch rebuilds only an older one.',
        'damaged' => 'The store file is damaged; run with --drop or let the next capture replace it.',
        'foreign' => ':path is not a Firewatch store; nothing was changed.',
        'sqlite' => 'SQLite :version is older than the :minimum Firewatch needs; nothing was changed.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Doctor Command
    |--------------------------------------------------------------------------
    |
    | What `firewatch:doctor` prints for each check. A key is the
    | check id, then the case it reports, and a warning or a
    | failure has a second key with `_fix` for its fix line.
    |
    */

    'doctor' => [
        'threw_fix' => 'This is a Firewatch bug: report it with the output of `php artisan firewatch:doctor --json`.',

        'none' => 'none',

        'on' => 'on',

        'off' => 'off',

        'mode' => [
            'ok' => 'environment :environment is on the allowlist (:environments) and Firewatch is enabled; a request or job here runs :mode',
            'production' => 'the allowlist (:environments) names :production, so Firewatch captures there when it is installed',
            'production_fix' => 'remove production and prod from FIREWATCH_ENVIRONMENTS and install Firewatch with `composer install --no-dev` there',
            'disabled' => 'FIREWATCH_ENABLED is false in :environment (allowlist :environments), so nothing is captured',
            'disabled_fix' => 'set FIREWATCH_ENABLED=true, then restart queue workers and the assistant session',
        ],

        'php' => [
            'ok' => 'PHP :version at :binary',
            'too_old' => 'PHP :version at :binary is older than the :minimum Firewatch needs',
            'too_old_fix' => 'run the application on PHP :minimum or newer',
        ],

        'sqlite' => [
            'ok' => 'SQLite :version through ext-sqlite3',
            'missing' => 'ext-sqlite3 is not loaded, so nothing is captured',
            'missing_fix' => 'enable the sqlite3 extension for :binary',
            'too_old' => 'SQLite :version is older than the :minimum Firewatch needs, so nothing is captured',
            'too_old_fix' => 'link PHP against SQLite :minimum or newer',
            'wal_reset' => 'SQLite :version is in the write-ahead log reset range; the risk is low and the write lock that avoids it is active',
            'wal_reset_fix' => 'upgrade SQLite to a release outside the range when convenient',
        ],

        'nightwatch' => [
            'ok' => 'Nightwatch :version, on the verified :line line',
            'unverified' => 'Nightwatch :version is newer than the verified :line line, so captured shapes may drift',
            'unverified_fix' => 'pin laravel/nightwatch to :line.* or upgrade Firewatch, and watch store-drift',
            'api' => 'Nightwatch :version lacks IngestingEvents or its records property, so Firewatch can\'t guard its ingest',
            'api_fix' => 'install laravel/nightwatch :line.*',
        ],

        'nightwatch-order' => [
            'ok' => 'Firewatch registers before Nightwatch and no other listener sees what Nightwatch ingests',
            'registered_first' => 'Nightwatch\'s provider registered before Firewatch\'s, so Nightwatch runs with its own defaults',
            'registered_first_fix' => 'run `composer dump-autoload` or `php artisan package:discover`',
            'listeners' => '{1} :count other listener can see or veto what Nightwatch ingests|[2,*] :count other listeners can see or veto what Nightwatch ingests',
            'listeners_fix' => 'remove the application\'s own IngestingEvents listeners while Firewatch is installed',
        ],

        'config' => [
            'ok' => 'the configuration is valid',
            'issue_fix' => 'edit :key in config/firewatch.php or its environment variable',
        ],

        'budgets' => [
            'ok' => '{0} no budgets configured|{1} :count budget entry|[2,*] :count budget entries',
            'issue_fix' => 'edit :key in the budgets list of config/firewatch.php',
            'shadowed' => 'budgets[:number] can never govern, because an earlier entry of the same type with no matcher does',
        ],

        'store-path' => [
            'ok' => 'store at :path',
            'refused' => ':issue; the store is at :path',
            'refused_fix' => 'set FIREWATCH_DATABASE (or database in config/firewatch.php) to a path outside public/',
        ],

        'capture-posture' => [
            'posture' => 'payload fields redacted: :fields; headers redacted: :headers; request payloads :payload; logs :logs',
            'nightwatch_defaults' => 'Nightwatch\'s defaults are in effect, because Firewatch\'s capture settings were not applied',
        ],

        'store-permissions' => [
            'ok' => 'directory 0700, file 0600',
            'absent' => 'no store directory yet',
            'windows' => 'not applicable on Windows',
            'looser' => 'directory :directory_mode and file :file_mode are looser than 0700 and 0600',
            'looser_fix' => 'run `chmod 700 :directory` and `chmod 600 :path`',
        ],

        'store-gitignore' => [
            'ok' => ':directory has its own .gitignore',
            'absent' => 'no store directory yet',
            'missing' => ':directory has no .gitignore, so the store can be committed',
            'missing_fix' => 'create :directory/.gitignore containing *',
        ],

        'store-identity' => [
            'ok' => 'Firewatch store at :path, schema :version',
            'absent' => 'no store yet at :path; the first captured request or job creates it',
            'foreign' => ':path is not a Firewatch store, so Firewatch never touches it and captures nothing',
            'foreign_fix' => 'set FIREWATCH_DATABASE to another path, or move the file away',
            'older' => 'the store is schema :found and this release writes :expected; the next captured batch rebuilds it',
            'older_fix' => 'exercise the application, or run `php artisan firewatch:clear --drop` to rebuild it now',
            'newer' => 'the store is schema :found from a newer Firewatch and this release reads :expected; capture drops its batches',
            'newer_fix' => 'upgrade Firewatch, or run `php artisan firewatch:clear --drop` to discard the store',
            'damaged' => 'a Firewatch store whose schema can\'t be read; see store-integrity',
        ],

        'store-integrity' => [
            'ok' => 'quick_check found no problem',
            'problems' => 'quick_check found :problems, the first being: :first',
            'problem_count' => '{1} :count problem|[2,*] :count problems',
            'damaged' => 'the store file is damaged',
            'damaged_fix' => 'run `php artisan firewatch:clear --drop`, or let the next captured batch move it aside',
        ],

        'store-activity' => [
            'ok' => ':records from :oldest to :newest, :file on disk and :live live; retention :age or :limit records; busy timeout :busy_timeout ms; last prune :prune:coverage',
            'quiet' => 'nothing was captured in the last 24 hours; :records from :oldest to :newest, :file on disk and :live live; retention :age or :limit records; busy timeout :busy_timeout ms; last prune :prune:coverage',
            'quiet_fix' => 'exercise the application in an allowed environment, and see mode',
            'empty' => 'the store holds no records; retention :age or :limit records; busy timeout :busy_timeout ms',
            'records' => '{1} :count record|[2,*] :count records',
            'never' => 'never',
            'coverage' => '; :type complete from :from (:reason)',
        ],

        'store-losses' => [
            'ok' => 'no dropped batches',
            'dropped' => ':batches dropped, holding :records in all; the newest at :at (:kind): :message',
            'batches' => '{1} :count batch|[2,*] :count batches',
            'busy_fix' => 'raise FIREWATCH_BUSY_TIMEOUT above :milliseconds ms, or close what holds the store',
            'full_fix' => 'free disk space or lower FIREWATCH_RETENTION_RECORDS',
            'other_fix' => 'act on the message, then run `php artisan firewatch:clear` to empty the log',
        ],

        'store-drift' => [
            'ok' => 'no drift',
            'found' => 'drift seen: :rows',
            'store' => 'store',
            'found_fix' => 'pin laravel/nightwatch to :line.* or upgrade Firewatch',
        ],

        'server' => [
            'ok' => 'the server (:version) boots and lists :count tools, with instructions',
            'tools' => 'the server lists no tools, or a tool without a name or a description',
            'instructions' => 'the server has no instructions',
            'fix' => 'run `composer install` and `php artisan optimize:clear`, then `php artisan firewatch:server --list`',
        ],

        'sql-access' => [
            'ok' => 'the SQL tool can run; its child started on a read-only connection',
            'unavailable' => 'the SQL tool is unavailable (:reason); every other tool works',
            'proc_open_missing_fix' => 'remove proc_open from disable_functions for :binary',
            'sqlite3_missing_fix' => 'enable the sqlite3 extension for :binary',
            'php_binary_fix' => 'run the server with a PHP binary that is an executable file',
            'sqlite_too_old_fix' => 'link PHP against SQLite :minimum or newer',
            'spawn_failed_fix' => 'run `:binary -r "echo 1;"` to see why a PHP process can\'t start',
            'authorizer_fix' => 'link PHP against a SQLite built with the authorizer',
            'heap_limit_fix' => 'link PHP against a SQLite that accepts a soft heap limit',
        ],

        'client' => [
            'launch' => 'launch command: :command (run from the project root)',
        ],

        'store' => [
            'absent' => 'no store yet',
            'busy' => 'the store stayed busy, so it was not checked',
            'busy_fix' => 'run the doctor again',
            'unavailable' => 'not checked, because SQLite is below the floor (see sqlite)',
            'see_identity' => 'not checked; see store-identity',
            'see_integrity' => 'not checked; see store-integrity',
        ],
    ],

    'blind_spot' => 'Blind spot (:id): :message',

    'next' => 'Next:',

    'format_argument' => 'markdown (default) or json: the same answer either way.',

    'missing_argument' => "error: missing_argument\n`:argument` is required.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'invalid_argument' => "error: invalid_argument\n`:argument` must be :expected; got :value.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'unknown_argument' => "error: invalid_argument\n`:argument` is not an argument of :tool.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'inapplicable_argument' => "error: conflicting_arguments\n`:argument` does not apply to :tool.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'conflicting_arguments' => "error: conflicting_arguments\n`:argument` does not apply with `:with`.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'split_outside_window' => "error: split_outside_window\n`split_at` must lie strictly between `since` and `until`.\nargument: split_at\naccepted: a time after `since` and before `until`\nexample: :example",

    'not_found' => "error: not_found\nNo record `:id` exists in the store; the identifier may have been pruned or cleared.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'bad_cursor' => "error: bad_cursor\n`cursor` does not belong to this call (tool, arguments or store changed); start again without it.\nargument: cursor\naccepted: the `next` cursor of the previous answer to the same call\nexample: :tool(cursor: \"<cursor from next>\")",

    'internal' => "error: internal\nThe tool failed unexpectedly; the exception was reported to the application's exception handler, which logs it by default.",

    'unreadable_time' => "error: unreadable_time\n`:argument` value :value is not a time this tool reads.\nargument: :argument\naccepted: epoch seconds up to :maximum, ISO 8601 with Z or an offset, a local date or date-time (YYYY-MM-DD HH:MM:SS), a relative time such as -1d or -90 minutes (units s, m, h, d, w), or now\nexample: :tool(:argument: \"-1d\")",

    'empty_window' => "error: empty_window\n`since` (:since) is not before `until` (:until).\nargument: since\naccepted: a `since` earlier than `until`\nexample: :tool(since: \"-1d\", until: \"now\")",

    'not_allowed' => "error: not_allowed\n:message\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type",

    'sql_denied' => [
        'function' => 'function `:name` is not allowed.',
        'table' => 'table `:name` is not readable.',
        'action' => 'action `:name` is not allowed.',
        'second_statement' => 'one statement only.',
        'no_columns' => 'the statement returns no rows.',
        'too_long' => 'the SQL is longer than :bytes bytes.',
        'nul' => 'the SQL contains a NUL byte.',
    ],

    'sql_hint_function' => 'hint: `describe` lists the permitted functions.',

    'sql_hint_table' => 'hint: the readable objects are the twelve record views, records, users, drift, meta, json_each and json_tree. `describe` lists the readable tables.',

    'sql_hint_forms' => 'hint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.',

    'invalid_sql' => "error: invalid_sql\n:message\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: `describe` lists the tables, views and columns.",

    'aborted' => "error: aborted\nThe query process ended unexpectedly.",

    'row_too_large' => "error: row_too_large\nThe first row is larger than the :bytes-byte answer budget.\nhint: select fewer columns or cut text with substr().",

    'deadline' => "error: deadline\nThe statement did not finish within :seconds seconds.\nhint: filter on `started_at` or an indexed column.",

    'memory' => "error: memory\nThe statement needed more than :mebibytes MiB of working memory.\nhint: aggregate less, add filters, or select fewer columns; sorting and grouping large sets needs memory.",

    'aborted_stderr' => 'stderr: :stderr',

    'unavailable' => "error: unavailable\nThe SQL tool is unavailable: :reason. Every other Firewatch tool works.",

    'sql_unavailable' => [
        'proc_open_missing' => '`proc_open` is disabled or missing',
        'sqlite3_missing' => 'the sqlite3 extension is not loaded',
        'php_binary' => 'PHP_BINARY is not an executable file',
        'sqlite_too_old' => 'SQLite is older than 3.38.0',
        'spawn_failed' => 'the query process could not be started',
        'authorizer' => 'SQLite cannot install an authorizer',
        'heap_limit' => 'SQLite did not accept a heap limit',
    ],

    'failed' => "error: failed\nThe SQL tool failed unexpectedly; the failure was reported to the application's exception handler. Run `php artisan firewatch:doctor`.",

    'rank_type_argument' => 'The type to rank, required unless group: request, command, job-attempt, scheduled-task, query, exception, cache-event, mail, notification, outgoing-request or queued-job.',

    'rank_by_argument' => 'The measure: p95_duration (default; occurrences for exceptions), p50_duration, max_duration, total_duration, occurrences, p95_memory, max_memory, last_seen or queries.',

    'rank_deploy_argument' => 'An exact deploy string: only its records count.',

    'rank_limit_argument' => 'The most groups to list, 1 to 100. Default 20.',

    'rank_summary' => 'Ranked :count :type group by :by, worst first.|Ranked :count :type groups by :by, worst first.',

    'rank_fallback' => 'No group has enough records for :statistic (needed :needed); ordered by max.',

    'rank_untimed' => ':count record without duration is counted in occurrences and left out of the duration statistics.|:count records without duration are counted in occurrences and left out of the duration statistics.',

    'rank_group_argument' => 'One group id (32 hex): one row per deploy in first-seen order. Excludes matching and deploy; by queries is refused.',

    'rank_matching_argument' => 'A case-insensitive substring of the group label, 1 to 200 characters. Excludes group.',

    'rank_cursor_argument' => 'The cursor of a cut answer, from its truncated entry, with the same arguments. Not with group.',

    'rank_cursor_how' => 'Call rank again with this cursor to see the rest: :call',

    'rank_breakdown_summary' => 'Broke group :group down into :count deploy, in the order it was first seen.|Broke group :group down into :count deploys, in the order they were first seen.',

    'rank_job_group' => 'Group :group is held by job-attempt and queued-job; showing job-attempt. Pass type: queued-job for the dispatches.',

    'rank_next_group' => 'Break the worst group down by deploy to see whether it changed.',

    'rank_no_deploy' => 'no deploy identity',

    'rank_truncated_how' => 'Pass a larger `limit`, up to 100, or narrow the window.',

    'rank_no_route' => '(no route matched)',

    'compare_type_argument' => 'A type with groups, as rank takes it. Required unless group.',

    'compare_group_argument' => 'One group id (32 hex). Excludes limit.',

    'compare_split_at_argument' => 'Where after begins, in the forms of since; a record at it is after. Strictly inside the window.',

    'compare_deploy_before_argument' => 'The deploy of the before side, matched exactly. Needs deploy_after.',

    'compare_deploy_after_argument' => 'The deploy of the after side, matched exactly, not deploy_before. Needs deploy_before.',

    'compare_by_argument' => 'p95_duration (default; occurrences for exceptions), p50_duration, max_duration, total_duration, occurrences, p95_memory, p50_memory, max_memory or queries.',

    'compare_limit_argument' => 'The most groups to list, 1 to 100. Default 20.',

    'compare_since_argument' => 'Start of the window, included, in the forms every tool takes. Absent: the start of what the store covers for the type.',

    'compare_until_argument' => 'End of the window, excluded, in the same forms. Absent: the store clock, now.',

    'compare_summary' => 'Compared :groups :type group by :by before and after the split: :changes.|Compared :groups :type groups by :by before and after the split: :changes.',

    'compare_pair_summary' => 'Compared :groups :type group by :by from deploy :before to deploy :after: :changes.|Compared :groups :type groups by :by from deploy :before to deploy :after: :changes.',

    'compare_empty_deploy_summary' => 'Not evaluated (empty_side): deploy :deploy, the :side side, holds no :type records in the window, which is not "no regression".',

    'compare_empty_deploys_summary' => 'Not evaluated (empty_side): neither deploy :before, the before side, nor deploy :after, the after side, holds :type records in the window, which is not "no regression".',

    'compare_empty_side_summary' => 'Not evaluated (empty_side): the :side side holds no :type records, which is not "no regression".',

    'compare_outside_coverage_summary' => 'Not evaluated (outside_coverage): the :side side lies before the history the store holds for :type.',

    'compare_not_evaluated_note' => 'Nothing was compared, which says nothing about whether anything changed: exercise the application again, or move split_at or since.',

    'compare_pair_not_evaluated_note' => 'Nothing was compared, which says nothing about whether anything changed: pass deploys the window holds, or widen it with since or until.',

    'compare_deploy_pair_note' => 'A deploy pair cannot separate an uncommitted edit; a time split can.',

    'compare_earlier_note' => ':count :type record started before what the store covers for :type, so it is on neither side.|:count :type records started before what the store covers for :type, so they are on neither side.',

    'compare_earlier_more_note' => ':count or more :type records started before what the store covers for :type, so they are on neither side.',

    'compare_earlier_deploy_note' => ':count :type record of deploy :deploy started before what the store covers for :type, so it is on neither side.|:count :type records of deploy :deploy started before what the store covers for :type, so they are on neither side.',

    'compare_earlier_deploy_more_note' => ':count or more :type records of deploy :deploy started before what the store covers for :type, so they are on neither side.',

    'compare_move_since_note' => 'The before side reaches back more than an hour before the split, so it may hold earlier changes too: on a later round, pass the previous split as `since`.',

    'compare_truncated_how' => 'Pass a larger `limit`, up to 100, one `group`, or a narrower window.',

    'compare_deploys_truncated_how' => 'These are the deploys first seen: narrow the window with `since` or `until`.',

    'compare_next_occurrences' => 'List the records of the first group listed.',

    'compare_next_occurrences_deploy' => 'List the records of the first group listed under deploy :deploy.',

    'compare_next_rank' => 'Break the first group listed down by deploy.',

    'trend_type_argument' => 'A type with groups. Required unless group.',

    'trend_group_argument' => 'One group id (32 hex).',

    'trend_by_argument' => 'The measure, default occurrences; no percentiles.',

    'trend_buckets_argument' => '2 to 60. Default 12.',

    'trend_since_argument' => 'Start, included; the forms every tool takes.',

    'trend_until_argument' => 'End; the same forms. Derived: included.',

    'trend_deploy_argument' => 'An exact deploy string.',

    'trend_summary' => ':type :by :direction over :buckets buckets; the peak is bucket :peak.',

    'trend_summary_no_peak' => ':type :by :direction over :buckets buckets, with no peak.',

    'trend_sample_too_small_summary' => 'Not evaluated (sample_too_small): :have of :buckets buckets carry :by, and a direction needs :needed.',

    'trend_outside_coverage_summary' => 'Not evaluated (outside_coverage): the window ends at or before the history the store holds for :type.',

    'trend_partial_note' => ':count bucket starts before what the store covers for :type, so it is partial and left out of the direction and peak.|:count buckets start before what the store covers for :type, so they are partial and left out of the direction and peak.',

    'trend_width_zero_note' => 'Every selected record started at one instant, so the window has a width of 0 and one bucket.',

    'trend_idle_note' => 'No records for :duration since the last one.',

    'trend_next_occurrences' => 'List the records of the peak bucket.',

    'query_sql_argument' => 'One SELECT, WITH ... SELECT, VALUES or EXPLAIN statement, up to 16,384 bytes.',

    'query_limit_argument' => 'The most rows to return, 1 to 500. Default 50. A LIMIT of your own applies inside it.',

    'query_window_reason' => 'The statement owns its bounds.',

    'query_summary' => 'Returned :rows (:columns).',

    'query_summary_partial' => 'Returned :rows (:columns); the statement did not finish.',

    'query_summary_more' => 'Returned :rows (:columns). Showing the first :shown of more.',

    'query_rows_count' => ':count row|:count rows',

    'query_columns_count' => ':count column|:count columns',

    'query_no_rows' => 'The statement returned no rows.',

    'query_raw_values' => 'Values are raw: times are epoch seconds, durations microseconds.',

    'query_limit_how' => 'Add LIMIT/OFFSET or a keyset condition on id, or raise `limit` up to 500.',

    'query_budget_how' => 'Select fewer columns, cut text with substr(), or add LIMIT.',

    'query_partial_how' => 'Filter on started_at or an indexed column.',

    'query_partial_note' => 'The statement did not finish; these rows are not in any particular order.',

    'query_cap_how' => 'Read the rest with substr(column, 2001, 2000).',

    'query_next_more' => 'The same statement with the most rows an answer holds.',

    'describe_type_argument' => 'A record type or user.',

    'describe_window_reason' => 'the schema and the store facts are not bounded by time',

    'describe_summary' => 'The store holds :records record of :types types; the SQL tool is :sql.|The store holds :records records of :types types; the SQL tool is :sql.',

    'describe_summary_empty' => 'The store holds no records yet; the schema below is what the SQL tool reads.',

    'describe_summary_absent' => 'No store exists yet; the schema below is the shipped catalogue.',

    'describe_summary_unusable' => 'The store cannot be read; the schema below is the shipped catalogue.',

    'describe_sql_available' => 'available',

    'describe_sql_unavailable' => 'unavailable',

    'describe_type_summary' => ':object has :columns columns and holds :records record.|:object has :columns columns and holds :records records.',

    'describe_type_summary_empty' => ':object has :columns columns and holds no records yet, so no example values.',

    'describe_json_access' => "JSON columns hold JSON text: read a field with data ->> '\$.field' and expand a list or object with json_each(column). data holds every field, including ones the contract does not know.",

    'describe_join_keys' => 'Join children to their execution on execution_id, queued work on job_id, a causal chain on trace_id and a group on group_hash; all four are indexed.',

    'describe_deploys_how' => 'Run query with SELECT deploy, max(started_at) FROM records GROUP BY deploy for every deploy.',

    'describe_bytes_note' => 'file_bytes is the main file only; live_bytes is the pages in use, the write-ahead log not counted.',

    'describe_unknown_types' => 'Records of types outside the contract are stored as sent: :types.',

    'describe_next_type' => 'Columns, recent values and example statements for :type.',

    'describe_next_rank' => 'Rank the groups of this type, worst first.',

    'describe_next_query' => 'Run the first example statement.',

    'fingerprint_arguments' => [
        'type' => 'A record type; not exception, log or user.',
        'methods' => 'request: all its methods, such as GET and HEAD.',
        'path' => 'request: the full route path.',
        'domain' => 'request, optional.',
        'name' => 'command, job-attempt, queued-job, scheduled-task.',
        'cron' => 'scheduled-task.',
        'timezone' => 'scheduled-task, optional.',
        'repeat_seconds' => 'scheduled-task, optional.',
        'connection' => 'query: the connection name.',
        'sql' => 'query: the SQL as run, with ? placeholders.',
        'driver' => 'query, optional, such as mysql.',
        'store' => 'cache-event.',
        'key' => 'cache-event.',
        'host' => 'outgoing-request.',
        'class' => 'mail, notification.',
    ],

    'no_recipe' => "error: invalid_argument\n`type` cannot be :type: :reason\nargument: type\naccepted: :accepted\nexample: :example",

    'no_recipe_reasons' => [
        'exception' => 'an exception is grouped by where it was thrown, which source does not give. Use `rank(type: "exception", matching: "<class>")`.',
        'log' => 'logs have no group.',
        'user' => 'users have no group.',
    ],

    'fingerprint_window_reason' => 'a group id is looked up in the whole store',

    'fingerprint_check' => [
        'agrees' => 'agrees',
        'disagrees' => 'disagrees',
        'not_evaluated' => 'was not evaluated',
    ],

    'fingerprint_summary_held' => ':group is held by :records record of :types; the recipe check :check.|:group is held by :records records of :types; the recipe check :check.',

    'fingerprint_summary_missed' => [
        'agrees' => 'No :types record holds :group and the recipe check agrees: it has not run since coverage starts.',
        'disagrees' => 'No :types record holds :group, but the recipe check disagrees: do not conclude it has not run.',
        'not_evaluated' => 'No :types record holds :group, and the recipe check was not evaluated: do not conclude it has not run.',
    ],

    'fingerprint_driver_note' => 'The store holds this query under the other reading, group :group, so the connection\'s driver is likely not the one given.',
    'fingerprint_head_note' => 'HEAD was added beside GET, as Laravel registers every GET route.',

    'fingerprint_timezone_note' => 'No timezone was given, so the schedule timezone :timezone is assumed.',

    'fingerprint_next_occurrences' => "Read this group's records.",

    'fingerprint_next_rank' => "Rank this group's records by deploy.",

    'fingerprint_assumption_normalised' => 'the driver is one Nightwatch normalises: mariadb, mysql, pgsql, sqlite, sqlsrv or singlestore',

    'fingerprint_assumption_written' => 'the driver is any other, so the SQL is hashed as written',

    'objects' => [
        'requests' => 'One row per HTTP request the application served.',
        'commands' => 'One row per Artisan command that ran.',
        'job_attempts' => 'One row per attempt to run a queued job.',
        'scheduled_tasks' => 'One row per scheduled task run, including skipped ones.',
        'queries' => 'One row per database query an execution ran.',
        'exceptions' => 'One row per exception reported, handled or not.',
        'logs' => 'One row per log line written.',
        'cache_events' => 'One row per cache hit, miss, write or delete.',
        'mail' => 'One row per mail sent.',
        'notifications' => 'One row per notification sent on a channel.',
        'outgoing_requests' => 'One row per HTTP request the application made.',
        'queued_jobs' => 'One row per job dispatched to a queue.',
        'records' => 'Every record of every type: the twelve views above read from it, and fields beyond the common columns sit in data.',
        'users' => 'The user directory: one row per signed-in person the sensors recorded.',
        'drift' => 'Where Nightwatch\'s output departed from the contract, counted by kind, type, version and detail.',
        'meta' => 'The store\'s markers: when it was created, pruned and cleared, and the Nightwatch release last seen.',
    ],

    'column_meanings' => [
        'id' => 'The store\'s own row number: it pages and prunes, and is never an identity or a link.',
        'v' => 'The version of the record type\'s wire shape.',
        'started_at' => 'When it started.',
        'ended_at' => 'When it ended: started_at plus duration, derived.',
        'duration' => 'How long it took.',
        'group_hash' => 'Nightwatch\'s group id, 32 hex characters: the same group Nightwatch shows.',
        'trace_id' => 'The causal chain it belongs to, shared by a request and the jobs it caused.',
        'execution_id' => 'The execution it belongs to; children join their execution on it.',
        'source' => 'The kind of execution: request, command, job or schedule.',
        'execution_source' => 'The kind of execution it ran inside: request, command, job or schedule.',
        'execution_stage' => 'The stage of its execution it was made in.',
        'job_id' => 'The queued job\'s id: a dispatch and its attempts join on it.',
        'user_id' => 'The signed-in user, an id into users; null when nobody was signed in.',
        'deploy' => 'The deploy string the application reported.',
        'server' => 'The server name the application reported.',
        'data' => 'Every field of the record as JSON, including fields the contract does not know.',
        'queue' => 'The queue name.',
        'context' => 'The context the application attached, as JSON.',
        'method' => 'The HTTP method.',
        'url' => 'The full URL, query string included.',
        'status_code' => 'The HTTP status code of the response.',
        'request_size' => 'The size of the request body.',
        'response_size' => 'The size of the response body.',
        'route_name' => 'The route\'s name; empty for an unnamed route.',
        'route_methods' => 'The methods the route answers, as a JSON list.',
        'route_domain' => 'The domain the route is bound to; empty when none.',
        'route_path' => 'The route\'s path pattern, as declared.',
        'route_action' => 'The controller action or closure that handled the request.',
        'ip' => 'The client IP address.',
        'headers' => 'The request headers, as a JSON object.',
        'payload' => 'The request payload as Nightwatch captured it, as JSON.',
        'bootstrap' => 'Time spent booting the application before routing or the command ran.',
        'before_middleware' => 'Time spent in middleware before the action.',
        'action' => 'Time spent in the controller action or command handler.',
        'render' => 'Time spent rendering the response.',
        'after_middleware' => 'Time spent in middleware after the action.',
        'sending' => 'Time spent sending the response.',
        'terminating' => 'Time spent in terminating callbacks.',
        'peak_memory_usage' => 'The process\'s peak memory when it ended, not the execution\'s own use.',
        'exceptions' => 'How many exceptions the execution counted; the rows are in exceptions.',
        'logs' => 'How many log lines the execution counted; the rows are in logs.',
        'queries' => 'How many queries the execution counted; the rows are in queries.',
        'jobs_queued' => 'How many jobs the execution queued; the rows are in queued_jobs.',
        'mail' => 'How many mails the execution sent; the rows are in mail.',
        'notifications' => 'How many notifications the execution sent; the rows are in notifications.',
        'outgoing_requests' => 'How many outgoing requests the execution made; the rows are in outgoing_requests.',
        'cache_events' => 'How many cache events the execution counted; the rows are in cache_events.',
        'lazy_loads' => 'Never populated by the sensors: 0 means not measured, not none.',
        'files_read' => 'Never populated by the sensors: 0 means not measured, not none.',
        'files_written' => 'Never populated by the sensors: 0 means not measured, not none.',
        'hydrated_models' => 'Never populated by the sensors: 0 means not measured, not none.',
        'exception_preview' => 'The start of the first exception\'s message, kept on the execution.',
        'command' => 'The command line as typed.',
        'exit_code' => 'The process exit code; 0 is success.',
        'attempt' => 'The attempt number of the job, from 1.',
        'cron' => 'The cron expression of the schedule.',
        'timezone' => 'The timezone the schedule evaluates in.',
        'repeat_seconds' => 'Seconds between runs of a sub-minute schedule; 0 otherwise.',
        'without_overlapping' => '1 when the task is declared without overlapping.',
        'on_one_server' => '1 when the task is declared to run on one server.',
        'run_in_background' => '1 when the task is declared to run in the background.',
        'even_in_maintenance_mode' => '1 when the task is declared to run in maintenance mode.',
        'sql' => 'The SQL as run, with its placeholders.',
        'connection_type' => 'Whether the query ran on the read or the write connection; empty when unknown.',
        'bindings' => 'The bound values as a JSON list; null when none could be paired with the query.',
        'code' => 'The exception code.',
        'trace' => 'The stack trace, as JSON.',
        'handled' => '1 when the exception was caught, 0 when it was not.',
        'php_version' => 'The PHP version that raised it.',
        'laravel_version' => 'The Laravel version that raised it.',
        'level' => 'The log level.',
        'extra' => 'The extra data the logger added, as JSON.',
        'store' => 'The cache store\'s name.',
        'event' => 'The kind of cache event.',
        'ttl' => 'Seconds the entry was written to live; 0 for other events.',
        'mailer' => 'The mailer that sent it.',
        'subject' => 'The mail\'s subject.',
        'to' => 'How many recipients the mail had in To.',
        'cc' => 'How many recipients the mail had in Cc.',
        'bcc' => 'How many recipients the mail had in Bcc.',
        'attachments' => 'How many attachments the mail had.',
        'failed' => 'Never populated by the sensors: 0 means not measured, not none.',
        'channel' => 'The channel the notification went out on.',
        'host' => 'The host that was called.',
        'username' => 'The person\'s username.',
        'kind' => 'Which departure from the contract it was.',
        'detail' => 'The field or shape it concerned.',
        'count' => 'How many times it was counted.',
        'value' => 'The marker\'s value, as text.',
    ],

    'column_meanings_in' => [
        'records' => [
            'type' => 'The record type, as sent: request, command, job-attempt and so on.',
            'source' => 'For an execution its own kind, for a child the kind of execution it ran inside.',
        ],
        'commands' => ['class' => 'The command class.', 'name' => 'The command name.'],
        'job_attempts' => ['name' => 'The job class.', 'connection' => 'The queue connection.', 'status' => 'The outcome of the attempt.', 'execution_id' => 'The attempt\'s own id: a fresh one for every attempt.'],
        'scheduled_tasks' => ['name' => 'The task\'s command or description.', 'status' => 'The outcome of the run.'],
        'queued_jobs' => ['name' => 'The job class.', 'connection' => 'The queue connection.'],
        'queries' => ['file' => 'The file that issued the query.', 'line' => 'The line that issued the query.', 'connection' => 'The database connection name.'],
        'exceptions' => ['class' => 'The exception class.', 'file' => 'The file the exception was thrown in.', 'line' => 'The line it was thrown on.', 'message' => 'The exception message.'],
        'logs' => ['message' => 'The log message.', 'context' => 'The context array passed with the message, as JSON.'],
        'cache_events' => ['key' => 'The cache key.'],
        'mail' => ['class' => 'The mailable class.'],
        'notifications' => ['class' => 'The notification class.'],
        'outgoing_requests' => ['url' => 'The URL that was called.', 'method' => 'The HTTP method of the call.', 'status_code' => 'The status code of the response received.'],
        'users' => ['id' => 'The user id that user_id holds.', 'name' => 'The person\'s name.', 'first_seen' => 'When the person was first recorded.', 'last_seen' => 'When the person was last recorded.'],
        'drift' => ['type' => 'The record type it was seen on; empty when not tied to one.', 'v' => 'The record version it was seen on, as text.', 'first_seen' => 'When it was first counted.', 'last_seen' => 'When it was last counted.'],
        'meta' => ['key' => 'The marker\'s name.'],
    ],

    'describe_recipes' => [
        'request' => 'route_methods, route_domain and route_path; the label is the methods with the path.',
        'command' => 'name.',
        'job-attempt' => 'name, shared with its dispatch.',
        'scheduled-task' => 'name, cron and timezone.',
        'query' => 'the SQL with its values normalised, and the connection.',
        'exception' => 'class, code, file and line.',
        'cache-event' => 'store and key.',
        'outgoing-request' => 'host.',
        'mail' => 'class.',
        'notification' => 'class.',
        'queued-job' => 'name, shared with its attempts.',
    ],

    'describe_units' => [
        'epoch_seconds' => 'Unix seconds in UTC, a REAL with microsecond resolution.',
        'microseconds' => 'Integer microseconds: every duration and stage.',
        'bytes' => 'Integer bytes: memory and sizes.',
        'seconds' => 'Whole seconds: a cache lifetime or a schedule interval.',
    ],

    'describe_examples' => [
        'slowest_routes' => 'The slowest routes of the last hour.',
        'exceptions_by_class' => 'Exceptions by class, most frequent first.',
        'queries_per_execution' => 'The executions that ran the most queries.',
        'execution_timeline' => 'The latest request\'s records in order.',
        'header_names' => 'The request header names, through json_each.',
        'request_status' => 'Requests by status code.',
        'request_slowest' => 'The ten slowest requests.',
        'request_stages' => 'Where the time goes, by route and stage.',
        'command_exit_codes' => 'Runs by command and exit code.',
        'command_slowest' => 'The ten slowest commands.',
        'command_memory' => 'Peak memory by command.',
        'job_attempt_outcomes' => 'Attempts by job and outcome.',
        'job_attempt_wait' => 'The longest waits between dispatch and attempt.',
        'job_attempt_slowest' => 'The ten slowest attempts.',
        'scheduled_task_status' => 'Runs by task and outcome.',
        'scheduled_task_slowest' => 'The ten slowest runs that ran.',
        'scheduled_task_settings' => 'Each task with its schedule and flags.',
        'query_slowest' => 'The ten slowest queries, with where they were issued.',
        'query_repeated' => 'The query groups that took the most time in total.',
        'query_connections' => 'Queries by connection and kind.',
        'exception_places' => 'The latest exceptions with where they were thrown.',
        'exception_handled' => 'Exceptions handled against not handled.',
        'exception_frames' => 'Exceptions by stack depth, through json_array_length.',
        'log_levels' => 'Log lines by level.',
        'log_recent' => 'The latest log lines.',
        'log_context' => 'The shape of each log line\'s context, through json_type.',
        'cache_event_kinds' => 'Cache events by store and kind.',
        'cache_event_keys' => 'The keys that missed most.',
        'cache_event_slowest' => 'The ten slowest cache events.',
        'mail_by_class' => 'Mail sent by class.',
        'mail_slowest' => 'The ten slowest mails.',
        'mail_recent' => 'The latest mail.',
        'notification_by_channel' => 'Notifications by channel and class.',
        'notification_slowest' => 'The ten slowest notifications.',
        'notification_failed' => 'Notifications by failed flag.',
        'outgoing_by_host' => 'Outgoing requests by host, with the average time.',
        'outgoing_statuses' => 'Outgoing requests by host, method and status.',
        'outgoing_slowest' => 'The ten slowest outgoing requests.',
        'queued_job_dispatches' => 'Dispatches by job and queue.',
        'queued_job_attempts' => 'Each dispatch with the attempts that ran it, through a join on job_id.',
        'queued_job_connections' => 'Dispatches by connection and queue.',
        'user_directory' => 'The people seen most recently.',
        'user_requests' => 'Requests by person, through a join on user_id.',
        'user_span' => 'How long each person has been seen.',
    ],

    'execution_id_argument' => 'The execution id to open (a request\'s trace id is its execution id). Omit for the latest finished execution. Excludes type.',

    'execution_type_argument' => 'request, command, job-attempt or scheduled-task: the latest finished execution of that kind. Not with execution_id.',

    'execution_limit_argument' => 'The most timeline entries, 1 to 100. Default 50. Counted after repeated identical queries collapse.',

    'execution_window_reason' => 'one execution, found by its id or as the latest one that finished',

    'execution_any_type' => 'type: any execution type',

    'budget_line' => 'Budget: :state (:details)',

    'budget_cell' => ':state (:details)',

    'budget_ignored' => '; ignored_entries: :count',

    'execution_summary' => 'Showed the :type :id: outcome :outcome.',

    'execution_not_found_trace' => "error: not_found\nNo execution `:id` exists in the store, but that value is a trace id; `trace(trace_id: \":id\")` follows it.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'execution_next_rank' => 'Rank the group of this execution to see how it compares with the others of its kind.',
    'execution_next_occurrences' => 'List the queries this execution ran, to see which was slowest.',
    'execution_next_trace' => 'Follow the trace of this execution: the executions it belongs to and the jobs it dispatched.',

    'execution_timeline_how' => 'The timeline lists at most `limit` entries, up to 100; `occurrences(execution_id: ":id")` lists every record of the execution, with a cursor for the rest.',

    'execution_exceptions_how' => 'The five earliest exceptions are shown, and the accounting row counts them all; `occurrences(execution_id: ":id", type: "exception")` lists every one.',

    'execution_frames_limit_reached' => 'Nightwatch stores source lines for the first 10 application frames of an exception only; this one had more.',

    'execution_frames_limit_not_reached' => 'Nightwatch\'s limit of 10 frames with source lines was not reached: the lines were not available when the exception was captured, or were dropped to fit the record.',

    'trace_id_argument' => 'A trace id: its executions in start order and the queued jobs they dispatched or ran. Give this or job_id, not both.',

    'trace_job_id_argument' => 'A job id: the lineage of that job and the executions of the trace it was dispatched in. Give this or trace_id, not both.',

    'trace_limit_argument' => 'The most executions to list, 1 to 100. Default 50. It does not cap the jobs, whose list is cut only to fit the answer.',

    'trace_window_reason' => 'a trace is read whole, whenever it ran',

    'trace_summary' => 'Trace :id: :executions, :jobs.',

    'trace_job_summary' => 'Job :id: :executions in its trace, :jobs.',

    'trace_executions_count' => ':count execution|:count executions',

    'trace_jobs_count' => ':count queued job|:count queued jobs',

    'trace_executions_how' => 'Pass a larger `limit`, up to 100, to see more of the executions.',

    'trace_partial_no_attempts' => 'Partial lineage: dispatch without attempts.',

    'trace_partial_no_dispatch' => 'Partial lineage: attempts without dispatch.',

    'trace_no_execution' => 'No execution of trace `:id` is in the store. Records that carry it: :counts.',

    'trace_next_failed' => 'This execution failed: open it for its exceptions and children.',

    'trace_next_slowest' => 'The slowest execution of the trace: open it to see what took the time.',

    'trace_next_occurrences' => 'List the records that carry this id, since the execution they belong to is not in the store.',

    'actor_who_argument' => 'A user id, a username or a name, 1 to 255 characters once trimmed. Tried in that order, then as a part of a name or username.',

    'actor_limit_argument' => 'The most executions to list, 1 to 100. Default 20.',

    'actor_summary' => ':person: :attributed of :total executions in the window attributed (:direct direct, :dispatch dispatch, :inside inside); :unattributable cannot be attributed.',

    'actor_summary_without_person' => ':attributed of :total executions in the window attributed (:direct direct, :dispatch dispatch, :inside inside); :unattributable cannot be attributed.',

    'actor_nothing_attributed_summary' => 'Nothing in this window is attributed to :person.',

    'actor_nothing_attributed_summary_without_person' => 'Nothing in this window is attributed to the person identified.',

    'actor_nothing_attributed' => 'Nothing in this window is attributed to :person, among the :population executions that started in it.',

    'actor_no_executions' => 'No request, command, job attempt or scheduled task started in this window, so nothing can be attributed; the store holds :population records: widen the window or move it.',

    'actor_executions_how' => 'The newest attributed execution is listed; narrow `since` and `until` to see the others.|The :listed newest attributed executions are listed; narrow `since` and `until` to see the others.',

    'actor_commands_note' => ':commands and :tasks ran in this window and carry no actor; :inside shown as inside work; what this person set off through the others is not shown.',

    'actor_commands_count' => ':count command|:count commands',

    'actor_tasks_count' => ':count scheduled task|:count scheduled tasks',

    'actor_inside_count' => ':count of them is|:count of them are',

    'actor_no_commands_note' => 'No commands or scheduled tasks ran in this window, so nothing was lost to them.',

    'actor_no_actor_note' => ':count job attempt had no recorded user and no traceable dispatch; it may belong to this person.|:count job attempts had no recorded user and no traceable dispatch; some may belong to this person.',

    'actor_guest_note' => ':count request carries no recorded user (a guest, a user of a non-default guard, or one Nightwatch could not resolve) and cannot be attributed.|:count requests carry no recorded user (a guest, a user of a non-default guard, or one Nightwatch could not resolve) and cannot be attributed.',

    'actor_identity_note' => 'User ids are keys recorded as sent, not qualified by guard or model; a reseeded database can give an id to another person. Only the latest name and username are searchable. `first_seen_at` is when the directory row was created, not when the person first acted. History before the coverage start is gone.',

    'actor_next_execution' => 'Open the newest execution attributed to this person, in full.',

    'actor_next_group' => 'List the records of the group of that execution.',

    'actor_next_user' => 'List the records that carry this user id; dispatch and inside links are not followed there.',

    'actor_from_records_note' => 'No directory row exists for this id (users are kept while recently seen).',

    'actor_ambiguous_summary' => '`:who` matches :count people at the :stage stage; repeat with an id.',

    'actor_ambiguous_summary_without_who' => ':count people were found at the :stage stage; repeat with an id.',

    'actor_ambiguous_note' => 'Several people match; repeat with an id.',

    'actor_candidates_how' => 'The :listed candidates seen most recently are listed; repeat with an id, or with more of the name or username.',

    'actor_unknown' => 'Nobody was identified by `:who`, among the :population people in the user directory.',

    'actor_unknown_summary' => 'Nobody was identified by `:who`.',

    'actor_unknown_note' => 'An actor exists only once recorded acting; the directory holds users seen within retention.',

    'detect_shape_argument' => 'The shape to run: n-plus-one, database-bound, failing-routes, failing-jobs, queue-latency, failing-tasks, exception-clusters, error-logs, failing-http, cache or memory. Absent: every shape that ships.',
    'detect_threshold_argument' => 'Overrides the shape\'s default, named in the tool description. Whole numbers, except percent and megabytes. An answer states the unit, range and default; out of range is refused. Needs `shape`.',
    'detect_group_argument' => 'One group id (32 hex) restricting the shape; for n-plus-one an execution\'s group, not a query group; refused for error-logs. A group holding no records is an empty answer. Needs `shape`.',
    'detect_limit_argument' => 'The most findings to list, 1 to 100. Default 20.',
    'detect_input' => [
        'n-plus-one' => 'executions',
        'database-bound' => 'requests',
        'failing-routes' => 'requests',
        'failing-jobs' => 'job attempts',
        'queue-latency' => 'dispatches',
        'failing-tasks' => 'scheduled tasks',
        'exception-clusters' => 'executions',
        'error-logs' => 'log records',
        'failing-http' => 'outgoing requests',
        'cache' => 'cache events',
        'memory' => 'executions',
    ],
    'detect_caveat_reads' => 'Reads are recognised by the first keyword; a WITH statement that writes counts as a read.',
    'detect_caveat_incomplete' => '{1} :count examined execution has fewer captured than counted queries; run counts and shares are lower bounds.|[2,*] :count examined executions have fewer captured than counted queries; run counts and shares are lower bounds.',
    'detect_caveat_memory' => 'A long-lived process inherits memory it already held; a peak is not attributable to code.',
    'detect_caveat_wait' => 'The wire carries no availability time: a delayed or backed-off job counts as waiting; round-number waits are usually delays.',
    'detect_caveat_pending' => 'No attempt is recorded: no worker has run, or one is running; the store cannot tell.',
    'detect_caveat_skipped' => 'Skipped runs are often intended.',
    'detect_caveat_not_fired' => 'A task that did not fire leaves no record.',
    'detect_caveat_log_capture' => 'Log records exist only while log capture is on.',
    'detect_caveat_unanswered' => 'Clean means only that no captured call returned an error status; timeouts and connection failures leave no record.',
    'detect_caveat_cache_keys' => 'Keys that embed ids fragment into one-off groups; read the per-store activity instead.',
    'detect_findings_summary' => ':detector: :total findings over :examined :input.',
    'detect_clean_summary' => ':detector: clean over :examined :input.',
    'detect_not_evaluated_summary' => ':detector: not evaluated (:reason).',
    'detect_all_summary' => 'Problem shapes: :parts.',
    'detect_all_clean_summary' => 'No findings: the shape is clean over what was captured.|No findings: all :count shapes are clean over what was captured.',
    'detect_part_findings' => 'findings in :shapes',
    'detect_part_not_evaluated' => 'not evaluated (:reason): :shapes',
    'detect_part_clean' => 'clean: :shapes',
    'detect_findings_how' => 'List more of the findings with a larger `limit`, up to 100, or narrow the call with `group`, `since` and `until`.',
    'detect_findings_how_ungrouped' => 'List more of the findings with a larger `limit`, up to 100, or narrow the call with `since` and `until`.',
    'detect_next_execution' => 'Open the latest execution that failed.',
    'detect_next_occurrences' => 'List the records of the group.',
    'detect_next_rank' => 'See how the group compares with the others, and whether it changed with a deploy.',
    'detect_next_log_lines' => 'List the lines at this level and worse that hold the fragment of the shape.',
    'detect_next_log_level' => 'List the lines at this level and worse; the shape has no literal text to match.',
    'detect_next_shape' => 'List the findings of this shape with their evidence.',

    'accounting_incomplete' => 'Incomplete: :captured of :counted counted :noun were captured.',

    'accounting_more' => 'More records captured than counted: :captured :noun captured, :counted counted.',

    'accounting_outside_coverage' => 'Children outside coverage: history for :noun before :from was removed.',

    'accounting_nouns' => [
        'queries' => 'queries',
        'exceptions' => 'exceptions',
        'logs' => 'logs',
        'cache_events' => 'cache events',
        'mail' => 'mail',
        'notifications' => 'notifications',
        'outgoing_requests' => 'outgoing requests',
        'jobs_queued' => 'jobs queued',
    ],
    'occurrences_group_argument' => 'A group id from `rank` or from a row: only its records. A job group lists its dispatches and attempts together.',

    'occurrences_type_argument' => 'A record type: request, command, job-attempt, scheduled-task, query, exception, log, cache-event, mail, notification, outgoing-request or queued-job.',

    'occurrences_execution_id_argument' => 'An execution id: the execution\'s own record and everything recorded inside it.',

    'occurrences_trace_id_argument' => 'A trace id: the records of a request and of the jobs and commands it caused.',

    'occurrences_job_id_argument' => 'A job id: its dispatch and every attempt.',

    'occurrences_user_id_argument' => 'A user id: the records that carry that user, as recorded.',

    'occurrences_order_argument' => 'recent (default), slowest, memory or queries. Records without the measure come last; memory and queries need an execution type.',

    'occurrences_method_argument' => 'An HTTP method, any case. Only for request and outgoing-request.',

    'occurrences_status_argument' => 'A status code: 500, a class such as 5xx, or a range such as 400-499. Only for request and outgoing-request.',

    'occurrences_outcome_argument' => 'processed, failed or released for job-attempt; processed, failed or skipped for scheduled-task.',

    'occurrences_level_argument' => 'A log level: that level and every worse one. Only for log.',

    'occurrences_slower_than_ms_argument' => 'Only records strictly slower than this many milliseconds. Records without a duration are left out.',

    'occurrences_at_or_above_argument' => 'median or p95: only records at or above that duration of the selection. Withheld with a note when the selection is too small.',

    'occurrences_matching_argument' => 'A plain substring, 1 to 200 characters, ignoring ASCII case, looked for in the type\'s own fields. Needs a type.',

    'occurrences_limit_argument' => 'The most rows to list, 1 to 100. Default 20.',

    'occurrences_cursor_argument' => 'The cursor of a cut answer, from its truncated entry, with the same arguments.',

    'occurrences_summary' => 'Listed :count record, ordered by :order.|Listed :count records, ordered by :order.',

    'occurrences_cursor_how' => 'Call occurrences again with this cursor to see the rest: :call',

    'occurrences_baseline_withheld' => 'The :percentile baseline has :have records and needs :needed; no record was left out for being below it.',

    'occurrences_user_only' => 'Filtered by recorded user only; use `actor` for dispatch and inside links.',

    'occurrences_next_group' => 'The group of the first row: how it compares with its peers.',

    'since_argument' => 'Start of the window, included: epoch seconds, ISO 8601, a local date or date-time, a relative time such as -1d, or now. Absent: unbounded.',

    'until_argument' => 'End of the window, excluded: the same forms as since. Absent: unbounded.',

    'cell_null' => 'n/a',

    'cell_yes' => 'yes',

    'cell_no' => 'no',

    /*
    |--------------------------------------------------------------------------
    | Server Command
    |--------------------------------------------------------------------------
    |
    | What `firewatch:server` prints outside a session: the tool
    | listing header, and the refusal of the `--json` option
    | when that option is given without the `--list` one.
    |
    */

    'listing' => 'Firewatch MCP server :version: :count tools',

    'json_requires_list' => 'The --json option requires --list.',

];
