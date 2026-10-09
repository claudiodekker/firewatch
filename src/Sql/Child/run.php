<?php

namespace ClaudioDekker\Firewatch\Sql\Child;

use Exception;
use SQLite3;
use SQLite3Exception;

require_once __DIR__.'/Denied.php';
require_once __DIR__.'/Unavailable.php';
require_once __DIR__.'/Policy.php';

const SQLITE_ERROR = 1;

const SQLITE_BUSY = 5;

const SQLITE_NOMEM = 7;

const SQLITE_CORRUPT = 11;

const SQLITE_NOTADB = 26;

const BUSY_TIMEOUT_MILLISECONDS = 1000;

$encode = fn (array $line): string => json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

$write = function (array $line) use ($encode): void {
    fwrite(STDOUT, $encode($line)."\n");
};

$state = fn (string $state, ?int $found = null) => [
    'k' => 'state',
    'state' => $state,
    'found' => $found,
];

$error = fn (string $code, string $fact, ?string $value) => [
    'k' => 'error',
    'code' => $code,
    $fact => $value,
];

$refuse = fn (Denied $denied, ?string $name = null) => [
    ...$error('not_allowed', 'subject', $denied->value),
    'name' => $name,
];

$request = json_decode((string) stream_get_contents(STDIN), associative: true);

if (! is_array($request) || ! is_string($request['sql'] ?? null) || ! is_int($request['limit'] ?? null) || ! is_string($request['store'] ?? null)
    || ! is_int($request['application_id'] ?? null) || ! is_int($request['version'] ?? null)) {
    $write($error('failed', 'message', 'The request is unreadable.'));

    return;
}

$screened = Policy::screen($request['sql']);

if ($screened !== null) {
    $write($refuse($screened));

    return;
}

clearstatcache(true, $request['store']);

if (! is_file($request['store']) || filesize($request['store']) === 0) {
    $write($state('absent'));

    return;
}

try {
    $connection = new SQLite3($request['store'], SQLITE3_OPEN_READONLY);
    $connection->enableExceptions(true);
    $connection->busyTimeout(BUSY_TIMEOUT_MILLISECONDS);
    $connection->exec('PRAGMA query_only = 1');
    $connection->exec('PRAGMA trusted_schema = 0');
    $connection->exec('PRAGMA temp_store = MEMORY');
    $connection->exec('PRAGMA hard_heap_limit = '.Policy::HEAP_LIMIT_BYTES);

    if ($connection->querySingle('PRAGMA hard_heap_limit') !== Policy::HEAP_LIMIT_BYTES) {
        $write($error('unavailable', 'reason', Unavailable::HEAP_LIMIT->value));

        return;
    }

    // Deferred: the stamp reads below take the one snapshot the statement then reads in.
    $connection->exec('BEGIN');

    $applicationId = $connection->querySingle('PRAGMA application_id');
    $version = $connection->querySingle('PRAGMA user_version');
    $known = $connection->query('SELECT lower(name) FROM sqlite_schema UNION SELECT lower(name) FROM sqlite_temp_schema UNION SELECT lower(name) FROM pragma_module_list') ?: throw new SQLite3Exception($connection->lastErrorMsg(), $connection->lastErrorCode());
    $objects = [];

    while (($object = $known->fetchArray(SQLITE3_NUM)) !== false) {
        $objects[] = (string) $object[0];
    }
} catch (SQLite3Exception $exception) {
    $write(match ($exception->getCode() & 0xFF) {
        SQLITE_BUSY => $state('busy'),
        SQLITE_CORRUPT, SQLITE_NOTADB => $state('corrupt'),
        default => $error('failed', 'message', $exception->getMessage()),
    });

    return;
} catch (Exception $exception) {
    $write($error('failed', 'message', $exception->getMessage()));

    return;
}

if ($applicationId !== $request['application_id']) {
    $write($state('foreign'));

    return;
}

