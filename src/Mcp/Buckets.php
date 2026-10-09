<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Microseconds;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-type Bucket array{index: int, since: float, until: float, value: int|float|null, samples: int, partial: bool}
 */
class Buckets
{
    /**
     * The valued buckets a direction needs.
     */
    public const DIRECTION_NEEDS = 4;

    /**
     * The valued buckets a peak needs.
     */
    protected const PEAK_NEEDS = 2;

    /**
     * Create a new buckets instance.
     *
     * @param  float|null  $width  in seconds; null when the window lies outside coverage
     * @param  list<Bucket>  $buckets  in time order, every index of the grid
     * @param  array{index: int, value: int|float}|null  $peak
     */
    protected function __construct(
        public readonly RecordType $type,
        public readonly Measure $by,
        public readonly Window $window,
        public readonly ?float $width,
        public readonly array $buckets,
        public readonly ?Direction $direction,
        public readonly ?ComparisonReason $reason,
        public readonly int $valued,
        public readonly ?array $peak,
    ) {
        //
    }

    /**
     * Lay equal buckets over the window, its missing bounds derived from the records the filters select inside the type's coverage, and state a direction and a peak; null when no record is selected.
     */
    public static function read(SQLite3 $connection, RecordType $type, Measure $by, Window $given, ?float $coverageStart, ?string $group, ?string $deploy, int $count): ?self
    {
        $from = max($given->since(), $coverageStart);

        if ($from !== null && $given->until() !== null && $given->until() <= $from) {
            return new self($type, $by, $given->derive(null, null), null, [], null, ComparisonReason::OUTSIDE_COVERAGE, 0, null);
        }

        $ranking = new Ranking($type, $by, Window::between($from, $given->until(), $given->timezone()), $deploy, group: $group);
        $extent = $ranking->extent($connection);

        if ($extent === null) {
            return null;
        }

        [$first, $last] = $extent;

        $since = $given->since() ?? $first;
        $until = $given->until() ?? $last;
        $count = $until > $since ? $count : 1;
        $width = ($until - $since) / $count;

        $rows = $ranking->buckets($connection, $since, $width, $count);
        $buckets = array_map(fn (int $index) => self::bucket($by, $since, $until, $width, $count, $index, $rows[$index] ?? null, $coverageStart), range(0, $count - 1));

        $window = $given->derive($first, $last);
        $valued = self::valued($buckets);

        if (count($valued) < self::DIRECTION_NEEDS) {
            return new self($type, $by, $window, $width, $buckets, null, ComparisonReason::SAMPLE_TOO_SMALL, count($valued), self::peak($valued));
        }

        $direction = self::direction($by, array_column($valued, 'value'));

        return new self($type, $by, $window, $width, $buckets, $direction, null, count($valued), self::peak($valued));
    }

    /**
     * Get one bucket of the grid, an empty one 0 by occurrences and null by any other measure.
     *
     * @param  array{samples: int, value: int|float|null}|null  $row
     * @return Bucket
     */
    protected static function bucket(Measure $by, float $since, float $until, float $width, int $count, int $index, ?array $row, ?float $coverageStart): array
    {
        $start = $since + $index * $width;
        $end = $index === $count - 1 ? $until : $since + ($index + 1) * $width;
        $empty = $by === Measure::OCCURRENCES ? 0 : null;

        return [
            'index' => $index,
            'since' => $start,
            'until' => $end,
            'value' => $row === null ? $empty : $row['value'],
            'samples' => $row['samples'] ?? 0,
            'partial' => $coverageStart !== null && $start < $coverageStart,
        ];
    }

    /**
     * Get the buckets that count toward the direction and the peak, in time order: whole, with a value, over at least one record.
     *
     * @param  list<Bucket>  $buckets
     * @return list<array{index: int, value: int|float}>
     */
    protected static function valued(array $buckets): array
    {
        $valued = [];

        foreach ($buckets as $bucket) {
            if ($bucket['partial'] || $bucket['value'] === null || $bucket['samples'] === 0) {
                continue;
            }

            $valued[] = [
                'index' => $bucket['index'],
                'value' => $bucket['value'],
            ];
        }

        return $valued;
    }

