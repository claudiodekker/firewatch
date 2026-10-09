<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;

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
     * The noise floor of a memory measure in bytes, because the runtime reports peak memory in steps of 2 MiB.
     */
    protected const MEMORY_NOISE_FLOOR = 2 * Ranking::MEGABYTE;

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
     * Get the measures a type is compared by, in the order they are listed.
     *
     * @return list<self>
     */
    public static function compared(RecordType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $measure) => $measure->movement() !== null && $measure->fits($type)));
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
     * Get what the change rule needs of the measure, or null for one that is never compared.
     *
     * @return array{int, Change, Change}|null the noise floor in the unit the store holds, then the changes of a rise and of a fall
     */
    public function movement(): ?array
    {
        return match ($this) {
            self::P95_DURATION, self::P50_DURATION, self::MAX_DURATION, self::TOTAL_DURATION => [Microseconds::PER_MILLISECOND, Change::SLOWER, Change::FASTER],
            self::P95_MEMORY, self::P50_MEMORY, self::MAX_MEMORY => [self::MEMORY_NOISE_FLOOR, Change::HEAVIER, Change::LIGHTER],
            self::OCCURRENCES, self::QUERIES => [1, Change::MORE_CALLS, Change::FEWER_CALLS],
            self::LAST_SEEN => null,
        };
    }

    /**
     * Determine if the measure grows with how long a side observed, so that it is judged only between sides of like spans.
     */
    public function isVolume(): bool
    {
        return in_array($this, [self::OCCURRENCES, self::TOTAL_DURATION, self::QUERIES], true);
    }

    /**
     * Get the percentile the measure is, or null for one that is none.
     */
    public function percentile(): ?Percentile
    {
        return match ($this) {
            self::P95_DURATION, self::P95_MEMORY => Percentile::P95,
            self::P50_DURATION, self::P50_MEMORY => Percentile::MEDIAN,
            default => null,
        };
    }

    /**
     * Get the median a 95th percentile steps down to when a side has too few records for it, or null for a measure that does not step down.
     */
    public function stepDown(): ?self
    {
        return match ($this) {
            self::P95_DURATION => self::P50_DURATION,
            self::P95_MEMORY => self::P50_MEMORY,
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

    /**
     * Get the suffix of the fields that carry the measure: its unit, or the name of the count.
     */
    public function unit(): string
    {
        return match ($this) {
            self::P95_DURATION, self::P50_DURATION, self::MAX_DURATION, self::TOTAL_DURATION => '_ms',
            self::P95_MEMORY, self::P50_MEMORY, self::MAX_MEMORY => '_mb',
            default => '_'.$this->value,
        };
    }

    /**
     * Get a stored value of the measure as an answer writes it.
     */
    public function shown(int|float|null $value): int|float|null
    {
        return match ($this->unit()) {
            '_ms' => Stored::milliseconds($value),
            '_mb' => Stored::megabytes($value),
            default => $value,
        };
    }
}
