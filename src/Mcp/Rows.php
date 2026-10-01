<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
class Rows
{
    /**
     * Create a new rows instance.
     *
     * @param  list<mixed>  $rows  at most the limit
     */
    protected function __construct(
        public readonly array $rows,
        public readonly bool $more,
    ) {
        //
    }

    /**
     * Get how many rows a reader fetches for a limit: one more, so that exactly the limit is a complete list.
     */
    public static function fetch(int $limit): int
    {
        return $limit + 1;
    }

    /**
     * Cut what a reader fetched to the limit, and note whether the row beyond it was there.
     *
     * @param  list<mixed>  $fetched
     */
    public static function bound(array $fetched, int $limit): self
    {
        return new self(array_slice($fetched, 0, $limit), count($fetched) > $limit);
    }

    /**
     * Get the `truncated` entry of a list the limit cut, or null for a complete one.
     *
     * @return array{section: string, shown: int, matched: null, reason: string, how: string}|null
     */
    public function truncation(string $section, string $how): ?array
    {
        return $this->more
            ? ['section' => $section, 'shown' => count($this->rows), 'matched' => null, 'reason' => 'limit', 'how' => $how]
            : null;
    }
}
