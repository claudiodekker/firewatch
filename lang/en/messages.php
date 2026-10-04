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
        Firewatch is a local, dev-only record of what a Laravel application did while it was developed: requests, commands, queued jobs and scheduled tasks, and the queries, exceptions, logs, cache events, mail, notifications and outgoing requests inside them. It only reads; nothing leaves the machine.

        Start with `overview`, then drill down: `overview` (what is wrong), `rank` (worst routes, queries, jobs), `detect` (named problem shapes), `occurrences` (individual records), `execution` (one request, command, job attempt or task in full), `trace` (a request and the jobs it caused), `actor` (one signed-in person), `compare` (before against after), `trend` (over time), `query` (your own read-only SQL, last resort), `describe` (schema, deploys, store facts), `fingerprint` (the group id of something you just read in source). Every answer ends with `next`: calls you can run as written.

        Reading answers: empty is not clean. Every answer states the store clock, the window, coverage and blind spots (what Firewatch cannot see). Clean means nothing was found among the records captured, over the stated examined count. A not_evaluated verdict is never a pass. Null means unknown, not zero. Truncated means only the worst rows are shown: narrow the call or use the cursor. Durations end in _ms, memory in _mb; times are in the application timezone, named on the window; identifiers print in full and go straight back into tools. There is no default window: leave since and until out and everything stored is used.

        Did my change help? Note `now` from an answer, change the code, exercise the application, then call `compare` with `split_at` set to that value; on a later round also pass the previous split as `since`.
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
        'overview' => 'Entry point. Answers "what is wrong in this application?": the store\'s coverage, the server-error and client-error rate, the slowest groups by total time, record counts for all twelve types and the user directory, how many executions had a signed-in actor, budget verdicts, and every problem shape that ships checked at once at its default threshold, within five seconds. Use it first and after every change; drill into a shape with `detect`, into a group with `rank`. Windowed by since/until; without them everything stored counts. Empty is not clean: each shape is clean, has findings or is not_evaluated, with the number of records examined. Nothing is wrong only when every shape ran and is clean.',
        'rank' => 'Ranks the groups of one type (routes, queries, jobs, exceptions and so on) by a measure, worst first, to answer "what is slow, heavy or frequent?". Pass `type`, or `group` to break one group down by deploy (rows in first-seen order) to see whether it changed. `matching` finds a group by a substring of its label. `by` picks the measure, p95_duration by default and occurrences for exceptions. Percentiles are null with a `withheld` object when too few records support them; when no group has enough for the percentile, the order falls back to the maximum and a note says so. Rows carry when the group was first and last seen, its deploys and its slowest execution, and failure_pct where the type has a notion of failure. Windowed by since/until; `deploy` restricts the records; a cursor continues a cut list. Empty is not clean.',
        'execution' => 'One execution in full: a request, command, job attempt or scheduled task. Without arguments it returns the latest one that finished (greatest end time); `type` picks the latest of one kind; `execution_id` picks a specific one (a request\'s trace id is also its execution id). Shows outcome, stages, budget verdict, request headers and payload as captured, counted-versus-captured accounting for eight counters, up to five exceptions with application frames and source lines, and the child timeline. Not windowed. For the job lineage across attempts use `trace`. Recorded when finished: running work is absent.',
        'occurrences' => 'Lists individual records, newest first by default, for the selectors you give (at least one): `group`, `type`, `execution_id`, `trace_id`, `job_id`, `user_id`. Order by recent, slowest, memory or queries. Filters (a filter that does not fit the type is refused): method, status, outcome, level, slower_than_ms, at_or_above (median or p95 of the selection), matching (substring). Rows carry group, name, location (file:line), user and a `detail` object; a query group also lists its distinct call sites. Windowed; cursor for more. Empty is not clean.',
        'trace' => 'Follows one trace: its executions in start order and the lineage of every queued job. A lineage shows the dispatch, attempts in order, wait before each attempt (wait_ms), outcome (processed, failed, retrying, pending) and partial states (no_dispatch, no_attempts). Give exactly one of `trace_id` or `job_id`. Lineage joins on job id, so it is complete even when attempts carry other traces. A job on an inline connection (sync, deferred, background, null) runs in the dispatching process and the sensors record no dispatch for it; a dispatch recorded on one shows no attempts and no outcome. Not windowed. Use `execution` for children, exceptions and source lines.',
        'detect' => 'Runs named problem shapes and returns evidence, worst first. Shape `failing-routes`: the request groups that had a request at or above a status; `threshold` is the lowest status that counts as failed, a whole number from 100 to 599, default 400. Without `shape` it runs every shape; `threshold` and `group` need one shape. Each shape returns a verdict (findings, clean, not_evaluated) over the records it examined, its threshold with unit, range and whether the default applied, the exact total, up to `limit` findings (1 to 100, default 20) and caveats. Clean means none among what was captured, and over few records it is weak: read `examined`. `group` restricts a shape to one group. Windowed by since/until.',
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
        'newer_schema' => 'The store at :path was written by a newer Firewatch schema (version :found, this release reads version :expected). The next captured batch rebuilds it; it holds no readable data until then.',
        'sqlite_too_old' => 'SQLite :version is older than the :minimum Firewatch needs, so nothing is captured and the store at :path can not be read.',
        'unreadable' => 'The store at :path can not be read: :cause',
    ],

    'store_causes' => [
        'corrupt' => 'the file is damaged. The next captured batch moves it aside and starts a new one.',
        'busy' => 'it stayed busy for 1000 ms, so try again.',
    ],

    'store_clock' => 'Store clock: :time (epoch :epoch) - pass that number as since, until or split_at to measure what happens next against what came before',

    'overview_summary' => 'The store holds :records records, :requests of them requests.',

    'overview_detectors_findings' => 'Findings: :shapes.',

    'overview_detectors_not_evaluated' => 'Not evaluated: :shapes.',

    'overview_detectors_clean' => 'No findings: the shape is clean over what was captured.|No findings: all :count shapes are clean over what was captured.',

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

    'store_line' => 'Store: :store',

    'store_history' => 'history complete from :from (:reason); retention :age, :records records',

    'store_unlimited' => 'unlimited',

    'store_to' => 'to',

    'store_records' => ':count records',

    'truncated' => 'Truncated: :section shows :shown of :matched (:reason). :how',

    'truncated_cap' => 'Truncated: :section had cells cut at :characters characters, :shown in all (:reason). :how',

    'cap_how' => 'Cells are cut at :characters characters; read the rest of a value with the query tool: substr(column, :next, :characters).',

    'size_how' => 'The answer is over its budget of :characters characters: narrow the call (a shorter window, a lower limit or a filter) to see the rest.',

    'cell_truncated' => '... [truncated, :count characters]',

    'truncated_unknown' => 'Truncated: :section shows :shown of more (:reason). :how',

    'blind_spots' => [
        'console-requests' => 'Requests are recorded only while the application serves HTTP; test runs and console processes record none. An empty request answer may mean the app was never exercised over HTTP.',
        'unanswered-outgoing-requests' => 'An outgoing request that received no response (timeout, connection failure) leaves no record.',
        'payload-on-server-error-only' => 'A request\'s payload is stored only when the response status is 500.',
        'dead-counters' => 'lazy_loads, hydrated_models, files_read and files_written are always 0 because the sensors never fill them; 0 does not mean none. Use the N+1 detector on query rows.',
        'failed-flag-unpopulated' => 'The failed flag on mail and notification records is always false; failures are not recorded.',
        'mail-by-notification' => 'Mail sent by a notification is recorded as a notification, not as mail.',
        'sync-jobs-unrecorded' => 'Jobs on the sync connection run inside the dispatching execution and have no attempt record.',
        'vendor-defaults-unrecorded' => 'Vendor commands and framework cache keys on Nightwatch\'s default exclusion lists are not recorded.',
        'exceptions-unreported' => 'Exceptions the application does not report, and exceptions inside scheduled tasks, are not recorded.',
        'named-log-channels' => 'Logs written directly to a named channel are not captured; only the default channel is.',
        'memory-is-process-peak' => 'Memory is the whole process\'s peak since the sensor last reset it: requests, scheduled tasks and job attempts reset it, a command reports its whole process; it is not attributable to code.',
        'query-bindings-unpaired' => 'A query\'s bindings are null when they could not be paired with certainty; null means unknown, not none.',
        'uninstrumented-dispatcher' => 'A job dispatched where Nightwatch was not running has attempts but no dispatch, and may have a fresh trace per attempt; join on job id, not trace id.',
        'actor-partial' => 'Commands and scheduled tasks carry no actor; requests with no recorded user may be guests or users of a non-default guard; ids are keys, not people; names come from the application\'s user callback, and impersonation shows the impersonated user.',
        'visible-at-completion' => 'An execution is recorded when it finishes: work still running is absent, and work spanning a boundary sits on the side where it started.',
        'application-opt-outs' => 'Anything the application\'s opt-outs excluded (pause, ignore, reject callbacks, never-sample) and processes killed before flushing are absent.',
        'values-truncated' => 'Long values are cut at ingest (:field_bytes bytes per string, bindings :bindings_bytes bytes in all) and again to :characters characters when printed; the SQL tool prints at most :characters characters per cell, read the rest with substr().',
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
        'schema' => 'The store was written by another Firewatch schema (found :found, expected :expected). Run with --drop to rebuild it now, or let the next captured batch do it.',
        'damaged' => 'The store file is damaged; run with --drop or let the next capture replace it.',
        'foreign' => ':path is not a Firewatch store; nothing was changed.',
        'sqlite' => 'SQLite :version is older than the :minimum Firewatch needs; nothing was changed.',
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

    'internal' => "error: internal\nThe tool failed unexpectedly. Run the doctor command.",

    'unreadable_time' => "error: unreadable_time\n`:argument` value :value is not a time this tool reads.\nargument: :argument\naccepted: epoch seconds up to :maximum, ISO 8601 with Z or an offset, a local date or date-time (YYYY-MM-DD HH:MM:SS), a relative time such as -1d or -90 minutes (units s, m, h, d, w), or now\nexample: :tool(:argument: \"-1d\")",

    'empty_window' => "error: empty_window\n`since` (:since) is not before `until` (:until).\nargument: since\naccepted: a `since` earlier than `until`\nexample: :tool(since: \"-1d\", until: \"now\")",

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

    'execution_id_argument' => 'The execution id to open (a request\'s trace id is its execution id). Omit for the latest finished execution. Excludes type.',

    'execution_type_argument' => 'request, command, job-attempt or scheduled-task: the latest finished execution of that kind. Not with execution_id.',

    'execution_limit_argument' => 'The most timeline entries, 1 to 100. Default 50. Counted after repeated identical queries collapse.',

    'execution_window_reason' => 'one execution, found by its id or as the latest one that finished',

    'execution_any_type' => 'type: any execution type',

    'execution_summary' => 'Showed the :type :id: outcome :outcome.',

    'execution_not_found_trace' => "error: not_found\nNo execution `:id` exists in the store, but that value is a trace id; use trace.\nargument: :argument\naccepted: :accepted\nexample: :example",

    'execution_next_rank' => 'Rank the group of this execution to see how it compares with the others of its kind.',
    'execution_next_occurrences' => 'List the queries this execution ran, to see which was slowest.',
    'execution_next_trace' => 'Follow the trace of this execution: the executions it belongs to and the jobs it dispatched.',

    'execution_timeline_how' => 'Pass a larger `limit`, up to 100, to see more of the timeline.',

    'execution_exceptions_how' => 'The five earliest exceptions are shown; the accounting row counts them all.',

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

    'detect_shape_argument' => 'The shape to run: failing-routes. Absent: every shape that ships.',
    'detect_threshold_argument' => 'Overrides the shape\'s default, in its unit; the answer states the unit, the range and whether the default applied. failing-routes: the lowest status that counts as failed, a whole number from 100 to 599, default 400. Needs `shape`.',
    'detect_group_argument' => 'One group id (32 hex) to restrict the shape to. A group that holds no records is an empty answer. Needs `shape`.',
    'detect_limit_argument' => 'The most findings to list, 1 to 100. Default 20.',
    'detect_input' => [
        'failing-routes' => 'requests',
    ],
    'detect_findings_summary' => ':detector: :total findings over :examined :input.',
    'detect_clean_summary' => ':detector: clean over :examined :input.',
    'detect_not_evaluated_summary' => ':detector: not evaluated (:reason).',
    'detect_all_summary' => 'Problem shapes: :parts.',
    'detect_all_clean_summary' => 'No findings: the shape is clean over what was captured.|No findings: all :count shapes are clean over what was captured.',
    'detect_part_findings' => ':detector :total findings',
    'detect_part_not_evaluated' => ':detector not evaluated (:reason)',
    'detect_part_clean' => ':detector clean',
    'detect_findings_how' => 'List more of the findings with a larger `limit`, up to 100, or narrow the call with `group`, `since` and `until`.',
    'detect_next_execution' => 'Open the latest execution that failed.',
    'detect_next_occurrences' => 'List the records of the group.',
    'detect_next_rank' => 'See how the group compares with the others, and whether it changed with a deploy.',
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
