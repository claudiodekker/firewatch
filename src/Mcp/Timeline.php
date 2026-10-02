<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class Timeline
{
    /**
     * Get the children as timeline rows in the order they came, with repeated identical queries collapsed into one row at the first of them.
     *
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    public static function of(array $children): array
    {
        $entries = [];
        $positions = [];
        $totals = [];

        foreach ($children as $child) {
            $key = self::key($child);

            if ($key !== null && array_key_exists($key, $positions)) {
                $position = $positions[$key];
                $entries[$position]['count']++;
                $totals[$position] = self::sum($totals[$position], self::duration($child));

                continue;
            }

            if ($key !== null) {
                $positions[$key] = count($entries);
            }

            $totals[] = self::duration($child);
            $entries[] = self::row($child);
        }

        return array_map(fn (array $entry, int $position) => self::collapsed($entry, $totals[$position]), $entries, array_keys($entries));
    }

    /**
     * Get the key repeated queries share: the same SQL, connection and location, whatever the bindings, or null for a child that is never collapsed.
     *
     * @param  array<string, mixed>  $child
     */
    protected static function key(array $child): ?string
    {
        if ($child['type'] !== RecordType::QUERY->value) {
            return null;
        }

        return json_encode([$child['sql'] ?? null, $child['connection'] ?? null, $child['file'] ?? null, $child['line'] ?? null], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the microseconds a child took, or null for one that has no duration.
     *
     * @param  array<string, mixed>  $child
     */
    protected static function duration(array $child): int|float|null
    {
        $duration = $child['duration'] ?? null;

        return is_int($duration) || is_float($duration) ? $duration : null;
    }

    /**
     * Get the sum of two durations that may be unknown, which is unknown only when both are.
     */
    protected static function sum(int|float|null $first, int|float|null $second): int|float|null
    {
        return $first === null && $second === null ? null : ($first ?? 0) + ($second ?? 0);
    }

    /**
     * Get the row of one child.
     *
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    protected static function row(array $child): array
    {
        $type = RecordType::from($child['type']);

        return [
            'started_at' => $child['started_at'],
            'type' => $type->value,
            'stage' => Stored::blank($child['execution_stage'] ?? null),
            'duration_ms' => Stored::milliseconds($child['duration'] ?? null),
            'name' => self::name($type, $child),
            'location' => Stored::location(file: $child['file'] ?? null, line: $child['line'] ?? null),
            'count' => 1,
            'total_ms' => null,
            'detail' => self::detail($type, $child),
        ];
    }

    /**
     * Get a row as it is listed: a single child has no count or total, and a repeated query no bindings, which may differ between its runs.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected static function collapsed(array $entry, int|float|null $microseconds): array
    {
        if ($entry['count'] === 1) {
            return [
                ...$entry,
                'count' => null,
            ];
        }

        unset($entry['detail']['bindings']);

        return [
            ...$entry,
            'total_ms' => Stored::milliseconds($microseconds),
        ];
    }

    /**
     * Get what names a child: the SQL, the class, the level, the key, the URL or the job.
     *
     * @param  array<string, mixed>  $child
     */
    protected static function name(RecordType $type, array $child): mixed
    {
        return match ($type) {
            RecordType::QUERY => $child['sql'] ?? null,
            RecordType::EXCEPTION, RecordType::MAIL, RecordType::NOTIFICATION => $child['class'] ?? null,
            RecordType::LOG => $child['level'] ?? null,
            RecordType::CACHE_EVENT => $child['key'] ?? null,
            RecordType::OUTGOING_REQUEST => $child['url'] ?? null,
            default => $child['name'] ?? null,
        };
    }

    /**
     * Get the fields of a child that its type adds to the row.
     *
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    protected static function detail(RecordType $type, array $child): array
    {
        return match ($type) {
            RecordType::QUERY => [
                'connection' => $child['connection'] ?? null,
                'connection_type' => $child['connection_type'] ?? null,
                'bindings' => Stored::json($child['bindings'] ?? null),
            ],
            RecordType::EXCEPTION => [
                'message' => $child['message'] ?? null,
                'handled' => Stored::flag($child['handled'] ?? null),
            ],
            RecordType::LOG => [
                'message' => $child['message'] ?? null,
            ],
            RecordType::CACHE_EVENT => [
                'event' => $child['event'] ?? null,
                'store' => $child['store'] ?? null,
                'ttl' => $child['ttl'] ?? null,
            ],
            RecordType::MAIL => [
                'mailer' => $child['mailer'] ?? null,
                'subject' => $child['subject'] ?? null,
                'to' => $child['to'] ?? null,
                'cc' => $child['cc'] ?? null,
                'bcc' => $child['bcc'] ?? null,
                'attachments' => $child['attachments'] ?? null,
                'failed' => Stored::flag($child['failed'] ?? null),
            ],
            RecordType::NOTIFICATION => [
                'channel' => $child['channel'] ?? null,
                'failed' => Stored::flag($child['failed'] ?? null),
            ],
            RecordType::OUTGOING_REQUEST => [
                'method' => $child['method'] ?? null,
                'host' => $child['host'] ?? null,
                'status_code' => $child['status_code'] ?? null,
                'request_size_bytes' => $child['request_size'] ?? null,
                'response_size_bytes' => $child['response_size'] ?? null,
            ],
            default => [
                'job_id' => $child['job_id'] ?? null,
                'connection' => $child['connection'] ?? null,
                'queue' => $child['queue'] ?? null,
            ],
        };
    }
}
