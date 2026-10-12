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
    case RUN = 'run';

    /**
     * Get the sort key of the order: a run sorts what it lists by when each started, as recent sorts records.
     */
    public function sortKey(): string
    {
        return match ($this) {
            self::RECENT, self::RUN => 'COALESCE(started_at, -1)',
            self::SLOWEST => 'COALESCE('.Stored::number('duration').', -1)',
            self::MEMORY => 'COALESCE('.Stored::number("json_extract(data, '$.peak_memory_usage')").', -1)',
            self::QUERIES => 'COALESCE('.Stored::number("json_extract(data, '$.queries')").', -1)',
        };
    }
}
