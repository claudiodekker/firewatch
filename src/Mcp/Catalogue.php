<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\DriftKind;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Schema;
use SQLite3;
use SQLite3Result;

/**
 * The objects the SQL tool reads, as the shipped schema builds them, with what the schema can't say.
 *
 * The column list, order, SQL type, wire field, nullability and indexes are read from the schema built in memory,
 * never declared: a store that can be read has that very schema, and one that can't still has a catalogue.
 *
 * @internal
 */
class Catalogue
{
    /**
     * The most characters of an example value.
     */
    public const SAMPLE_CHARACTERS = 40;

    /**
     * The statements every answer without a type offers, by the language key of what each shows.
     */
    public const EXAMPLES = [
        'slowest_routes' => "SELECT route_path, count(*) AS requests, max(duration) AS max_us FROM requests WHERE started_at >= unixepoch('now') - 3600 GROUP BY route_path ORDER BY max_us DESC LIMIT 10",
        'exceptions_by_class' => 'SELECT class, count(*) AS occurrences, max(started_at) AS last_at FROM exceptions GROUP BY class ORDER BY occurrences DESC',
        'queries_per_execution' => 'SELECT execution_id, count(*) AS queries, sum(duration) AS total_us FROM queries GROUP BY execution_id ORDER BY queries DESC LIMIT 10',
        'execution_timeline' => 'SELECT type, started_at, duration FROM records WHERE execution_id = (SELECT execution_id FROM requests ORDER BY started_at DESC LIMIT 1) ORDER BY started_at, id',
        'header_names' => 'SELECT h.key AS header, count(*) AS requests FROM requests, json_each(requests.headers) AS h GROUP BY h.key ORDER BY requests DESC',
    ];

