<?php

use ClaudioDekker\Firewatch\Mcp\ErrorCode;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\SqlFailure;

test('every general error code has pinned text', function (Closure $refusal, ErrorCode $code, string $text) {
    $refusal = $refusal();

    expect($refusal->error)->toBe($code)
        ->and($refusal->getMessage())->toBe($text);
})->with([
    'missing_argument' => [
        fn () => Refusal::missing('limit', 'a whole number from 1 to 100', 'rank(type: "request", limit: 20)'),
        ErrorCode::MISSING_ARGUMENT,
        "error: missing_argument\n`limit` is required.\nargument: limit\naccepted: a whole number from 1 to 100\nexample: rank(type: \"request\", limit: 20)",
    ],
    'invalid_argument' => [
        fn () => Refusal::invalid('limit', '1 to 100', '500', 'a whole number from 1 to 100', 'rank(type: "request", limit: 20)'),
        ErrorCode::INVALID_ARGUMENT,
        "error: invalid_argument\n`limit` must be 1 to 100; got 500.\nargument: limit\naccepted: a whole number from 1 to 100\nexample: rank(type: \"request\", limit: 20)",
    ],
    'an unknown argument' => [
        fn () => Refusal::unknown('sinse', 'overview', ['since', 'until', 'format']),
        ErrorCode::INVALID_ARGUMENT,
        "error: invalid_argument\n`sinse` is not an argument of overview.\nargument: sinse\naccepted: since, until, format\nexample: overview(format: \"json\")",
    ],
    'an argument that does not apply to the tool' => [
        fn () => Refusal::inapplicable('split_at', 'overview', ['since', 'until', 'format']),
        ErrorCode::CONFLICTING_ARGUMENTS,
        "error: conflicting_arguments\n`split_at` does not apply to overview.\nargument: split_at\naccepted: since, until, format\nexample: overview(format: \"json\")",
    ],
    'arguments that conflict' => [
        fn () => Refusal::conflicting('type', 'execution_id', 'execution_id or type', 'execution(execution_id: "abc")'),
        ErrorCode::CONFLICTING_ARGUMENTS,
        "error: conflicting_arguments\n`type` does not apply with `execution_id`.\nargument: type\naccepted: execution_id or type\nexample: execution(execution_id: \"abc\")",
    ],
    'unreadable_time' => [
        fn () => Refusal::time('since', 'yesterday', 'overview'),
        ErrorCode::UNREADABLE_TIME,
        "error: unreadable_time\n`since` value `yesterday` is not a time this tool reads.\nargument: since\naccepted: epoch seconds up to 4102444800, ISO 8601 with Z or an offset, a local date or date-time (YYYY-MM-DD HH:MM:SS), a relative time such as -1d or -90 minutes (units s, m, h, d, w), or now\nexample: overview(since: \"-1d\")",
    ],
    'empty_window' => [
        fn () => Refusal::window(1790776800.0, 1790773200.0, 'UTC', 'overview'),
        ErrorCode::EMPTY_WINDOW,
        "error: empty_window\n`since` (2026-09-30 14:00:00.000000) is not before `until` (2026-09-30 13:00:00.000000).\nargument: since\naccepted: a `since` earlier than `until`\nexample: overview(since: \"-1d\", until: \"now\")",
    ],
    'split_outside_window' => [
        fn () => Refusal::splitOutsideWindow('compare(type: "request", split_at: "-1h", since: "-1d")'),
        ErrorCode::SPLIT_OUTSIDE_WINDOW,
        "error: split_outside_window\n`split_at` must lie strictly between `since` and `until`.\nargument: split_at\naccepted: a time after `since` and before `until`\nexample: compare(type: \"request\", split_at: \"-1h\", since: \"-1d\")",
    ],
    'not_found' => [
        fn () => Refusal::notFound('trace_id', 'abc', 'a trace id the store holds', 'trace(trace_id: "abc")'),
        ErrorCode::NOT_FOUND,
        "error: not_found\nNo record `abc` exists in the store; the identifier may have been pruned or cleared.\nargument: trace_id\naccepted: a trace id the store holds\nexample: trace(trace_id: \"abc\")",
    ],
    'an execution id that is a trace id' => [
        fn () => Refusal::traceIdNotExecution('execution_id', 'abc', 'an execution id, not a trace id', 'execution(execution_id: "<execution id>")'),
        ErrorCode::NOT_FOUND,
        "error: not_found\nNo execution `abc` exists in the store, but that value is a trace id; `trace(trace_id: \"abc\")` follows it.\nargument: execution_id\naccepted: an execution id, not a trace id\nexample: execution(execution_id: \"<execution id>\")",
    ],
    'bad_cursor' => [
        fn () => Refusal::badCursor('rank'),
        ErrorCode::BAD_CURSOR,
        "error: bad_cursor\n`cursor` does not belong to this call (tool, arguments or store changed); start again without it.\nargument: cursor\naccepted: the `next` cursor of the previous answer to the same call\nexample: rank(cursor: \"<cursor from next>\")",
    ],
    'internal' => [
        fn () => Refusal::internal(),
        ErrorCode::INTERNAL,
        "error: internal\nThe tool failed unexpectedly; the exception was reported to the application's exception handler, which logs it by default.",
    ],
]);

