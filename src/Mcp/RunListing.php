<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\TestRecord;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class RunListing
{
    /**
     * Create a new run listing instance.
     */
    public function __construct(
        protected Window $window,
    ) {
        //
    }

    /**
     * Read the executions and between sections that start in the window, each with all its records counted, oldest first and one more than the limit.
     *
     * @param  array{started_at: int|float, execution_id: string}|null  $after
     * @return array{rows: list<array<string, mixed>>, keys: list<array{started_at: int|float, execution_id: string}>}
     */
    public function rows(SQLite3 $connection, int $limit, ?array $after = null): array
    {
        $executions = implode(', ', array_map(fn (RecordType $type) => "'{$type->value}'", ExecutionType::records()));
        $query = RecordType::QUERY->value;
        $continued = $after === null ? '1' : '(started_at > :after_at OR (started_at = :after_at AND execution_id > :after_id))';
        $bindings = $after === null ? [] : [
            'after_at' => $after['started_at'],
            'after_id' => $after['execution_id'],
        ];

        $units = Stored::rows($connection, "WITH anchors AS ({$this->anchors($executions)}),
            page AS (SELECT * FROM anchors WHERE {$continued} ORDER BY started_at, execution_id LIMIT :fetch)
            SELECT page.id, page.execution_id, page.started_at, executions.type, executions.duration, executions.data,
                count(*) FILTER (WHERE records.type = '{$query}') AS queries,
                sum(records.duration) FILTER (WHERE records.type = '{$query}') AS query_duration,
                count(*) FILTER (WHERE records.type IS NULL OR records.type NOT IN ('{$query}', {$executions})) AS other
            FROM page
            JOIN records ON records.execution_id = page.execution_id
            LEFT JOIN records AS executions ON executions.id = page.id
            GROUP BY page.started_at, page.execution_id, page.id
            ORDER BY page.started_at, page.execution_id", [
            ...$bindings,
            'fetch' => Rows::fetch($limit),
        ], $this->window);

        return [
            'rows' => array_map($this->row(...), $units),
            'keys' => array_map(fn (array $unit) => [
                'started_at' => $unit['started_at'],
                'execution_id' => $unit['execution_id'],
            ], $units),
        ];
    }

    /**
     * Get the SQL of where each execution and each between section on the window's side starts, the first by its own record and the second by its first.
     *
     * @param  string  $executions  the execution types, quoted and separated by commas
     */
    protected function anchors(string $executions): string
    {
        $bounds = $this->window->scoped(Scope::ALL)->condition();
        $between = Scope::BETWEEN->condition();
        $branches = [];

        if ($this->window->scope() !== Scope::BETWEEN) {
            $branches[] = "SELECT id, execution_id, started_at FROM records WHERE type IN ({$executions}) AND execution_id IS NOT NULL AND {$bounds}";
        }

        if ($this->window->scope() !== Scope::EXECUTIONS) {
            $branches[] = "SELECT NULL AS id, execution_id, started_at FROM (SELECT execution_id, min(started_at) AS started_at FROM records WHERE {$between} AND execution_id IS NOT NULL GROUP BY execution_id) WHERE {$bounds}";
        }

        return implode(' UNION ALL ', $branches);
    }

    /**
     * Build the row of an execution or a between section.
     *
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    protected function row(array $unit): array
    {
        $type = RecordType::tryFrom($unit['type'] ?? '');

        /** @var array<string, mixed> $data */
        $data = $type === null ? [] : json_decode($unit['data'], true, flags: JSON_THROW_ON_ERROR);

        return [
            'started_at' => $unit['started_at'],
            'source' => $type === null ? TestRecord::BETWEEN_SOURCE : $type->source(),
            'type' => $type?->value,
            'execution_id' => $unit['execution_id'],
            'label' => $type?->labelOf($data),
            'outcome' => $type === null ? null : ExecutionHeader::outcome($type, $data),
            'duration_ms' => Stored::milliseconds($unit['duration']),
            'queries' => $unit['queries'],
            'query_ms' => Stored::milliseconds($unit['query_duration']),
            'other' => $unit['other'],
        ];
    }
}