    /**
     * The statements the answer for a type offers, by type and the language key of what each shows.
     *
     * @var array<string, array<string, string>>
     */
    public const TYPE_EXAMPLES = [
        'request' => [
            'request_status' => 'SELECT status_code, count(*) AS requests FROM requests GROUP BY status_code ORDER BY status_code',
            'request_slowest' => 'SELECT method, url, duration, started_at FROM requests ORDER BY duration DESC LIMIT 10',
            'request_stages' => 'SELECT route_path, avg(bootstrap) AS bootstrap_us, avg(action) AS action_us, avg(render) AS render_us FROM requests GROUP BY route_path',
        ],
        'command' => [
            'command_exit_codes' => 'SELECT name, exit_code, count(*) AS runs FROM commands GROUP BY name, exit_code',
            'command_slowest' => 'SELECT command, duration, started_at FROM commands ORDER BY duration DESC LIMIT 10',
            'command_memory' => 'SELECT name, max(peak_memory_usage) AS peak_bytes FROM commands GROUP BY name ORDER BY peak_bytes DESC',
        ],
        'job-attempt' => [
            'job_attempt_outcomes' => 'SELECT name, status, count(*) AS attempts FROM job_attempts GROUP BY name, status',
            'job_attempt_wait' => 'SELECT a.name, a.attempt, a.started_at - q.started_at AS waited_s FROM job_attempts a JOIN queued_jobs q ON q.job_id = a.job_id ORDER BY waited_s DESC LIMIT 10',
            'job_attempt_slowest' => 'SELECT name, attempt, duration FROM job_attempts ORDER BY duration DESC LIMIT 10',
        ],
        'scheduled-task' => [
            'scheduled_task_status' => 'SELECT name, status, count(*) AS runs FROM scheduled_tasks GROUP BY name, status',
            'scheduled_task_slowest' => "SELECT name, cron, duration FROM scheduled_tasks WHERE status != 'skipped' ORDER BY duration DESC LIMIT 10",
            'scheduled_task_settings' => 'SELECT name, cron, timezone, without_overlapping, on_one_server FROM scheduled_tasks GROUP BY name',
        ],
        'query' => [
            'query_slowest' => 'SELECT sql, duration, file, line FROM queries ORDER BY duration DESC LIMIT 10',
            'query_repeated' => 'SELECT group_hash, sql, count(*) AS runs, sum(duration) AS total_us FROM queries GROUP BY group_hash ORDER BY total_us DESC LIMIT 10',
            'query_connections' => 'SELECT connection, connection_type, count(*) AS queries FROM queries GROUP BY connection, connection_type',
        ],
        'exception' => [
            'exception_places' => 'SELECT class, file, line, message FROM exceptions ORDER BY started_at DESC LIMIT 10',
            'exception_handled' => 'SELECT handled, count(*) AS exceptions FROM exceptions GROUP BY handled',
            'exception_frames' => 'SELECT class, json_array_length(trace) AS frames FROM exceptions ORDER BY frames DESC LIMIT 10',
        ],
        'log' => [
            'log_levels' => 'SELECT level, count(*) AS lines FROM logs GROUP BY level',
            'log_recent' => 'SELECT started_at, level, message FROM logs ORDER BY started_at DESC LIMIT 20',
            'log_context' => 'SELECT message, json_type(context) AS context_type FROM logs WHERE json_valid(context)',
        ],
        'cache-event' => [
            'cache_event_kinds' => 'SELECT store, event, count(*) AS events FROM cache_events GROUP BY store, event',
            'cache_event_keys' => "SELECT key, sum(event = 'hit') AS hits, sum(event = 'miss') AS misses FROM cache_events GROUP BY key ORDER BY misses DESC LIMIT 10",
            'cache_event_slowest' => 'SELECT key, event, duration FROM cache_events ORDER BY duration DESC LIMIT 10',
        ],
        'mail' => [
            'mail_by_class' => 'SELECT class, count(*) AS sent, sum(failed) AS failed FROM mail GROUP BY class',
            'mail_slowest' => 'SELECT class, subject, duration FROM mail ORDER BY duration DESC LIMIT 10',
            'mail_recent' => 'SELECT started_at, mailer, subject, attachments FROM mail ORDER BY started_at DESC LIMIT 10',
        ],
        'notification' => [
            'notification_by_channel' => 'SELECT channel, class, count(*) AS sent FROM notifications GROUP BY channel, class',
            'notification_slowest' => 'SELECT class, channel, duration FROM notifications ORDER BY duration DESC LIMIT 10',
            'notification_failed' => 'SELECT failed, count(*) AS notifications FROM notifications GROUP BY failed',
        ],
        'outgoing-request' => [
            'outgoing_by_host' => 'SELECT host, count(*) AS calls, avg(duration) AS avg_us FROM outgoing_requests GROUP BY host ORDER BY calls DESC',
            'outgoing_statuses' => 'SELECT host, method, status_code, count(*) AS calls FROM outgoing_requests GROUP BY host, method, status_code',
            'outgoing_slowest' => 'SELECT method, url, duration, status_code FROM outgoing_requests ORDER BY duration DESC LIMIT 10',
        ],
        'queued-job' => [
            'queued_job_dispatches' => 'SELECT name, queue, count(*) AS dispatched FROM queued_jobs GROUP BY name, queue',
            'queued_job_attempts' => 'SELECT q.name, count(a.id) AS attempts FROM queued_jobs q LEFT JOIN job_attempts a ON a.job_id = q.job_id GROUP BY q.job_id',
            'queued_job_connections' => 'SELECT connection, queue, count(*) AS dispatched FROM queued_jobs GROUP BY connection, queue',
        ],
        'user' => [
            'user_directory' => 'SELECT id, name, username, last_seen FROM users ORDER BY last_seen DESC LIMIT 10',
            'user_requests' => 'SELECT u.name, count(*) AS requests FROM users u JOIN requests r ON r.user_id = u.id GROUP BY u.id ORDER BY requests DESC',
            'user_span' => 'SELECT id, last_seen - first_seen AS seen_for_s FROM users ORDER BY seen_for_s DESC LIMIT 10',
        ],
    ];

    /**
     * The unit of every stored number that has one, by column name, in every object that has the column.
     */
    protected const UNITS = [
        'started_at' => Unit::EPOCH_SECONDS,
        'ended_at' => Unit::EPOCH_SECONDS,
        'first_seen' => Unit::EPOCH_SECONDS,
        'last_seen' => Unit::EPOCH_SECONDS,
        'duration' => Unit::MICROSECONDS,
        'bootstrap' => Unit::MICROSECONDS,
        'before_middleware' => Unit::MICROSECONDS,
        'action' => Unit::MICROSECONDS,
        'render' => Unit::MICROSECONDS,
        'after_middleware' => Unit::MICROSECONDS,
        'sending' => Unit::MICROSECONDS,
        'terminating' => Unit::MICROSECONDS,
        'peak_memory_usage' => Unit::BYTES,
        'request_size' => Unit::BYTES,
        'response_size' => Unit::BYTES,
        'ttl' => Unit::SECONDS,
        'repeat_seconds' => Unit::SECONDS,
    ];

