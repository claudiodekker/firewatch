<?php

namespace ClaudioDekker\Firewatch\Mcp;

use RuntimeException;

/**
 * @internal
 */
class Refusal extends RuntimeException
{
    /**
     * Create a new refusal instance from the text of the error the tool answers with.
     */
    public function __construct(public readonly ErrorCode $error, string $text)
    {
        parent::__construct($text);
    }

    /**
     * Get the refusal of a format that is none.
     */
    public static function format(mixed $value, string $tool): self
    {
        return new self(ErrorCode::INVALID_ARGUMENT, __('firewatch::messages.format_refused', ['value' => json_encode($value), 'tool' => $tool]));
    }

    /**
     * Get the refusal of a boundary that is no time of the grammar.
     */
    public static function time(string $argument, mixed $value, string $tool): self
    {
        $shown = '`'.(is_string($value) ? $value : json_encode($value)).'`';

        return new self(ErrorCode::UNREADABLE_TIME, __('firewatch::messages.unreadable_time', ['argument' => $argument, 'value' => $shown, 'tool' => $tool, 'maximum' => TimeGrammar::MAXIMUM_EPOCH]));
    }

    /**
     * Get the refusal of a window that does not start before it ends.
     */
    public static function window(float $since, float $until, string $timezone, string $tool): self
    {
        return new self(ErrorCode::EMPTY_WINDOW, __('firewatch::messages.empty_window', ['since' => Instant::format($since, $timezone), 'until' => Instant::format($until, $timezone), 'tool' => $tool]));
    }
}
