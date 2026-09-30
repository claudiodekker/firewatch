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

    'store_clock' => 'Store clock: :time (epoch :epoch) - pass that number as since, until or split_at to measure what happens next against what came before',

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