    /**
     * The kinds of a cache event, as the wire spells them; no enum holds them.
     */
    protected const CACHE_EVENTS = ['hit', 'miss', 'write', 'write-failure', 'delete', 'delete-failure'];

    /**
     * The kinds of a query's connection, as the wire spells them; an unknown kind is sent empty.
     */
    protected const CONNECTION_TYPES = ['read', 'write', 'direct', ''];

    /**
     * The SQL type of a column read out of the JSON data, by the JSON type its contract field accepts.
     */
    protected const SQL_TYPES = [
        'integer' => 'INTEGER',
        'number' => 'REAL',
        'boolean' => 'INTEGER',
        'string' => 'TEXT',
        'array' => 'TEXT',
    ];

    /**
     * The in-memory database the shipped schema is built in.
     */
    protected SQLite3 $schema;

    /**
     * Create a new catalogue instance.
     */
    public function __construct()
    {
        $this->schema = new SQLite3(':memory:');

        foreach ((new Schema)->statements() as $statement) {
            $this->schema->exec($statement);
        }
    }

    /**
     * Get the object a type is described by: its record view, or the user directory.
     */
    public static function object(RecordType $type): string
    {
        return $type->view() ?? 'users';
    }

    /**
     * Get one row per object the SQL tool reads: its name, the type that describes it and what it holds.
     *
     * @return list<array{object: string, type: string|null, holds: string}>
     */
    public function objects(): array
    {
        $objects = array_values(array_diff(Policy::READABLE, ['json_each', 'json_tree']));

        return array_map(fn (string $object) => [
            'object' => $object,
            'type' => $this->described($object)?->value,
            'holds' => __("firewatch::messages.objects.{$object}"),
        ], $objects);
    }

    /**
     * Get the answer row of each column of an object, in schema order.
     *
     * @param  array<string, list<mixed>>  $samples  recent distinct values by column; none on a store that holds no records
     * @return list<array{column: string, sql_type: string, wire: string|null, unit: string|null, values: list<int|string>|null, nullable: bool, indexed: bool, examples: list<mixed>, meaning: string}>
     */
    public function columns(string $object, array $samples = []): array
    {
        $backing = $this->backing($object);
        $stored = array_column($this->schemaColumns($backing), null, 'name');
        $leading = $this->leading($backing, $stored);

        return array_map(function (array $column) use ($object, $stored, $leading, $samples) {
            $name = $column['name'];
            $field = $this->field($object, $name);
            $own = $stored[$name] ?? null;

            return [
                'column' => $name,
                'sql_type' => $column['type'] !== '' ? $column['type'] : self::SQL_TYPES[$field['type'] ?? ''] ?? 'TEXT',
                'wire' => $field['wire'] ?? null,
                'unit' => (self::UNITS[$name] ?? null)?->value,
                'values' => $this->values($object, $name, $field['type'] ?? null),
                'nullable' => $own === null || ! ($own['notnull'] || $own['pk']),
                'indexed' => in_array($name, $leading, true),
                'examples' => $samples[$name] ?? [],
                'meaning' => $this->meaning($object, $name),
            ];
        }, $this->schemaColumns($object));
    }

    /**
     * Get the columns of an object whose recent values are worth showing: never the store id, never a JSON value.
     *
     * @return list<string>
     */
    public function sampled(string $object): array
    {
        $names = array_column($this->schemaColumns($object), 'name');
        $types = $this->types($object);
        $json = array_merge(['data'], ...array_map(fn (RecordType $type) => [...$type->jsonFields(), ...$type->addedFields()], $types));

        $sampled = array_filter($names, fn (string $name) => ! in_array($name, $json, true)
            && ! ($name === 'id' && $object !== 'users')
            && ($this->field($object, $name)['type'] ?? null) !== 'array');

        return array_values($sampled);
    }

    /**
     * Get the statements of the answer without a type, or of one type, with what each shows.
     *
     * @return list<array{example: string, sql: string}>
     */
    public function examples(?RecordType $type = null): array
    {
        $statements = $type === null ? self::EXAMPLES : self::TYPE_EXAMPLES[$type->value];

        return array_map(
            fn (string $key, string $sql) => [
                'example' => __("firewatch::messages.describe_examples.{$key}"),
                'sql' => $sql,
            ],
            array_keys($statements),
            $statements,
        );
    }

