<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 *
 * @template TRow = mixed
 */
class Rows
{
    /**
     * Create a new rows instance.
     *
     * @param  list<TRow>  $rows  at most the limit
     */
    protected function __construct(
        public readonly array $rows,
        public readonly bool $more,
    ) {
        //
    }

    /**
     * Get how many rows a reader fetches for a limit.
     */
    public static function fetch(int $limit): int
    {
        return $limit + 1;
    }

    /**
     * Cut what a reader fetched to the limit, and note whether the row beyond it was there.
     *
     * @template TFetched
     *
     * @param  list<TFetched>  $fetched
     * @return self<TFetched>
     */
    public static function bound(array $fetched, int $limit): self
    {
        $kept = array_slice($fetched, 0, $limit);

        return new self(rows: $kept, more: count($fetched) > $limit);
    }

    /**
     * Get the `truncated` entry of a list the limit cut, or null for a complete one.
     *
     * @return array{section: string, shown: int, matched: int|null, reason: string, how: string}|null
     */
    public function truncation(string $section, string $how, ?int $matched = null): ?array
    {
        if (! $this->more) {
            return null;
        }

        return [
            'section' => $section,
            'shown' => count($this->rows),
            'matched' => $matched,
            'reason' => TruncationReason::LIMIT->value,
            'how' => $how,
        ];
    }
}
