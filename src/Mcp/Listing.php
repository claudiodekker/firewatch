<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use SQLite3;

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
     */
    public function __construct(
        public readonly Window $window,
        public readonly Order $order,
        public readonly OccurrenceFilters $filters,
        public readonly ?string $group = null,
        public readonly ?RecordType $type = null,
        public readonly ?string $executionId = null,
        public readonly ?string $traceId = null,
        public readonly ?string $jobId = null,
        public readonly ?string $userId = null,
        public readonly ?string $worker = null,
        public readonly ?string $deploy = null,
    ) {
        //
    }

    /**
     * Read the baseline of the selection.
     *
     * @return array{samples: int, needed: int, threshold: int|float|null, durations: list<int|float>|null}
     */
    public function baseline(SQLite3 $connection, Percentile $percentile): array
    {
        [$selection, $bindings] = $this->selection();
        $needed = $percentile->floor();
        $duration = Stored::number('duration');

        $samples = Stored::rows($connection, "SELECT count({$duration}) AS samples FROM records WHERE {$selection}", $bindings, $this->window)[0]['samples'];

        if ($samples < $needed) {
            $durations = Stored::rows($connection, "SELECT {$duration} AS duration FROM records WHERE {$selection} AND {$duration} IS NOT NULL ORDER BY {$duration}", $bindings, $this->window);

            return [
                'samples' => $samples,
                'needed' => $needed,
                'threshold' => null,
                'durations' => array_column($durations, 'duration'),
            ];
        }

        $bindings['offset'] = intdiv($samples * $percentile->share() + Percentile::PERCENT - 1, Percentile::PERCENT) - 1;
        $row = Stored::rows($connection, "SELECT {$duration} AS duration FROM records WHERE {$selection} AND {$duration} IS NOT NULL ORDER BY {$duration} LIMIT 1 OFFSET :offset", $bindings, $this->window)[0];

        return [
            'samples' => $samples,
            'needed' => $needed,
            'threshold' => $row['duration'],
            'durations' => null,
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
        $bindings['fetch'] = Rows::fetch($limit);

        if ($after !== null) {
            $where .= " AND ({$key} < :after_value OR ({$key} = :after_value AND id < :after_id))";
            $bindings['after_value'] = $after['value'];
            $bindings['after_id'] = $after['id'];
        }

        $records = Stored::rows($connection, "SELECT id, type, started_at, duration, source, execution_id, trace_id, group_hash, job_id, user_id, deploy, data, {$key} AS sort_key FROM records WHERE {$where} ORDER BY {$key} DESC, id DESC LIMIT :fetch", $bindings, $this->window);

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
        $bindings['limit'] = self::CALL_SITES;

        $sites = Stored::rows($connection, "SELECT json_extract(data, '\$.file') AS file, json_extract(data, '\$.line') AS line, count(*) AS count FROM records WHERE {$where} AND json_type(data, '\$.file') = 'text' GROUP BY file, line ORDER BY count DESC, file, line LIMIT :limit", $bindings, $this->window);

        return array_map(fn (array $site) => [
            'location' => Stored::location($site['file'], $site['line']),
            ...CompiledView::of($site['file']),
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
            'worker' => $this->worker,
            'deploy' => $this->deploy,
            'type' => $this->type?->value,
        ];

        foreach ($selectors as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = :{$column}";
                $bindings[$column] = $value;
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

        [$filtered, $filterBindings] = $this->filters->condition($this->type);

        if ($filtered !== '') {
            $conditions[] = $filtered;
        }

        $bindings = [...$bindings, ...$filterBindings];

        if ($threshold !== null) {
            $conditions[] = "{$duration} >= :threshold";
            $bindings['threshold'] = $threshold;
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
            'stage' => Cell::blank($data['execution_stage'] ?? null),
            'duration_ms' => Stored::milliseconds($record['duration']),
            'execution_id' => $record['execution_id'],
            'trace_id' => $record['trace_id'],
            'group' => $record['group_hash'],
            'name' => $this->name($type, $data),
            'location' => $located ? Stored::location($data['file'] ?? null, $data['line'] ?? null) : null,
            ...($located ? CompiledView::of($data['file'] ?? null) : []),
            'user_id' => Cell::blank($record['user_id']),
            'deploy' => Cell::blank($record['deploy']),
        ];

        if ($this->filters->matching !== null && $type !== null) {
            $row['matched_on'] = $this->filters->matchedOn($type, $data);
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
        if ($type === null || $type === RecordType::LOG) {
            return null;
        }

        return $type->labelOf($data);
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
}