    /**
     * Decide the direction from the values of the valued buckets in time order: the median of the first half against that of the last, the middle one dropped when their count is odd.
     *
     * @param  list<int|float>  $values
     */
    protected static function direction(Measure $by, array $values): Direction
    {
        $half = intdiv(count($values), 2);

        $before = self::median(array_slice($values, 0, $half));
        $after = self::median(array_slice($values, -$half));

        return Direction::of($by, $before, $after);
    }

    /**
     * Get the nearest-rank median of values: the value at rank max(1, ceil(n/2)) in ascending order.
     *
     * @param  list<int|float>  $values  at least one
     */
    protected static function median(array $values): int|float
    {
        sort($values);

        return $values[max(1, (int) ceil(count($values) / 2)) - 1];
    }

    /**
     * Get the earliest of the highest valued buckets, or null when too few buckets are valued or every one holds the same value.
     *
     * @param  list<array{index: int, value: int|float}>  $valued
     * @return array{index: int, value: int|float}|null
     */
    protected static function peak(array $valued): ?array
    {
        if (count($valued) < self::PEAK_NEEDS) {
            return null;
        }

        $peak = $valued[0];

        foreach ($valued as $bucket) {
            if ($bucket['value'] > $peak['value']) {
                $peak = $bucket;
            }
        }

        $level = array_filter($valued, fn (array $bucket) => $bucket['value'] == $peak['value']);

        return count($level) === count($valued) ? null : $peak;
    }

    /**
     * Determine if every selected record started at one instant, so the window has no width and one bucket holds them all.
     */
    public function isInstant(): bool
    {
        return $this->width === 0.0;
    }

    /**
     * Get how many buckets start before the type's coverage start.
     */
    public function partialCount(): int
    {
        return count(array_filter($this->buckets, fn (array $bucket) => $bucket['partial']));
    }

    /**
     * Get the whole seconds since the last record when the window's until was derived from it and at least one bucket width has passed, or null.
     */
    public function idle(float $now): ?int
    {
        if ($this->width === null || $this->isInstant() || ! $this->window->derives('until')) {
            return null;
        }

        $idle = $now - $this->window->until();

        return $idle >= $this->width ? (int) floor($idle) : null;
    }

    /**
     * Get the bucket of the peak, or null when there is none.
     *
     * @return Bucket|null
     */
    public function peakBucket(): ?array
    {
        return $this->peak === null ? null : $this->buckets[$this->peak['index']];
    }

    /**
     * Get the result of the answer: the direction as compare states a row, the peak and every bucket, each value under the measure's field.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $field = $this->by->field();
        $sampleTooSmall = $this->reason === ComparisonReason::SAMPLE_TOO_SMALL;
        $peak = $this->peak === null ? null : [
            'index' => $this->peak['index'],
            $field => $this->by->shown($this->peak['value']),
        ];

        return [
            'type' => $this->type->value,
            'by' => $this->by->value,
            'width_ms' => $this->width === null ? null : Stored::milliseconds($this->width * Microseconds::PER_SECOND),
            'direction' => $this->direction?->value,
            'reason' => $this->reason?->value,
            'have' => $sampleTooSmall ? $this->valued : null,
            'needed' => $sampleTooSmall ? self::DIRECTION_NEEDS : null,
            'peak' => $peak,
            'buckets' => array_map(fn (array $bucket) => [
                'index' => $bucket['index'],
                'since_at' => $bucket['since'],
                'until_at' => $bucket['until'],
                $field => $this->by->shown($bucket['value']),
                'samples' => $bucket['samples'],
                'partial' => $bucket['partial'],
            ], $this->buckets),
        ];
    }
}