test('every SQL error code has pinned text', function (SqlFailure $failure, ErrorCode $code, string $text) {
    $refusal = Refusal::sql($failure);

    expect($refusal->error)->toBe($code)
        ->and($refusal->getMessage())->toBe($text);
})->with([
    'a function outside the allow-list' => [
        SqlFailure::notAllowed(Denied::FUNCTION, 'printf'),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\nfunction `printf` is not allowed.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type",
    ],
    'a table outside the readable set' => [
        SqlFailure::notAllowed(Denied::TABLE, 'sqlite_master'),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\ntable `sqlite_master` is not readable.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: the readable objects are the twelve record views, records, users, drift, meta, json_each and json_tree.",
    ],
    'an action that is not a read' => [
        SqlFailure::notAllowed(Denied::ACTION, 'INSERT'),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\naction `INSERT` is not allowed.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.",
    ],
    'a second statement' => [
        SqlFailure::notAllowed(Denied::SECOND_STATEMENT),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\none statement only.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.",
    ],
    'no result columns' => [
        SqlFailure::notAllowed(Denied::NO_COLUMNS),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\nthe statement returns no rows.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.",
    ],
    'SQL that is too long' => [
        SqlFailure::notAllowed(Denied::TOO_LONG),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\nthe SQL is longer than 16,384 bytes.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.",
    ],
    'a NUL byte' => [
        SqlFailure::notAllowed(Denied::NUL),
        ErrorCode::NOT_ALLOWED,
        "error: not_allowed\nthe SQL contains a NUL byte.\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type\nhint: only SELECT, WITH ... SELECT, VALUES and EXPLAIN are allowed.",
    ],
    'invalid_sql' => [
        SqlFailure::invalid('no such table: orders'),
        ErrorCode::INVALID_SQL,
        "error: invalid_sql\nno such table: orders\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type",
    ],
    'invalid_sql with a message cut at 300 characters' => [
        SqlFailure::invalid('near "'.str_repeat('x', 400).'": syntax error'),
        ErrorCode::INVALID_SQL,
        "error: invalid_sql\nnear \"".str_repeat('x', 294)."\nargument: sql\naccepted: one SELECT, WITH ... SELECT, VALUES or EXPLAIN statement over the readable objects\nexample: SELECT type, count(*) FROM records GROUP BY type",
    ],
    'aborted' => [
        SqlFailure::aborted(''),
        ErrorCode::ABORTED,
        "error: aborted\nThe query process ended unexpectedly.",
    ],
    'aborted with stderr' => [
        SqlFailure::aborted("PHP Fatal error:  Allowed memory size\nexhausted"),
        ErrorCode::ABORTED,
        "error: aborted\nThe query process ended unexpectedly.\nstderr: PHP Fatal error: Allowed memory size exhausted",
    ],
    'unavailable without proc_open' => [
        SqlFailure::unavailable(Unavailable::PROC_OPEN_MISSING),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: `proc_open` is disabled or missing. Every other Firewatch tool works.",
    ],
    'unavailable without the sqlite3 extension' => [
        SqlFailure::unavailable(Unavailable::SQLITE3_MISSING),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: the sqlite3 extension is not loaded. Every other Firewatch tool works.",
    ],
    'unavailable without an executable PHP_BINARY' => [
        SqlFailure::unavailable(Unavailable::PHP_BINARY),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: PHP_BINARY is not an executable file. Every other Firewatch tool works.",
    ],
    'unavailable on an old SQLite' => [
        SqlFailure::unavailable(Unavailable::SQLITE_TOO_OLD),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: SQLite is older than 3.38.0. Every other Firewatch tool works.",
    ],
    'unavailable when the process can not be started' => [
        SqlFailure::unavailable(Unavailable::SPAWN_FAILED),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: the query process could not be started. Every other Firewatch tool works.",
    ],
    'unavailable without an authorizer' => [
        SqlFailure::unavailable(Unavailable::AUTHORIZER),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: SQLite cannot install an authorizer. Every other Firewatch tool works.",
    ],
    'unavailable without a heap limit' => [
        SqlFailure::unavailable(Unavailable::HEAP_LIMIT),
        ErrorCode::UNAVAILABLE,
        "error: unavailable\nThe SQL tool is unavailable: SQLite did not accept a heap limit. Every other Firewatch tool works.",
    ],
    'failed' => [
        SqlFailure::failed('A row line does not fit the columns.'),
        ErrorCode::FAILED,
        "error: failed\nThe SQL tool failed unexpectedly; the failure was reported to the application's exception handler. Run `php artisan firewatch:doctor`.",
    ],
]);
