<?php

namespace ClaudioDekker\Firewatch\Mcp;

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
     * Get the window of a tool that takes no `since` or `until`.
     */
    public static function none(string $reason, string $timezone): self
    {
        return new self(false, null, null, $timezone, $reason);
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
}
