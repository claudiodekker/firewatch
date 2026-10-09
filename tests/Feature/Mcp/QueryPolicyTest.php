<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Child\Policy;

const QP_ALL_TYPES = ['request', 'command', 'job-attempt', 'scheduled-task', 'query', 'exception', 'log', 'cache-event', 'mail', 'notification', 'outgoing-request', 'queued-job'];

/**
 * The statements the policy allows, each with the record types the answer says it read.
 */
const QP_ALLOWED = [
    'SELECT * FROM requests' => ['request'],
    'SELECT * FROM commands' => ['command'],
    'SELECT * FROM job_attempts' => ['job-attempt'],
    'SELECT * FROM scheduled_tasks' => ['scheduled-task'],
    'SELECT * FROM queries' => ['query'],
    'SELECT * FROM exceptions' => ['exception'],
    'SELECT * FROM logs' => ['log'],
    'SELECT * FROM cache_events' => ['cache-event'],
    'SELECT * FROM mail' => ['mail'],
    'SELECT * FROM notifications' => ['notification'],
    'SELECT * FROM outgoing_requests' => ['outgoing-request'],
    'SELECT * FROM queued_jobs' => ['queued-job'],
    'SELECT * FROM records' => QP_ALL_TYPES,
    'SELECT * FROM users' => ['user'],
    'SELECT * FROM drift' => [],
    'SELECT * FROM meta' => [],
    'WITH r AS (SELECT * FROM requests) SELECT count(*) FROM r' => ['request'],
    'WITH r AS (SELECT * FROM records) SELECT count(*) FROM r' => QP_ALL_TYPES,
    'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT n FROM c' => [],
    'VALUES (1), (2)' => [],
    'WITH c AS (SELECT 1 AS n) SELECT count(*) FROM c' => [],
    'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT count(*) FROM c' => [],
    'WITH c AS MATERIALIZED (SELECT id FROM records) SELECT count(*) FROM c' => QP_ALL_TYPES,
    'SELECT count(*) FROM RECORDS' => QP_ALL_TYPES,
    'SELECT count(*) FROM META' => [],
    'SELECT id FROM REQUESTS' => ['request'],
    'SELECT count(*) FROM queries' => ['query'],
    'SELECT id FROM requests UNION SELECT id FROM records' => QP_ALL_TYPES,
    'SELECT j.key FROM requests, json_each(requests.headers) j' => ['request'],
    'SELECT j.key FROM requests, json_tree(requests.headers) j' => ['request'],
    'SELECT 1;' => [],
    'SELECT 1; -- done' => [],
    'SELECT 1;; /* x */ ;' => [],
    "SELECT ';'" => [],
];

/**
 * A call of every function of the allow-list.
 */
