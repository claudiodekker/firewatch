<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
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
     * @param  list<RecordType>  $typesRead
     * @param  int|null  $straddling  the executions of a compared type that started in the hour before the split and finished after it; null outside a time split and for a compared type that is no execution
     */
    public function __construct(
        public readonly CoverageState $state,
        public readonly array $typesRead,
        public readonly History $history,
        public readonly ?UnusableReason $reason = null,
        public readonly ?float $oldest = null,
        public readonly ?float $newest = null,
        public readonly ?int $records = null,
        public readonly ?int $straddling = null,
    ) {
        //
    }

    /**
     * Get the coverage of a store that can't be read.
     *
     * @param  list<RecordType>  $typesRead
     */
    public static function of(StoreUnusable $unusable, array $typesRead, History $history): self
    {
        return $unusable->state === StoreState::ABSENT
            ? new self(CoverageState::ABSENT, $typesRead, $history)
            : new self(CoverageState::UNUSABLE, $typesRead, $history, reason: self::reason($unusable));
    }

    /**
     * Get why a store is unusable, which is unreadable for anything that has no reason of its own.
     */
    public static function reason(StoreUnusable $unusable): UnusableReason
    {
        return match ($unusable->state) {
            StoreState::FOREIGN => UnusableReason::FOREIGN_FILE,
            StoreState::SCHEMA_MISMATCH => $unusable->found < Schema::VERSION ? UnusableReason::OLDER_SCHEMA : UnusableReason::NEWER_SCHEMA,
            StoreState::UNAVAILABLE => UnusableReason::SQLITE_TOO_OLD,
            default => UnusableReason::UNREADABLE,
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
            'reason' => $this->reason?->value,
            'oldest_at' => $this->oldest,
            'newest_at' => $this->newest,
            'records' => $this->records,
            'types_read' => array_map(fn (RecordType $type) => $type->value, $this->typesRead),
            'history' => $this->history->toArray(),
            'straddling' => $this->straddling,
        ];
    }

    /**
     * Get the store line of a markdown answer.
     */
    public function line(string $timezone): string
    {
        $parts = [$this->reason === null ? $this->state->value : "{$this->state->value} ({$this->reason->value})"];

        if ($this->oldest !== null && $this->newest !== null) {
            $parts[] = Instant::format($this->oldest, $timezone).' '.__('firewatch::messages.store_to').' '.Instant::format($this->newest, $timezone);
        }

        if ($this->records !== null) {
            $parts[] = __('firewatch::messages.store_records', ['count' => number_format($this->records)]);
        }

        if ($this->straddling !== null) {
            $parts[] = __('firewatch::messages.store_straddling', ['count' => number_format($this->straddling)]);
        }

        $line = __('firewatch::messages.store_line', ['store' => implode(', ', $parts)]);

        return $this->history->from === null ? $line : $line.'; '.$this->historyLine($timezone);
    }

    /**
     * Get the part of the store line that says from when the history is complete, and how long it is kept.
     */
    protected function historyLine(string $timezone): string
    {
        return __('firewatch::messages.store_history', [
            'from' => Instant::format((float) $this->history->from, $timezone),
            'reason' => $this->history->reason?->value,
            'age' => $this->history->retentionAge === null ? __('firewatch::messages.store_unlimited') : Markdown::duration($this->history->retentionAge),
            'records' => $this->history->retentionRecords === null ? __('firewatch::messages.store_unlimited') : number_format($this->history->retentionRecords),
        ]);
    }
}
