<?php

namespace ClaudioDekker\Firewatch\Capture;

use Illuminate\Database\Events\QueryExecuted;
use Stringable;
use Throwable;

/**
 * @internal
 */
class QueryBindings
{
    /**
     * The most bytes a query's bindings keep as JSON, the count of those left out included.
     */
    public const TOTAL_LIMIT_BYTES = 16_384;

    /**
     * The most bytes one string binding keeps, truncation marker included.
     */
    public const VALUE_LIMIT_BYTES = 1_024;

    /**
     * The queries whose QueryExecuted event is being dispatched, innermost last.
     *
     * @var list<array{sql: string, connection: string, bindings: list<mixed>|null}>
     */
    protected array $pending = [];

    /**
     * Create a new query bindings instance.
     */
    public function __construct(protected Truncator $truncator)
    {
        //
    }

    /**
     * Hold a query's bindings while Nightwatch decides whether to record it.
     */
    public function capture(QueryExecuted $event): void
    {
        $this->pending[] = [
            'sql' => $event->sql,
            'connection' => $event->connectionName,
            'bindings' => $this->bindings($event),
        ];
    }

    /**
     * Forget the innermost query once Nightwatch recorded it or passed it by.
     */
    public function release(): void
    {
        array_pop($this->pending);
    }

    /**
     * Get the bindings of the query a wire record was written for, or null unless the record's SQL and connection are the innermost query's.
     *
     * @param  array<mixed>  $record
     * @return list<mixed>|null
     */
    public function pair(array $record): ?array
    {
        $index = array_key_last($this->pending);

        if ($index === null) {
            return null;
        }

        $query = $this->pending[$index];

        if (($record['sql'] ?? null) !== $query['sql'] || ($record['connection'] ?? null) !== $query['connection']) {
            return null;
        }

        return $query['bindings'];
    }

    /**
     * Get the values a query sent to its database, capped, or null when they can't be read.
     *
     * @return list<mixed>|null
     */
    protected function bindings(QueryExecuted $event): ?array
    {
        // A value that fails to read leaves the query unpaired rather than failing the application's query.
        try {
            $values = array_values($event->connection->prepareBindings($event->bindings));

            return $this->fit(array_map($this->value(...), $values));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get one binding as it is stored.
     */
    protected function value(mixed $value): mixed
    {
        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return match (true) {
            is_string($value) && ! mb_check_encoding($value, 'UTF-8') => '[binary '.strlen($value).' bytes]',
            is_string($value) => $this->truncator->cut($value, static::VALUE_LIMIT_BYTES),
            is_float($value) && ! is_finite($value) => (string) $value,
            is_scalar($value), $value === null => $value,
            default => '['.get_debug_type($value).']',
        };
    }

    /**
     * Keep the bindings that fit the total limit, replacing the rest with their count.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    protected function fit(array $values): array
    {
        // A JSON list is its brackets plus each value and the comma before all but the first.
        $bytes = 1;
        $sizes = [];

        foreach ($values as $value) {
            $bytes += strlen($this->encode($value)) + 1;
            $sizes[] = $bytes;

            if ($bytes > static::TOTAL_LIMIT_BYTES) {
                break;
            }
        }

        if ($bytes <= static::TOTAL_LIMIT_BYTES) {
            return $values;
        }

        for ($kept = count($sizes) - 1; $kept > 0; $kept--) {
            $marker = $this->marker(count($values) - $kept);

            if (static::TOTAL_LIMIT_BYTES >= $sizes[$kept - 1] + strlen($this->encode($marker)) + 1) {
                break;
            }
        }

        return [...array_slice($values, 0, $kept), $this->marker(count($values) - $kept)];
    }

    /**
     * Get the element that stands for the bindings left out.
     */
    protected function marker(int $count): string
    {
        return "... [{$count} more bindings]";
    }

    /**
     * Encode one binding as its stored JSON.
     */
    protected function encode(mixed $value): string
    {
        return json_encode($value, RecordMapper::JSON_FLAGS);
    }
}
