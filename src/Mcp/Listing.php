<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Listing
{
    /**
     * The most call sites a query group lists.
     */
    protected const CALL_SITES = 20;

    /**
     * Create a new listing instance.
     *
     * @param  array{int, int}|null  $status  the lowest and the highest status code that is kept
     */
    public function __construct(
        public readonly Window $window,
        public readonly Order $order,
        public readonly ?string $group = null,
        public readonly ?RecordType $type = null,
        public readonly ?string $executionId = null,
        public readonly ?string $traceId = null,
        public readonly ?string $jobId = null,
        public readonly ?string $userId = null,
        public readonly ?string $deploy = null,
        public readonly ?string $method = null,
        public readonly ?array $status = null,
        public readonly ?Outcome $outcome = null,
        public readonly ?LogLevel $level = null,
        public readonly int|float|null $slowerThanMilliseconds = null,
        public readonly ?string $matching = null,
    ) {
        //
    }

    /**
     * Read the record types a group is held by.
     *
     * @return list<RecordType>
     */
    public static function typesOf(SQLite3 $connection, string $group): array
    {
        $rows = self::run($connection, 'SELECT DISTINCT type FROM records WHERE group_hash = :group ORDER BY type', [':group' => $group]);

        return array_values(array_filter(array_map(fn (array $row) => RecordType::tryFrom($row['type']), $rows)));
    }

    /**
     * Get the fields of a type a `matching` is looked for in, in the order the first one that matches is named.
     *
     * @return list<string>
     */
    protected static function matchedFields(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => ['route_path', 'route_name', 'route_action', 'url'],
            RecordType::COMMAND => ['name', 'command'],
            RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK, RecordType::QUEUED_JOB => ['name'],
            RecordType::QUERY => ['sql'],
            RecordType::EXCEPTION => ['class', 'message', 'file'],
            RecordType::LOG => ['message'],
            RecordType::CACHE_EVENT => ['key'],
            RecordType::OUTGOING_REQUEST => ['host', 'url'],
            RecordType::MAIL => ['class', 'subject'],
            RecordType::NOTIFICATION => ['class'],
            RecordType::USER => [],
        };
    }

    /**
     * Read the baseline of the selection.
     *
     * @return array{samples: int, needed: int, threshold: int|float|null}
     */
    public function baseline(SQLite3 $connection, Percentile $percentile): array
    {
        [$selection, $bindings] = $this->selection();
        $needed = $percentile->floor();
        $duration = Stored::number('duration');

        $samples = self::run($connection, "SELECT count({$duration}) AS samples FROM records WHERE {$selection}", $bindings, $this->window)[0]['samples'];

        if ($samples < $needed) {
            return [
                'samples' => $samples,
                'needed' => $needed,
                'threshold' => null,
            ];
        }

        $bindings[':offset'] = intdiv($samples * $percentile->share() + Ranking::PERCENT - 1, Ranking::PERCENT) - 1;
        $row = self::run($connection, "SELECT {$duration} AS duration FROM records WHERE {$selection} AND {$duration} IS NOT NULL ORDER BY {$duration} LIMIT 1 OFFSET :offset", $bindings, $this->window)[0];

        return [
            'samples' => $samples,
            'needed' => $needed,
            'threshold' => $row['duration'],
        ];
    }

    /**
     * Read the first rows of the selection that the filters keep, one more than the limit, with the key of each in its order.
     *
     * @param  array{value: int|float, id: int}|null  $after
     * @return array{rows: list<array<string, mixed>>, keys: list<array{value: int|float, id: int}>}
     */
    public function rows(SQLite3 $connection, int $limit, int|float|null $threshold, ?array $after = null): array
    {
        [$where, $bindings] = $this->where($threshold);
        $key = $this->order->sortKey();
        $bindings[':fetch'] = Rows::fetch($limit);

        if ($after !== null) {
            $where .= " AND ({$key} < :after_value OR ({$key} = :after_value AND id < :after_id))";
            $bindings[':after_value'] = $after['value'];
            $bindings[':after_id'] = $after['id'];
        }

        $records = self::run($connection, "SELECT id, type, started_at, duration, source, execution_id, trace_id, group_hash, job_id, user_id, deploy, data, {$key} AS sort_key FROM records WHERE {$where} ORDER BY {$key} DESC, id DESC LIMIT :fetch", $bindings, $this->window);

        return [
            'rows' => array_map($this->row(...), $records),
            'keys' => array_map(fn (array $record) => [
                'value' => $record['sort_key'],
                'id' => $record['id'],
            ], $records),
        ];
    }

    /**
     * Read the distinct call sites of the records the filters keep, most frequent first.
     *
     * @return list<array{location: string|null, count: int}>
     */
    public function callSites(SQLite3 $connection, int|float|null $threshold): array
    {
        [$where, $bindings] = $this->where($threshold);
        $bindings[':limit'] = self::CALL_SITES;

        $sites = self::run($connection, "SELECT json_extract(data, '\$.file') AS file, json_extract(data, '\$.line') AS line, count(*) AS count FROM records WHERE {$where} AND json_type(data, '\$.file') = 'text' GROUP BY file, line ORDER BY count DESC, file, line LIMIT :limit", $bindings, $this->window);

        return array_map(fn (array $site) => [
            'location' => Stored::location($site['file'], $site['line']),
            'count' => $site['count'],
        ], $sites);
    }

    /**
     * Get the condition and bindings of the selectors, the window and the deploy.
     *
     * @return array{string, array<string, string|int|float>}
     */
    protected function selection(): array
    {
        $conditions = [$this->window->condition()];
        $bindings = [];

        $selectors = [
            'group_hash' => $this->group,
            'execution_id' => $this->executionId,
            'trace_id' => $this->traceId,
            'job_id' => $this->jobId,
            'user_id' => $this->userId,
            'deploy' => $this->deploy,
            'type' => $this->type?->value,
        ];

        foreach ($selectors as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = :{$column}";
                $bindings[":{$column}"] = $value;
            }
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Get the condition and bindings of the selection and the filters on top of it.
     *
     * @return array{string, array<string, string|int|float>}
     */
    protected function where(int|float|null $threshold): array
    {
        [$selection, $bindings] = $this->selection();
        $conditions = [$selection];
        $duration = Stored::number('duration');

        if ($this->method !== null) {
            $conditions[] = "upper(json_extract(data, '\$.method')) = upper(:method)";
            $bindings[':method'] = $this->method;
        }

        if ($this->status !== null) {
            $conditions[] = "json_extract(data, '\$.status_code') BETWEEN :status_from AND :status_to";
            [$bindings[':status_from'], $bindings[':status_to']] = $this->status;
        }

        if ($this->outcome !== null) {
            $conditions[] = "json_extract(data, '\$.status') = :outcome";
            $bindings[':outcome'] = $this->outcome->value;
        }

        if ($this->level !== null) {
            $names = [];

            foreach ($this->level->andWorse() as $at => $level) {
                $names[] = ":level{$at}";
                $bindings[":level{$at}"] = $level;
            }

            $conditions[] = "lower(json_extract(data, '\$.level')) IN (".implode(', ', $names).')';
        }

        if ($this->slowerThanMilliseconds !== null) {
            $conditions[] = "{$duration} > :slower";
            $bindings[':slower'] = $this->slowerThanMilliseconds * Microseconds::PER_MILLISECOND;
        }

        if ($threshold !== null) {
            $conditions[] = "{$duration} >= :threshold";
            $bindings[':threshold'] = $threshold;
        }

        if ($this->matching !== null && $this->type !== null) {
            $fields = array_map(fn (string $field) => "instr(lower(COALESCE(json_extract(data, '\$.{$field}'), '')), lower(:matching)) > 0", self::matchedFields($this->type));
            $conditions[] = '('.implode(' OR ', $fields).')';
            $bindings[':matching'] = $this->matching;
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Build the row of a record.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function row(array $record): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($record['data'], true, flags: JSON_THROW_ON_ERROR);
        $type = RecordType::tryFrom($record['type'] ?? '');
        $located = in_array($type, [RecordType::QUERY, RecordType::EXCEPTION], true);

        $row = [
            'started_at' => $record['started_at'],
            'type' => $record['type'],
            'source' => $record['source'],
            'stage' => Stored::blank($data['execution_stage'] ?? null),
            'duration_ms' => Stored::milliseconds($record['duration']),
            'execution_id' => $record['execution_id'],
            'trace_id' => $record['trace_id'],
            'group' => $record['group_hash'],
            'name' => $this->name($type, $data),
            'location' => $located ? Stored::location($data['file'] ?? null, $data['line'] ?? null) : null,
            'user_id' => Stored::blank($record['user_id']),
            'deploy' => Stored::blank($record['deploy']),
        ];

        if ($this->matching !== null && $type !== null) {
            $row['matched_on'] = $this->matchedOn($type, $data, $this->matching);
        }

        $row['detail'] = $this->detail($type, $record, $data);

        return $row;
    }

    /**
     * Get the label of the group a record belongs to, or null for a type that has none or that is unknown.
     *
     * @param  array<string, mixed>  $data
     */
    protected function name(?RecordType $type, array $data): ?string
    {
        $name = match ($type) {
            null, RecordType::LOG => null,
            RecordType::REQUEST => $data['route_path'] ?? null,
            RecordType::QUERY => $data['sql'] ?? null,
            RecordType::OUTGOING_REQUEST => $data['host'] ?? null,
            RecordType::CACHE_EVENT => $data['key'] ?? null,
            RecordType::EXCEPTION, RecordType::MAIL, RecordType::NOTIFICATION => $data['class'] ?? null,
            default => $data['name'] ?? null,
        };

        return $type === RecordType::REQUEST && ($name === null || $name === '') ? __('firewatch::messages.rank_no_route') : $name;
    }

    /**
     * Get the first field of the type, in the order they are listed, that holds the matching.
     *
     * @param  array<string, mixed>  $data
     */
    protected function matchedOn(RecordType $type, array $data, string $matching): ?string
    {
        foreach (self::matchedFields($type) as $field) {
            if (is_string($data[$field] ?? null) && str_contains(strtolower($data[$field]), strtolower($matching))) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get the fields that are particular to the type of a record.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function detail(?RecordType $type, array $record, array $data): array
    {
        $memory = Stored::megabytes($data['peak_memory_usage'] ?? null);

        return match ($type) {
            RecordType::REQUEST => [
                'method' => $data['method'] ?? null,
                'url' => $data['url'] ?? null,
                'status_code' => $data['status_code'] ?? null,
                'queries' => $data['queries'] ?? null,
                'memory_mb' => $memory,
            ],
            RecordType::COMMAND => [
                'command' => $data['command'] ?? null,
                'exit_code' => $data['exit_code'] ?? null,
                'queries' => $data['queries'] ?? null,
                'memory_mb' => $memory,
            ],
            RecordType::JOB_ATTEMPT => [
                'job_id' => $record['job_id'],
                'attempt' => $data['attempt'] ?? null,
                'status' => $data['status'] ?? null,
                'queue' => $data['queue'] ?? null,
                'connection' => $data['connection'] ?? null,
                'queries' => $data['queries'] ?? null,
                'memory_mb' => $memory,
            ],
            RecordType::SCHEDULED_TASK => [
                'cron' => $data['cron'] ?? null,
                'status' => $data['status'] ?? null,
                'queries' => $data['queries'] ?? null,
                'memory_mb' => $memory,
            ],
            RecordType::QUERY => [
                'sql' => $data['sql'] ?? null,
                'connection' => $data['connection'] ?? null,
                'bindings' => $data['bindings'] ?? null,
            ],
            RecordType::EXCEPTION => [
                'message' => $data['message'] ?? null,
                'handled' => $data['handled'] ?? null,
                'code' => $data['code'] ?? null,
            ],
            RecordType::LOG => [
                'level' => $data['level'] ?? null,
                'message' => $data['message'] ?? null,
            ],
            RecordType::CACHE_EVENT => [
                'store' => $data['store'] ?? null,
                'key' => $data['key'] ?? null,
                'event' => $data['event'] ?? null,
            ],
            RecordType::MAIL => [
                'mailer' => $data['mailer'] ?? null,
                'subject' => $data['subject'] ?? null,
            ],
            RecordType::NOTIFICATION => ['channel' => $data['channel'] ?? null],
            RecordType::OUTGOING_REQUEST => [
                'host' => $data['host'] ?? null,
                'method' => $data['method'] ?? null,
                'url' => $data['url'] ?? null,
                'status_code' => $data['status_code'] ?? null,
                'response_size_bytes' => $data['response_size'] ?? null,
            ],
            RecordType::QUEUED_JOB => [
                'job_id' => $record['job_id'],
                'connection' => $data['connection'] ?? null,
                'queue' => $data['queue'] ?? null,
            ],
            null, RecordType::USER => [],
        };
    }

    /**
     * Run a query and read all its rows.
     *
     * @param  array<string, string|int|float>  $bindings
     * @return list<array<string, mixed>>
     */
    protected static function run(SQLite3 $connection, string $sql, array $bindings, ?Window $window = null): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        foreach ($bindings as $name => $value) {
            $statement->bindValue($name, $value, match (true) {
                is_int($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                default => SQLITE3_TEXT,
            });
        }

        $window?->bind($statement);

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $rows = [];

        while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }
}