if ($version !== $request['version']) {
    $write($state('schema_mismatch', is_int($version) ? $version : null));

    return;
}

$classify = fn (SQLite3Exception $exception) => match ($exception->getCode() & 0xFF) {
    SQLITE_ERROR => $error('invalid_sql', 'message', $connection->lastErrorMsg()),
    SQLITE_BUSY => $state('busy'),
    SQLITE_CORRUPT, SQLITE_NOTADB => $state('corrupt'),
    default => $error('failed', 'message', $exception->getMessage()),
};

$denial = null;
$selects = false;
$reads = [];

// Installed after BEGIN, so the child's own transaction control needs no allowed action.
$installed = $connection->setAuthorizer(function (int $action, ?string $first, ?string $second, ?string $database, ?string $via) use ($objects, &$denial, &$selects, &$reads): int {
    $verdict = Policy::authorize($action, $first, $second, $objects);

    if ($verdict !== null) {
        $denial ??= $verdict;

        return SQLite3::DENY;
    }

    $selects = $selects || $action === SQLite3::SELECT;

    if ($action === SQLite3::READ) {
        $read = [strtolower((string) $first), $via === null ? null : strtolower($via)];
        $reads[implode("\0", $read)] = $read;
    }

    return SQLite3::OK;
});

if (! $installed) {
    $write($error('unavailable', 'reason', Unavailable::AUTHORIZER->value));

    return;
}

// prepare(), never query() or exec(): a tail after the first statement is never run, whatever the screen missed.
try {
    $statement = $connection->prepare($request['sql']) ?: throw new SQLite3Exception($connection->lastErrorMsg(), $connection->lastErrorCode());
} catch (SQLite3Exception $exception) {
    $write($denial === null ? $classify($exception) : $refuse(...$denial));

    return;
}

// Every allowed form asks to SELECT while it compiles. VACUUM asks the authorizer nothing, or only a SELECT for the
// subquery naming its target file, so only readOnly() tells it apart.
if (! $selects || ! $statement->readOnly()) {
    $write($refuse(Denied::NO_COLUMNS));

    return;
}

$rows = 0;
$bytes = 0;
$stop = 'complete';
$message = null;
$columns = null;

try {
    $result = $statement->execute() ?: throw new SQLite3Exception($connection->lastErrorMsg(), $connection->lastErrorCode());
    $columns = [];

    for ($index = 0; $index < $result->numColumns(); $index++) {
        $columns[] = (string) $result->columnName($index);
    }

    $write([
        'k' => 'columns',
        'columns' => $columns,
        'reads' => array_values($reads),
    ]);

    while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
        if ($rows === $request['limit']) {
            $stop = 'limit';

            break;
        }

        $cells = [];

        foreach ($row as $index => $value) {
            $cells[] = Policy::cell($value, $result->columnType($index) === SQLITE3_BLOB);
        }

        $size = strlen($encode($cells));

        if ($bytes + $size > Policy::ROW_BUDGET_BYTES) {
            $stop = 'budget';

            break;
        }

        $write([
            'k' => 'row',
            'r' => $cells,
        ]);
        $rows++;
        $bytes += $size;
    }
} catch (SQLite3Exception $exception) {
    $nomem = ($exception->getCode() & 0xFF) === SQLITE_NOMEM;

    if ($nomem && $columns === null) {
        $write($error('memory', 'message', $connection->lastErrorMsg()));

        return;
    }

    if (! $nomem && ($rows === 0 || $denial !== null)) {
        $write($denial === null ? $classify($exception) : $refuse(...$denial));

        return;
    }

    $stop = $nomem ? 'memory' : 'error';
    $message = $nomem ? null : $connection->lastErrorMsg();
}

$write([
    'k' => 'end',
    'rows' => $rows,
    'stop' => $stop,
    ...($message === null ? [] : ['message' => $message]),
]);

$connection->close();