    /**
     * Get the type an object describes, or null for the raw tables.
     */
    protected function described(string $object): ?RecordType
    {
        foreach (RecordType::cases() as $type) {
            if (self::object($type) === $object) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Get the table a view reads from.
     */
    protected function backing(string $object): string
    {
        return in_array($object, ['records', 'users', 'drift', 'meta'], true) ? $object : 'records';
    }

    /**
     * Get the record types whose contract feeds an object: a view's own, the directory's, every event for the raw records.
     *
     * @return list<RecordType>
     */
    protected function types(string $object): array
    {
        return $object === 'records' ? RecordType::events() : array_filter([$this->described($object)]);
    }

    /**
     * Get the columns of an object as the in-memory schema reports them, with the generated ones.
     *
     * @return list<array{name: string, type: string, notnull: int, pk: int}>
     */
    protected function schemaColumns(string $object): array
    {
        return array_map(fn (array $row) => [
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'notnull' => Cell::integer($row['notnull']),
            'pk' => Cell::integer($row['pk']),
        ], $this->pragma("table_xinfo({$object})"));
    }

    /**
     * Get the columns of a table that lead one of its indexes or its primary key.
     *
     * @param  array<string, array{name: string, type: string, notnull: int, pk: int}>  $stored
     * @return list<string>
     */
    protected function leading(string $table, array $stored): array
    {
        $leading = array_column(array_filter($stored, fn (array $column) => $column['pk'] === 1), 'name');

        foreach ($this->pragma("index_list({$table})") as $index) {
            $leading[] = (string) $this->pragma("index_info({$index['name']})")[0]['name'];
        }

        return $leading;
    }

    /**
     * Get the rows a pragma returns. The argument is a name of the shipped schema, never the caller's.
     *
     * @return list<array<string, mixed>>
     */
    protected function pragma(string $pragma): array
    {
        /** @var SQLite3Result $result */
        $result = $this->schema->query("PRAGMA {$pragma}");
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get the wire field a column of an object is stored from and the JSON type it accepts, or null for a column Firewatch adds or derives.
     *
     * A key stored under the column's own name wins (a child's `execution_id`, not an attempt's `attempt_id`).
     *
     * @return array{wire: string, type: string}|null
     */
    protected function field(string $object, string $column): ?array
    {
        $found = null;

        foreach ($this->types($object) as $type) {
            $fields = $type->fields();
            $keys = array_keys($fields, $column, true);
            $key = match (true) {
                in_array($column, $keys, true) => $column,
                $keys !== [] => $keys[0],
                array_key_exists($column, $fields) => $column,
                default => null,
            };

            if ($key !== null && ($found === null || $key === $column)) {
                $found = [
                    'wire' => $key,
                    'type' => $type->acceptedTypes()[$key][0],
                ];
            }
        }

        return $found;
    }

    /**
     * Get the closed values of a column, or null for an open one.
     *
     * @return list<int|string>|null
     */
    protected function values(string $object, string $column, ?string $accepts): ?array
    {
        $type = $this->described($object);
        $sources = array_values(array_filter(array_map(fn (RecordType $type) => $type->source(), RecordType::events())));

        return match (true) {
            $accepts === 'boolean' => [0, 1],
            $column === 'status' && $type !== null => array_map(fn (Outcome $outcome) => $outcome->value, Outcome::for($type)),
            $object === 'logs' && $column === 'level' => array_map(fn (LogLevel $level) => $level->value, LogLevel::cases()),
            $column === 'event' => self::CACHE_EVENTS,
            $column === 'connection_type' => self::CONNECTION_TYPES,
            $column === 'source' && $type?->source() !== null => [$type->source()],
            in_array($column, ['source', 'execution_source'], true) => $sources,
            $object === 'drift' && $column === 'kind' => array_map(fn (DriftKind $kind) => $kind->value, DriftKind::cases()),
            default => null,
        };
    }

    /**
     * Get the one-line meaning of a column: the object's own sentence where the name means something else there.
     */
    protected function meaning(string $object, string $column): string
    {
        $own = "firewatch::messages.column_meanings_in.{$object}.{$column}";

        return trans()->has($own) ? __($own) : __("firewatch::messages.column_meanings.{$column}");
    }
}