const QP_FUNCTION_CALLS = [
    'json' => "SELECT json('{}')",
    'json_array' => 'SELECT json_array(1)',
    'json_array_length' => "SELECT json_array_length('[1]')",
    'json_extract' => "SELECT json_extract('{\"a\":1}', '$.a')",
    'json_insert' => "SELECT json_insert('{}', '$.a', 1)",
    'json_object' => "SELECT json_object('a', 1)",
    'json_patch' => "SELECT json_patch('{}', '{}')",
    'json_remove' => "SELECT json_remove('{\"a\":1}', '$.a')",
    'json_replace' => "SELECT json_replace('{}', '$.a', 1)",
    'json_set' => "SELECT json_set('{}', '$.a', 1)",
    'json_type' => "SELECT json_type('1')",
    'json_valid' => "SELECT json_valid('1')",
    'json_quote' => 'SELECT json_quote(1)',
    'json_group_array' => 'SELECT json_group_array(id) FROM records',
    'json_group_object' => 'SELECT json_group_object(id, type) FROM records',
    'json_each' => "SELECT key FROM json_each('[1]')",
    'json_tree' => "SELECT key FROM json_tree('[1]')",
    '->' => "SELECT '{\"a\":1}' -> '$.a'",
    '->>' => "SELECT '{\"a\":1}' ->> '$.a'",
    'count' => 'SELECT count(*) FROM records',
    'sum' => 'SELECT sum(id) FROM records',
    'avg' => 'SELECT avg(id) FROM records',
    'total' => 'SELECT total(id) FROM records',
    'min' => 'SELECT min(1, 2)',
    'max' => 'SELECT max(1, 2)',
    'group_concat' => 'SELECT group_concat(type) FROM records',
    'string_agg' => "SELECT string_agg(type, ',') FROM records",
    'abs' => 'SELECT abs(-1)',
    'round' => 'SELECT round(1.5)',
    'sign' => 'SELECT sign(-2)',
    'ceil' => 'SELECT ceil(1.2)',
    'ceiling' => 'SELECT ceiling(1.2)',
    'floor' => 'SELECT floor(1.2)',
    'trunc' => 'SELECT trunc(1.2)',
    'mod' => 'SELECT mod(5, 2)',
    'pow' => 'SELECT pow(2, 3)',
    'power' => 'SELECT power(2, 3)',
    'sqrt' => 'SELECT sqrt(4)',
    'exp' => 'SELECT exp(1)',
    'ln' => 'SELECT ln(1)',
    'log' => 'SELECT log(100)',
    'log2' => 'SELECT log2(8)',
    'log10' => 'SELECT log10(100)',
    'length' => "SELECT length('a')",
    'lower' => "SELECT lower('A')",
    'upper' => "SELECT upper('a')",
    'trim' => "SELECT trim(' a ')",
    'ltrim' => "SELECT ltrim(' a')",
    'rtrim' => "SELECT rtrim('a ')",
    'substr' => "SELECT substr('abc', 2)",
    'substring' => "SELECT substring('abc', 2)",
    'instr' => "SELECT instr('abc', 'b')",
    'replace' => "SELECT replace('a', 'a', 'b')",
    'like' => "SELECT 'a' LIKE 'a'",
    'glob' => "SELECT 'a' GLOB 'a'",
    'unicode' => "SELECT unicode('a')",
    'concat' => "SELECT concat('a', 'b')",
    'concat_ws' => "SELECT concat_ws(',', 'a', 'b')",
    'coalesce' => 'SELECT coalesce(NULL, 1)',
    'ifnull' => 'SELECT ifnull(NULL, 1)',
    'iif' => 'SELECT iif(1, 2, 3)',
    'nullif' => 'SELECT nullif(1, 2)',
    'typeof' => 'SELECT typeof(1)',
    'likely' => 'SELECT likely(1)',
    'unlikely' => 'SELECT unlikely(1)',
    'likelihood' => 'SELECT likelihood(1, 0.5)',
    'row_number' => 'SELECT row_number() OVER (ORDER BY id) FROM records',
    'rank' => 'SELECT rank() OVER (ORDER BY id) FROM records',
    'dense_rank' => 'SELECT dense_rank() OVER (ORDER BY id) FROM records',
    'percent_rank' => 'SELECT percent_rank() OVER (ORDER BY id) FROM records',
    'cume_dist' => 'SELECT cume_dist() OVER (ORDER BY id) FROM records',
    'ntile' => 'SELECT ntile(2) OVER (ORDER BY id) FROM records',
    'lag' => 'SELECT lag(id) OVER (ORDER BY id) FROM records',
    'lead' => 'SELECT lead(id) OVER (ORDER BY id) FROM records',
    'first_value' => 'SELECT first_value(id) OVER (ORDER BY id) FROM records',
    'last_value' => 'SELECT last_value(id) OVER (ORDER BY id) FROM records',
    'nth_value' => 'SELECT nth_value(id, 1) OVER (ORDER BY id) FROM records',
    'date' => "SELECT date('now')",
    'time' => "SELECT time('now')",
    'datetime' => "SELECT datetime('now')",
    'julianday' => "SELECT julianday('now')",
    'unixepoch' => "SELECT unixepoch('now')",
    'strftime' => "SELECT strftime('%s', 'now')",
];

beforeEach(function () {
    ingest(array_map(fn (RecordType $type) => syntheticRecord($type), RecordType::cases()));

    $this->unchanged = qpStoreState();
});

afterEach(function () {
    expect(qpStoreState())->toBe($this->unchanged);
});

/**
 * Get what a statement may not change: the bytes of the store and its log, and the files beside it.
 *
 * @return array<string, string|false>
 */
function qpStoreState(): array
{
    $path = app(Configuration::class)->database;
    $files = array_values(array_diff(scandir(dirname($path)), ['.', '..']));

    return [
        'files' => implode(',', $files),
        'store' => md5_file($path),
        'log' => is_file("{$path}-wal") ? md5_file("{$path}-wal") : false,
    ];
}

/**
 * Run a statement and get the JSON answer, or the text of the error.
 *
 * @return array<string, mixed>|string
 */
