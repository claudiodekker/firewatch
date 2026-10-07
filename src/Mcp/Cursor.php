<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Closure;
use JsonException;

/**
 * @template-covariant TLast of array<string, mixed>
 *
 * @internal
 */
class Cursor
{
    /**
     * The arguments a cursor does not pin.
     *
     * @var list<string>
     */
    protected const UNPINNED = ['cursor', 'limit', 'format'];

    /**
     * Create a new cursor instance.
     *
     * @param  TLast  $last
     */
    protected function __construct(
        public readonly array $last,
        public readonly ?float $since,
        public readonly ?float $until,
        protected readonly ?float $createdAt,
    ) {
        //
    }

    /**
     * Get the opaque cursor that continues a listing after its last row.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $last
     */
    public static function make(string $tool, array $arguments, ?float $createdAt, array $last, ?float $since, ?float $until): string
    {
        $payload = json_encode([
            'tool' => $tool,
            'arguments' => self::hash($arguments),
            'created_at' => $createdAt,
            'last' => $last,
            'since' => $since,
            'until' => $until,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Read a cursor passed to a call, which must be one the tool made for these arguments.
     *
     * @template TKey of array<string, mixed>
     *
     * @param  array<string, mixed>  $arguments
     * @param  Closure(array<string, mixed>): (TKey|null)  $key  checks the key of the last row and returns it, or null when it is none
     * @return self<TKey>
     */
    public static function read(mixed $value, string $tool, array $arguments, Closure $key): self
    {
        $payload = is_string($value) ? base64_decode(strtr($value, '-_', '+/'), strict: true) : false;

        try {
            $fields = is_string($payload) ? json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $fields = null;
        }

        if (! is_array($fields) || ($fields['tool'] ?? null) !== $tool || ($fields['arguments'] ?? null) !== self::hash($arguments)) {
            throw Refusal::badCursor($tool);
        }

        $last = is_array($fields['last'] ?? null) ? $key($fields['last']) : null;

        if ($last === null || ! array_key_exists('created_at', $fields)) {
            throw Refusal::badCursor($tool);
        }

        return new self(
            last: $last,
            since: self::instant($fields['since'] ?? null),
            until: self::instant($fields['until'] ?? null),
            createdAt: self::instant($fields['created_at']),
        );
    }

    /**
     * Read a bound of the window, which is a number or none.
     */
    protected static function instant(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? $value + 0.0 : null;
    }

    /**
     * Fail unless the store is the one the cursor was made for: a rebuild starts the ids again, a clear does not.
     */
    public function belongsTo(?float $createdAt, string $tool): void
    {
        if ($this->createdAt !== $createdAt) {
            throw Refusal::badCursor($tool);
        }
    }

    /**
     * Get a hash of the arguments a cursor pins.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function hash(array $arguments): string
    {
        $pinned = array_diff_key($arguments, array_flip(self::UNPINNED));

        ksort($pinned);

        return hash('sha256', json_encode($pinned, JSON_THROW_ON_ERROR));
    }
}
