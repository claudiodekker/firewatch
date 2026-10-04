<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum Order: string
{
    case RECENT = 'recent';
    case SLOWEST = 'slowest';
    case MEMORY = 'memory';
    case QUERIES = 'queries';

    /**
     * Get the sort key of the order, which a record without the measure sorts last by.
     */
    public function sortKey(): string
    {
        return match ($this) {
            self::RECENT => 'COALESCE(started_at, -1)',
            self::SLOWEST => 'COALESCE('.Stored::number('duration').', -1)',
            self::MEMORY => 'COALESCE('.Stored::number("json_extract(data, '$.peak_memory_usage')").', -1)',
            self::QUERIES => 'COALESCE('.Stored::number("json_extract(data, '$.queries')").', -1)',
        };
    }
}