function qpCall(string $sql): array|string
{
    $response = FirewatchServer::tool(Query::class, ['sql' => $sql, 'limit' => 500, 'format' => 'json']);
    $text = (fn () => $this->content())->call($response)[0];

    return str_starts_with($text, 'error: ') ? $text : json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Get the first two lines of a refusal: its code and its message.
 *
 * @return list<string>
 */
function qpRefusal(string $sql): array
{
    $text = qpCall($sql);

    expect($text)->toBeString();

    return array_slice(explode("\n", $text), 0, 2);
}

/**
 * Get the message of a refusal by the policy.
 */
function qpDenied(string $subject, ?string $name = null): array
{
    return ['error: not_allowed', __("firewatch::messages.sql_denied.{$subject}", ['name' => $name, 'bytes' => '16,384'])];
}

dataset('allowed statements', array_combine(array_keys(QP_ALLOWED), array_map(fn (string $sql, array $types) => [$sql, $types], array_keys(QP_ALLOWED), QP_ALLOWED)));

dataset('explanations', ['EXPLAIN', 'EXPLAIN QUERY PLAN']);

it('runs every allowed form and reads the record types the authorizer saw', function (string $sql, array $types) {
    $answer = qpCall($sql);

    expect($answer)->toBeArray()
        ->and($answer['result']['columns'])->not->toBe([])
        ->and($answer['coverage']['types_read'])->toBe($types);
})->with('allowed statements')->group('process');

it('explains every allowed form, reading the same record types', function (string $explain, string $sql, array $types) {
    $answer = qpCall("{$explain} {$sql}");

    expect($answer)->toBeArray()
        ->and($answer['result']['rows'])->not->toBe([])
        ->and($answer['coverage']['types_read'])->toBe($types);
})->with('explanations', 'allowed statements')->group('process');

it('calls every function of the allow-list', function (string $sql) {
    $answer = qpCall($sql);

    expect($answer)->toBeArray()
        ->and($answer['result']['rows'])->not->toBe([]);
})->with(QP_FUNCTION_CALLS)->group('process');

it('pins a call for every function of the allow-list', function () {
    expect(array_keys(QP_FUNCTION_CALLS))->toBe(Policy::FUNCTIONS);
});

it('refuses a second statement wherever it hides', function (string $sql) {
    expect(qpRefusal($sql))->toBe(qpDenied('second_statement'));
})->with([
    'after a statement' => 'SELECT 1; SELECT 2',
    'after a string holding a semicolon' => "SELECT 'a;b'; SELECT 2",
    'after a line comment holding a semicolon' => "SELECT 1 -- ;\n; SELECT 2",
    'after a block comment holding a semicolon' => 'SELECT 1 /* ; */; SELECT 2',
    'after a quoted identifier holding a semicolon' => 'SELECT "x;y" FROM (SELECT 1 AS "x;y"); SELECT 2',
    'after a bracketed identifier holding a semicolon' => 'SELECT [a;b] FROM (SELECT 1 AS [a;b]); DELETE FROM records',
    'after a backticked identifier holding a semicolon' => 'SELECT `a;b` FROM (SELECT 1 AS `a;b`); SELECT 2',
    'after a doubled quote' => "SELECT 'it''s;'; SELECT 2",
    'a write after a select' => 'SELECT 1; DROP TABLE records',
    'inside a trigger body' => 'CREATE TRIGGER t AFTER INSERT ON meta BEGIN SELECT 1; END',
]);

it('refuses a statement that returns no rows', function (string $sql) {
    expect(qpRefusal($sql))->toBe(qpDenied('no_columns'));
})->with([
    'only a comment' => '-- only a comment',
    'only a semicolon' => ';',
    'only whitespace' => '   ',
    'VACUUM, which asks the authorizer nothing' => 'VACUUM',
    'VACUUM INTO a file' => "VACUUM INTO 'copy.sqlite'",
    'an explained VACUUM' => 'EXPLAIN VACUUM',
    'VACUUM INTO a file a subquery names' => "VACUUM INTO (SELECT 'copy.sqlite')",
])->group('process');

it('refuses every action that is not a read, naming the first SQLite asked about', function (string $sql, string $action) {
    expect(qpRefusal($sql))->toBe(qpDenied('action', $action));
})->with([
    'INSERT' => ["INSERT INTO meta VALUES ('a', 'b')", 'INSERT'],
    'UPDATE' => ["UPDATE meta SET value = 'b'", 'UPDATE'],
    'DELETE' => ['DELETE FROM records', 'DELETE'],
    'CREATE TABLE' => ['CREATE TABLE t (a)', 'INSERT'],
    'CREATE TEMP TABLE' => ['CREATE TEMP TABLE t (a)', 'INSERT'],
    'CREATE VIEW' => ['CREATE VIEW v AS SELECT 1', 'INSERT'],
    'CREATE INDEX' => ['CREATE INDEX i ON meta (value)', 'INSERT'],
    'CREATE VIRTUAL TABLE' => ['CREATE VIRTUAL TABLE v USING fts5(a)', 'INSERT'],
    'DROP TABLE' => ['DROP TABLE meta', 'DELETE'],
    'ALTER TABLE' => ['ALTER TABLE meta ADD COLUMN z', 'ALTER TABLE'],
    'ATTACH' => ["ATTACH ':memory:' AS other", 'ATTACH'],
    'DETACH' => ['DETACH other', 'DETACH'],
    'PRAGMA' => ['PRAGMA user_version', 'PRAGMA'],
    'ANALYZE' => ['ANALYZE', 'INSERT'],
    'REINDEX' => ['REINDEX', 'REINDEX'],
    'BEGIN' => ['BEGIN', 'TRANSACTION'],
    'COMMIT' => ['COMMIT', 'TRANSACTION'],
    'ROLLBACK' => ['ROLLBACK', 'TRANSACTION'],
    'SAVEPOINT' => ['SAVEPOINT s', 'SAVEPOINT'],
    'RELEASE' => ['RELEASE s', 'SAVEPOINT'],
])->group('process');

it('refuses every function outside the allow-list by name', function (string $function, string $sql) {
    expect(qpRefusal($sql))->toBe(qpDenied('function', $function));
})->with([
    'printf' => ['printf', "SELECT printf('%d', 1)"],
    'format' => ['format', "SELECT format('%d', 1)"],
    'char' => ['char', 'SELECT char(65)'],
    'hex' => ['hex', 'SELECT hex(1)'],
    'zeroblob' => ['zeroblob', 'SELECT zeroblob(1)'],
    'randomblob' => ['randomblob', 'SELECT randomblob(1)'],
    'random' => ['random', 'SELECT random()'],
    'changes' => ['changes', 'SELECT changes()'],
    'total_changes' => ['total_changes', 'SELECT total_changes()'],
    'last_insert_rowid' => ['last_insert_rowid', 'SELECT last_insert_rowid()'],
    'sqlite_version' => ['sqlite_version', 'SELECT sqlite_version()'],
    'sqlite_source_id' => ['sqlite_source_id', 'SELECT sqlite_source_id()'],
    'load_extension' => ['load_extension', "SELECT load_extension('x')"],
    'fts3_tokenizer' => ['fts3_tokenizer', "SELECT fts3_tokenizer('x')"],
    'upper case' => ['printf', "SELECT PRINTF('%d', 1)"],
])->group('process');

it('refuses a function the linked SQLite does not know as invalid SQL', function (string $sql, string $message) {
    expect(qpRefusal($sql))->toBe(['error: invalid_sql', $message]);
})->with([
    'readfile' => ["SELECT readfile('x')", 'no such function: readfile'],
    'writefile' => ["SELECT writefile('x', 'y')", 'no such function: writefile'],
    'an unknown function' => ['SELECT nope()', 'no such function: nope'],
])->group('process');

it('refuses every table outside the readable set by the name SQLite reads', function (string $sql, string $table) {
    expect(qpRefusal($sql))->toBe(qpDenied('table', $table));
})->with([
    'sqlite_master' => ['SELECT * FROM sqlite_master', 'sqlite_master'],
    'sqlite_schema, read as sqlite_master' => ['SELECT * FROM sqlite_schema', 'sqlite_master'],
    'sqlite_temp_master' => ['SELECT * FROM sqlite_temp_master', 'sqlite_temp_master'],
    'sqlite_sequence' => ['SELECT * FROM sqlite_sequence', 'sqlite_sequence'],
    'a pragma function' => ["SELECT * FROM pragma_table_info('records')", 'pragma_table_info'],
    'inside a CTE' => ['WITH m AS (SELECT * FROM sqlite_master) SELECT * FROM m', 'sqlite_master'],
    'counted, with no column read' => ['SELECT count(*) FROM sqlite_master', 'sqlite_master'],
    'counted under another case' => ['SELECT count(*) FROM SQLITE_SEQUENCE', 'SQLITE_SEQUENCE'],
    'a pragma function, counted' => ["SELECT count(*) FROM pragma_table_info('records')", 'pragma_table_info'],
    'a CTE named after an unreadable table, counted' => ['WITH sqlite_sequence AS (SELECT 1) SELECT count(*) FROM sqlite_sequence', 'sqlite_sequence'],
    'an unreadable table counted through a CTE' => ['WITH m AS (SELECT name FROM sqlite_master) SELECT count(*) FROM m', 'sqlite_master'],
])->group('process');

it('refuses dbstat as unreadable where the linked SQLite has it, and as invalid SQL where it lacks it', function () {
    $linked = (new SQLite3(':memory:'))->querySingle("SELECT count(*) FROM pragma_module_list WHERE name = 'dbstat'") === 1;

    expect(qpRefusal('SELECT * FROM dbstat'))->toBe($linked ? qpDenied('table', 'dbstat') : ['error: invalid_sql', 'no such table: dbstat']);
})->group('process');
