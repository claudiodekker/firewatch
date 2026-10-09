<?php

use ClaudioDekker\Firewatch\Mcp\Catalogue;
use ClaudioDekker\Firewatch\Mcp\Unit;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Store\Schema;
use Illuminate\Support\Facades\Lang;
use Laravel\Nightwatch\QueryConnectionType;
use Laravel\Nightwatch\Records\CacheEvent;

/**
 * Get the columns of every object of the shipped schema, by object, in schema order.
 *
 * @return array<string, list<string>>
 */
function catalogueSchema(): array
{
    $connection = new SQLite3(':memory:');

    foreach ((new Schema)->statements() as $statement) {
        $connection->exec($statement);
    }

    $objects = $connection->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'");
    $schema = [];

    while (($object = $objects->fetchArray(SQLITE3_ASSOC)) !== false) {
        $columns = $connection->query("PRAGMA table_xinfo({$object['name']})");

        while (($column = $columns->fetchArray(SQLITE3_ASSOC)) !== false) {
            $schema[$object['name']][] = $column['name'];
        }
    }

    return $schema;
}

test('the catalogue lists the objects the SQL tool reads, in the order the policy names them', function () {
    expect(array_column((new Catalogue)->objects(), 'object'))->toBe(array_values(array_diff(Policy::READABLE, ['json_each', 'json_tree'])))
        ->and(array_keys(catalogueSchema()))->toEqualCanonicalizing(array_column((new Catalogue)->objects(), 'object'));
});

test('every object names the type that describes it, and records, drift and meta name none', function () {
    $types = array_column((new Catalogue)->objects(), 'type', 'object');

    expect($types)->toBe([
        'requests' => 'request',
        'commands' => 'command',
        'job_attempts' => 'job-attempt',
        'scheduled_tasks' => 'scheduled-task',
        'queries' => 'query',
        'exceptions' => 'exception',
        'logs' => 'log',
        'cache_events' => 'cache-event',
        'mail' => 'mail',
        'notifications' => 'notification',
        'outgoing_requests' => 'outgoing-request',
        'queued_jobs' => 'queued-job',
        'records' => null,
        'users' => 'user',
        'drift' => null,
        'meta' => null,
    ]);
});

test('every number the schema holds has a unit unless it counts, codes or identifies', function () {
    $unitless = [];

    foreach (array_keys(catalogueSchema()) as $object) {
        foreach ((new Catalogue)->columns($object) as $column) {
            if (in_array($column['sql_type'], ['INTEGER', 'REAL'], true) && $column['unit'] === null && $column['values'] === null) {
                $unitless[] = $column['column'];
            }
        }
    }

    expect(array_values(array_unique($unitless)))->toBe(['id', 'v', 'count', 'status_code', 'exceptions', 'logs', 'queries', 'lazy_loads', 'jobs_queued', 'mail', 'notifications', 'outgoing_requests', 'files_read', 'files_written', 'cache_events', 'hydrated_models', 'exit_code', 'attempt', 'line', 'to', 'cc', 'bcc', 'attachments']);
});

test('every schema column has a meaning of at most 20 words', function (string $object, array $columns) {
    foreach ($columns as $column) {
        $own = "firewatch::messages.column_meanings_in.{$object}.{$column}";
        $key = Lang::has($own) ? $own : "firewatch::messages.column_meanings.{$column}";

        expect(Lang::has($key))->toBeTrue("{$object}.{$column} has no meaning")
            ->and(str_word_count(__($key)))->toBeLessThanOrEqual(20, "{$object}.{$column} runs over 20 words");
    }
})->with(fn () => array_map(fn (string $object, array $columns) => [$object, $columns], array_keys(catalogueSchema()), catalogueSchema()));

test('no meaning, recipe, object line or example text is left without a user', function () {
    $schema = catalogueSchema();
    $columns = array_unique(array_merge(...array_values($schema)));
    $grouped = array_values(array_map(fn (RecordType $type) => $type->value, array_filter(RecordType::events(), fn (RecordType $type) => $type !== RecordType::LOG)));
    $meanings = trans('firewatch::messages.column_meanings');
    $overrides = trans('firewatch::messages.column_meanings_in');

    expect(array_values(array_diff(array_keys($meanings), $columns)))->toBe([])
        ->and(array_values(array_diff(array_keys($overrides), array_keys($schema))))->toBe([])
        ->and(array_values(array_diff(array_keys(trans('firewatch::messages.describe_recipes')), $grouped)))->toBe([])
        ->and(array_keys(trans('firewatch::messages.describe_recipes')))->toEqualCanonicalizing($grouped)
        ->and(array_keys(trans('firewatch::messages.objects')))->toBe(array_column((new Catalogue)->objects(), 'object'))
        ->and(array_keys(trans('firewatch::messages.describe_units')))->toBe(array_column(Unit::cases(), 'value'))
        ->and(array_keys(trans('firewatch::messages.describe_examples')))->toEqualCanonicalizing([
            ...array_keys(Catalogue::EXAMPLES),
            ...array_merge(...array_values(array_map('array_keys', Catalogue::TYPE_EXAMPLES))),
        ]);

    foreach ($overrides as $object => $own) {
        expect(array_values(array_diff(array_keys($own), $schema[$object])))->toBe([], "{$object} overrides a column it lacks");
    }
});

test('a meaning given for one object differs from the general one', function () {
    foreach (trans('firewatch::messages.column_meanings_in') as $object => $own) {
        foreach ($own as $column => $meaning) {
            expect($meaning)->not->toBe(trans("firewatch::messages.column_meanings.{$column}"), "{$object}.{$column} repeats the general meaning");
        }
    }
});

