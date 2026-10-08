<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
enum Measure: string
{
    case P95_DURATION = 'p95_duration';
    case P50_DURATION = 'p50_duration';
    case MAX_DURATION = 'max_duration';
    case TOTAL_DURATION = 'total_duration';
    case OCCURRENCES = 'occurrences';
    case P95_MEMORY = 'p95_memory';
    case P50_MEMORY = 'p50_memory';
    case MAX_MEMORY = 'max_memory';
    case LAST_SEEN = 'last_seen';
    case QUERIES = 'queries';

    /**
     * The four execution types.
     *
     * @var list<RecordType>
     */
    protected const EXECUTIONS = [RecordType::REQUEST, RecordType::COMMAND, RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK];

    /**
     * Get the types that have groups to rank.
     *
     * @return list<RecordType>
     */
    public static function types(): array
    {
        return array_values(array_filter(RecordType::events(), fn (RecordType $type) => $type !== RecordType::LOG));
    }

    /**
     * Get the measures a type is ranked by, in the order they are listed.
     *
     * @return list<self>
     */
    public static function for(RecordType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $measure) => $measure !== self::P50_MEMORY && $measure->fits($type)));
    }

    /**
     * Get the measure a type is ranked by when none is asked for.
     */
    public static function default(RecordType $type): self
    {
        return $type === RecordType::EXCEPTION ? self::OCCURRENCES : self::P95_DURATION;
    }

    /**
     * Determine if the measure fits a type.
     */
    public function fits(RecordType $type): bool
    {
        return match ($this) {
            self::OCCURRENCES, self::LAST_SEEN => true,
            self::P95_MEMORY, self::P50_MEMORY, self::MAX_MEMORY, self::QUERIES => in_array($type, self::EXECUTIONS, true),
            default => $type !== RecordType::EXCEPTION,
        };
    }

    /**
     * Get the measure a percentile falls back to when no group has enough records for it, or null for one that is no percentile.
     */
    public function fallback(): ?self
    {
        return match ($this) {
            self::P95_DURATION, self::P50_DURATION => self::MAX_DURATION,
            self::P95_MEMORY, self::P50_MEMORY => self::MAX_MEMORY,
            default => null,
        };
    }

    /**
     * Get the name of the percentile and the records it needs, or null for a measure that is none.
     *
     * @return array{string, int}|null
     */
    public function floor(): ?array
    {
        return match ($this) {
            self::P95_DURATION => ['p95', Ranking::P95_FLOOR],
            self::P50_DURATION => ['p50', Ranking::P50_FLOOR],
            self::P95_MEMORY => ['p95 memory', Ranking::P95_FLOOR],
            self::P50_MEMORY => ['p50 memory', Ranking::P50_FLOOR],
            default => null,
        };
    }
}
