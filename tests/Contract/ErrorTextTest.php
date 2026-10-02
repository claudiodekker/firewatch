<?php

use ClaudioDekker\Firewatch\Mcp\ErrorCode;
use ClaudioDekker\Firewatch\Mcp\Refusal;

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
        fn () => Refusal::traceIdNotExecution('execution_id', 'abc', 'an execution id; a trace id belongs to `trace`', 'execution(execution_id: "<execution id>")'),
        ErrorCode::NOT_FOUND,
        "error: not_found\nNo execution `abc` exists in the store, but that value is a trace id; use trace.\nargument: execution_id\naccepted: an execution id; a trace id belongs to `trace`\nexample: execution(execution_id: \"<execution id>\")",
    ],
    'bad_cursor' => [
        fn () => Refusal::badCursor('rank'),
        ErrorCode::BAD_CURSOR,
        "error: bad_cursor\n`cursor` does not belong to this call (tool, arguments or store changed); start again without it.\nargument: cursor\naccepted: the `next` cursor of the previous answer to the same call\nexample: rank(cursor: \"<cursor from next>\")",
    ],
    'internal' => [
        fn () => Refusal::internal(),
        ErrorCode::INTERNAL,
        "error: internal\nThe tool failed unexpectedly. Run the doctor command.",
    ],
]);
