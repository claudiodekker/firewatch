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
        'overview' => 'Entry point. Answers "what is wrong in this application?": the store\'s coverage, the server-error and client-error rate, the slowest groups by total time, record counts for all twelve types and the user directory, how many executions had a signed-in actor, budget verdicts, and all eleven problem shapes checked at once. Use it first and after every change; drill into a shape with `detect`, into a group with `rank`. Windowed by since/until; without them everything stored counts. Empty is not clean: each shape is clean, has findings or is not_evaluated, with the number of records examined. Nothing is wrong only when all eleven ran and are clean.',
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

    'store_to' => 'to',

    'store_records' => ':count records',

    'truncated' => 'Truncated: :section shows :shown of :matched (:reason). :how',

    'truncated_unknown' => 'Truncated: :section shows :shown of more (:reason). :how',

    'blind_spot' => 'Blind spot (:id): :message',

    'next' => 'Next:',

    'format_argument' => 'markdown (default) or json: the same answer either way.',

    'format_refused' => "error: invalid_argument\n`format` must be markdown or json; got :value.\nargument: format\naccepted: markdown or json\nexample: :tool(format: \"json\")",

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
