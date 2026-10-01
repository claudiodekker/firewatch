<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;

/**
 * @internal
 */
class Coverage
{
    /**
     * Create a new coverage instance.
     *
     * @param  string|null  $reason  why an unusable store is: foreign_file, newer_schema, older_schema, sqlite_too_old or unreadable
     */
    public function __construct(
        public readonly CoverageState $state,
        public readonly ?string $reason = null,
        public readonly ?float $oldest = null,
        public readonly ?float $newest = null,
        public readonly ?int $records = null,
    ) {
        //
    }

    /**
     * Get the coverage of a store that can't be read: absent, or unusable with the reason it is.
     */
    public static function of(StoreUnusable $unusable): self
    {
        return $unusable->state === StoreState::ABSENT
            ? new self(CoverageState::ABSENT)
            : new self(CoverageState::UNUSABLE, self::reason($unusable));
    }

    /**
     * Get why a store is unusable: foreign_file, newer_schema, older_schema, sqlite_too_old, or unreadable for anything else.
     */
    public static function reason(StoreUnusable $unusable): string
    {
        return match ($unusable->state) {
            StoreState::FOREIGN => 'foreign_file',
            StoreState::SCHEMA_MISMATCH => $unusable->found < Schema::VERSION ? 'older_schema' : 'newer_schema',
            StoreState::UNAVAILABLE => 'sqlite_too_old',
            default => 'unreadable',
        };
    }

    /**
     * Get the coverage as the envelope's `coverage` key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reason' => $this->reason,
            'oldest_at' => $this->oldest,
            'newest_at' => $this->newest,
            'records' => $this->records,
        ];
    }

    /**
     * Get the store line of a markdown answer.
     */
    public function line(string $timezone): string
    {
        $parts = [$this->reason === null ? $this->state->value : "{$this->state->value} ({$this->reason})"];

        if ($this->oldest !== null && $this->newest !== null) {
            $parts[] = Instant::format($this->oldest, $timezone).' '.__('firewatch::messages.store_to').' '.Instant::format($this->newest, $timezone);
        }

        if ($this->records !== null) {
            $parts[] = __('firewatch::messages.store_records', ['count' => number_format($this->records)]);
        }

        return __('firewatch::messages.store_line', ['store' => implode(', ', $parts)]);
    }
}
