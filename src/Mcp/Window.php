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
     *
     * @param  list<'since'|'until'>|null  $derived  the bounds filled in from the selected records; null for a tool that never derives one
     */
    protected function __construct(
        protected readonly bool $windowed,
        protected readonly ?float $since,
        protected readonly ?float $until,
        protected readonly string $timezone,
        protected readonly string $reason,
        protected readonly ?array $derived = null,
    ) {
        //
    }

    /**
     * Get a window half-open on `started_at`, with a bound on either side or none.
     */
    public static function between(?float $since, ?float $until, string $timezone): self
    {
        return new self(windowed: true, since: $since, until: $until, timezone: $timezone, reason: '');
    }

    /**
     * Read the window the request's `since` and `until` give, against the store clock, or refuse one that is unreadable or empty.
     */
    public static function read(Request $request, CarbonImmutable $now, string $timezone, string $tool): self
    {
        $since = self::boundary($request, argument: 'since', now: $now, timezone: $timezone, tool: $tool);
        $until = self::boundary($request, argument: 'until', now: $now, timezone: $timezone, tool: $tool);

        if ($since !== null && $until !== null && $since >= $until) {
            throw Refusal::window(since: $since, until: $until, timezone: $timezone, tool: $tool);
        }

        return self::between($since, $until, $timezone);
    }

    /**
     * Get the window of a tool that takes no `since` or `until`.
     */
    public static function none(string $reason, string $timezone): self
    {
        return new self(windowed: false, since: null, until: null, timezone: $timezone, reason: $reason);
    }

    /**
     * Get the window with each bound it was not given filled in from the first and the last selected record, and named as derived; a derived until includes the record at it.
     */
    public function derive(?float $first, ?float $last): self
    {
        $derived = [];

        if ($this->since === null && $first !== null) {
            $derived[] = 'since';
        }

        if ($this->until === null && $last !== null) {
            $derived[] = 'until';
        }

        return new self(windowed: true, since: $this->since ?? $first, until: $this->until ?? $last, timezone: $this->timezone, reason: '', derived: $derived);
    }

    /**
     * Determine if the bound was derived from the selected records.
     *
     * @param  'since'|'until'  $bound
     */
    public function derives(string $bound): bool
    {
        return in_array($bound, $this->derived ?? [], true);
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
     * Get the bounds as the arguments of a later call that reads the same window: the instants they resolved to, a side with no bound left out.
     *
     * @return array{since?: float, until?: float}
     */
    public function arguments(): array
    {
        $bounds = [
            'since' => $this->since,
            'until' => $this->until,
        ];

        return array_filter($bounds, fn (?float $bound) => $bound !== null);
    }

    /**
     * Get the SQL condition that holds for the records of the window: half-open, except that a derived until includes the record at it.
     */
    public function condition(): string
    {
        $until = $this->derives('until') ? '<=' : '<';

        return "(:since IS NULL OR started_at >= :since) AND (:until IS NULL OR started_at {$until} :until)";
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
            return [
                'windowed' => false,
                'reason' => $this->reason,
            ];
        }

        $window = [
            'windowed' => true,
            'basis' => 'started_at',
            'since' => $this->since,
            'until' => $this->until,
            'timezone' => $this->timezone,
            'description' => __($this->derives('until') ? 'firewatch::messages.window_description_derived' : 'firewatch::messages.window_description'),
        ];

        if ($this->derived === null) {
            return $window;
        }

        return [
            ...$window,
            'derived' => $this->derived,
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

        if ($this->derived !== null && $this->derived !== []) {
            return __('firewatch::messages.window_resolved', [
                'since' => $this->bound($this->since),
                'until' => $this->bound($this->until),
                'timezone' => $this->timezone,
                'derived' => implode(', ', array_map(fn (string $bound) => __("firewatch::messages.window_derived_{$bound}"), $this->derived)),
            ]);
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

        return TimeGrammar::parse($value, $now, $timezone) ?? throw Refusal::time(argument: $argument, value: $value, tool: $tool);
    }
}
