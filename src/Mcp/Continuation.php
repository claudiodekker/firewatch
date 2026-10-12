<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Store\Instant;
use Closure;
use Laravel\Mcp\Request;

/**
 * @template TKey of array<string, mixed>
 *
 * @internal
 */
class Continuation
{
    /**
     * Create a new continuation instance.
     *
     * @param  Cursor<TKey>|null  $cursor
     */
    protected function __construct(
        protected readonly Request $request,
        protected readonly string $tool,
        protected readonly ?Cursor $cursor,
    ) {
        //
    }

    /**
     * Read the cursor a call carries, which must be one the tool made for its arguments, or none on a first page.
     *
     * @template TRead of array<string, mixed>
     *
     * @param  Closure(array<string, mixed>): (TRead|null)  $key
     * @return self<TRead>
     */
    public static function read(Request $request, string $tool, Closure $key): self
    {
        $value = $request->get('cursor');
        $cursor = $value === null ? null : Cursor::read(value: $value, tool: $tool, arguments: $request->all(), key: $key);

        return new self($request, $tool, $cursor);
    }

    /**
     * Get the key of the last row the cursor continues after, or null on a first page.
     *
     * @return TKey|null
     */
    public function after(): ?array
    {
        return $this->cursor?->last;
    }

    /**
     * Get the window of the call, which a cursor keeps from the first page.
     */
    public function window(CarbonImmutable $now, string $timezone): Window
    {
        if ($this->cursor !== null) {
            return Window::between(since: $this->cursor->since, until: $this->cursor->until, timezone: $timezone);
        }

        return Window::read($this->request, $now, timezone: $timezone, tool: $this->tool);
    }

    /**
     * Fail unless the store is the one the cursor was made for.
     */
    public function belongsTo(?float $createdAt): void
    {
        $this->cursor?->belongsTo($createdAt, $this->tool);
    }

    /**
     * Get the `truncated` entries of a list the limit cut, the one entry carrying the call that continues it, or none for a complete one.
     *
     * @param  Rows<mixed>  $rows
     * @param  list<TKey>  $keys
     * @param  string  $how  the message key, given the `call`
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    public function truncated(Rows $rows, array $keys, string $section, string $how, ?float $createdAt, Window $window, float $epoch): array
    {
        if (! $rows->more) {
            return [];
        }

        $last = $keys[count($rows->rows) - 1];
        $until = $window->until() ?? $epoch;
        $arguments = array_diff_key($this->request->all(), array_flip(['cursor', 'format']));

        foreach (array_intersect_key($arguments, array_flip(['since', 'until'])) as $bound => $value) {
            $arguments[$bound] = is_int($value) || is_float($value) ? Instant::epoch($value) : $value;
        }

        $arguments['cursor'] = Cursor::make(tool: $this->tool, arguments: $this->request->all(), createdAt: $createdAt, last: $last, since: $window->since(), until: $until);
        $call = Call::written($this->tool, $arguments);
        $entry = $rows->truncation(section: $section, how: __($how, ['call' => $call]));

        return $entry === null ? [] : [$entry];
    }
}
