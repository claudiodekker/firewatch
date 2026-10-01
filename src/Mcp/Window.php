<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use SQLite3Stmt;

/**
 * @internal
 */
class Window
{
    /**
     * Create a new window instance.
     */
    protected function __construct(
        protected readonly bool $windowed,
        protected readonly ?float $since,
        protected readonly ?float $until,
        protected readonly string $timezone,
        protected readonly string $reason,
    ) {
        //
    }

    /**
     * Get a window half-open on `started_at`, with a bound on either side or none.
     */
    public static function between(?float $since, ?float $until, string $timezone): self
    {
        return new self(true, $since, $until, $timezone, '');
    }

    /**
     * Read the window the request's `since` and `until` give, against the store clock, or refuse one that is unreadable or empty.
     */
    public static function read(Request $request, CarbonImmutable $now, string $timezone, string $tool): self
    {
        $since = self::boundary($request, 'since', $now, $timezone, $tool);
        $until = self::boundary($request, 'until', $now, $timezone, $tool);

        if ($since !== null && $until !== null && $since >= $until) {
            throw Refusal::window($since, $until, $timezone, $tool);
        }

        return self::between($since, $until, $timezone);
    }

    /**
     * Get the window of a tool that takes no `since` or `until`.
     */
    public static function none(string $reason, string $timezone): self
    {
        return new self(false, null, null, $timezone, $reason);
    }

    /**
     * Get the start of the window, or null for one with none.
     */
    public function since(): ?float
    {
        return $this->since;
    }

    /**
     * Get the end of the window, or null for one with none.
     */
    public function until(): ?float
    {
        return $this->until;
    }

    /**
     * Get the timezone the window is written in.
     */
    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Get the SQL condition that holds for the records of the window.
     */
    public function condition(): string
    {
        return '(:since IS NULL OR started_at >= :since) AND (:until IS NULL OR started_at < :until)';
    }

    /**
     * Bind the bounds the condition names.
     */
    public function bind(SQLite3Stmt $statement): void
    {
        $statement->bindValue(':since', $this->since, $this->since === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $statement->bindValue(':until', $this->until, $this->until === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    }

    /**
     * Get the window as the envelope's `window` key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (! $this->windowed) {
            return ['windowed' => false, 'reason' => $this->reason];
        }

        return [
            'windowed' => true,
            'basis' => 'started_at',
            'since' => $this->since,
            'until' => $this->until,
            'timezone' => $this->timezone,
            'description' => __('firewatch::messages.window_description'),
        ];
    }

    /**
     * Get the window line of a markdown answer.
     */
    public function line(): string
    {
        if (! $this->windowed) {
            return __('firewatch::messages.window_not_windowed', ['reason' => $this->reason]);
        }

        if ($this->since === null && $this->until === null) {
            return __('firewatch::messages.window_unbounded');
        }

        return __('firewatch::messages.window_bounded', [
            'since' => $this->bound($this->since),
            'until' => $this->bound($this->until),
            'timezone' => $this->timezone,
        ]);
    }

    /**
     * Get one side of the window as local time, or as unbounded.
     */
    protected function bound(?float $epoch): string
    {
        return $epoch === null ? __('firewatch::messages.window_none') : Instant::format($epoch, $this->timezone);
    }

    /**
     * Read one boundary of the request, or null for one it leaves out.
     */
    protected static function boundary(Request $request, string $argument, CarbonImmutable $now, string $timezone, string $tool): ?float
    {
        $value = $request->get($argument);

        if ($value === null) {
            return null;
        }

        return TimeGrammar::parse($value, $now, $timezone) ?? throw Refusal::time($argument, $value, $tool);
    }
}