test('every example is offered for a type with an object of its own, three for each type and five without one', function () {
    expect(Catalogue::EXAMPLES)->toHaveCount(5)
        ->and(array_keys(Catalogue::TYPE_EXAMPLES))->toBe(array_map(fn (RecordType $type) => $type->value, RecordType::cases()));

    foreach (Catalogue::TYPE_EXAMPLES as $examples) {
        expect($examples)->toHaveCount(3);
    }
});

test('the columns the schema cannot describe are pinned', function (string $object, string $column, array $expected) {
    $row = collect((new Catalogue)->columns($object))->firstWhere('column', $column);

    foreach ($expected as $key => $value) {
        expect($row[$key])->toBe($value, "{$object}.{$column} {$key}");
    }
})->with([
    'the record type is the wire t' => ['records', 'type', ['sql_type' => 'TEXT', 'wire' => 't', 'values' => null, 'nullable' => true, 'indexed' => true]],
    'the store id is a number and never null' => ['records', 'id', ['sql_type' => 'INTEGER', 'wire' => null, 'nullable' => false, 'indexed' => true]],
    'the start is the wire timestamp' => ['requests', 'started_at', ['sql_type' => 'REAL', 'wire' => 'timestamp', 'unit' => 'epoch_seconds', 'indexed' => true]],
    'the end is derived and not on the wire' => ['requests', 'ended_at', ['sql_type' => 'REAL', 'wire' => null, 'unit' => 'epoch_seconds', 'nullable' => true, 'indexed' => false]],
    'the generated end of the raw table is listed' => ['records', 'ended_at', ['sql_type' => 'REAL', 'wire' => null, 'indexed' => false]],
    'the cache event kind is the wire type' => ['cache_events', 'event', ['sql_type' => 'TEXT', 'wire' => 'type', 'values' => ['hit', 'miss', 'write', 'write-failure', 'delete', 'delete-failure']]],
    'an attempt\'s execution id is its own attempt id' => ['job_attempts', 'execution_id', ['sql_type' => 'TEXT', 'wire' => 'attempt_id', 'indexed' => true]],
    'a request\'s execution id is its trace id and not on the wire' => ['requests', 'execution_id', ['wire' => null, 'indexed' => true]],
    'a child\'s execution id is the wire\'s' => ['queries', 'execution_id', ['wire' => 'execution_id']],
    'a child\'s execution source reads the source column' => ['queries', 'execution_source', ['wire' => 'execution_source', 'values' => ['request', 'command', 'job', 'schedule']]],
    'an execution\'s source is its own kind' => ['requests', 'source', ['wire' => null, 'values' => ['request']]],
    'the group is the wire _group' => ['requests', 'group_hash', ['wire' => '_group', 'indexed' => true]],
    'the user is the wire user' => ['requests', 'user_id', ['wire' => 'user', 'indexed' => true]],
    'a JSON column reads as text' => ['requests', 'headers', ['sql_type' => 'TEXT', 'wire' => 'headers', 'indexed' => false]],
    'an integer field is an integer' => ['requests', 'status_code', ['sql_type' => 'INTEGER', 'wire' => 'status_code']],
    'a stage is microseconds' => ['requests', 'render', ['sql_type' => 'INTEGER', 'unit' => 'microseconds']],
    'a memory peak is bytes' => ['commands', 'peak_memory_usage', ['sql_type' => 'INTEGER', 'unit' => 'bytes']],
    'a cache lifetime is seconds' => ['cache_events', 'ttl', ['sql_type' => 'INTEGER', 'unit' => 'seconds']],
    'a boolean is 1 or 0' => ['mail', 'failed', ['sql_type' => 'INTEGER', 'values' => [0, 1]]],
    'a task outcome' => ['scheduled_tasks', 'status', ['values' => ['processed', 'failed', 'skipped']]],
    'a job outcome' => ['job_attempts', 'status', ['values' => ['processed', 'failed', 'released']]],
    'a log level' => ['logs', 'level', ['values' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']]],
    'a query connection kind' => ['queries', 'connection_type', ['values' => ['read', 'write', 'direct', '']]],
    'the bindings are added by Firewatch' => ['queries', 'bindings', ['sql_type' => 'TEXT', 'wire' => null]],
    'drift is keyed by kind first' => ['drift', 'kind', ['nullable' => false, 'indexed' => true, 'values' => ['unknown_type', 'unknown_version', 'unknown_field', 'missing_field', 'structure', 'version']]],
    'drift type alone is not indexed' => ['drift', 'type', ['nullable' => false, 'indexed' => false, 'wire' => null]],
    'the directory id is its key' => ['users', 'id', ['sql_type' => 'TEXT', 'nullable' => false, 'indexed' => true, 'wire' => 'id']],
    'the directory first seen is not a wire field' => ['users', 'first_seen', ['sql_type' => 'REAL', 'wire' => null, 'unit' => 'epoch_seconds']],
]);

test('the closed lists written out equal the ones Nightwatch sends', function () {
    $constructor = (new ReflectionClass(CacheEvent::class))->getConstructor();
    $cacheEvents = collect((new Catalogue)->columns('cache_events'))->firstWhere('column', 'event')['values'];
    $connectionTypes = collect((new Catalogue)->columns('queries'))->firstWhere('column', 'connection_type')['values'];
    $nightwatch = array_map(fn (QueryConnectionType $type) => $type->value, QueryConnectionType::cases());

    preg_match("/@param\s+'([^@]+)'\s+\\\$type/", (string) $constructor?->getDocComment(), $declared);

    expect($cacheEvents)->toBe(explode("'|'", $declared[1]))
        ->and($connectionTypes)->toBe([...array_values(array_diff($nightwatch, ['unknown'])), '']);
});
