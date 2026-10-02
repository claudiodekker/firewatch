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
     * Get the refusal of a required argument that is missing.
     */
    public static function missing(string $argument, string $accepted, string $example): self
    {
        return new self(ErrorCode::MISSING_ARGUMENT, __('firewatch::messages.missing_argument', [
            'argument' => $argument,
            'accepted' => $accepted,
            'example' => $example,
        ]));
    }

    /**
     * Get the refusal of an argument whose value is none of the accepted ones: `expected` completes the sentence, `value` is shown as given.
     */
    public static function invalid(string $argument, string $expected, string $value, string $accepted, string $example): self
    {
        return new self(ErrorCode::INVALID_ARGUMENT, __('firewatch::messages.invalid_argument', [
            'argument' => $argument,
            'expected' => $expected,
            'value' => $value,
            'accepted' => $accepted,
            'example' => $example,
        ]));
    }

    /**
     * Get the refusal of an argument that no tool takes, such as a misspelled one.
     *
     * @param  list<string>  $accepted
     */
    public static function unknown(string $argument, string $tool, array $accepted): self
    {
        return new self(ErrorCode::INVALID_ARGUMENT, __('firewatch::messages.unknown_argument', [
            'argument' => $argument,
            'tool' => $tool,
            'accepted' => implode(', ', $accepted),
            'example' => self::formatExample($tool),
        ]));
    }

    /**
     * Get the refusal of an argument that another tool takes but this one does not.
     *
     * @param  list<string>  $accepted
     */
    public static function inapplicable(string $argument, string $tool, array $accepted): self
    {
        return new self(ErrorCode::CONFLICTING_ARGUMENTS, __('firewatch::messages.inapplicable_argument', [
            'argument' => $argument,
            'tool' => $tool,
            'accepted' => implode(', ', $accepted),
            'example' => self::formatExample($tool),
        ]));
    }

    /**
     * Get the refusal of an argument that does not apply with another argument of the call.
     */
    public static function conflicting(string $argument, string $with, string $accepted, string $example): self
    {
        return new self(ErrorCode::CONFLICTING_ARGUMENTS, __('firewatch::messages.conflicting_arguments', [
            'argument' => $argument,
            'with' => $with,
            'accepted' => $accepted,
            'example' => $example,
        ]));
    }

    /**
     * Get the refusal of a split that does not lie strictly inside the window.
     */
    public static function splitOutsideWindow(string $example): self
    {
        return new self(ErrorCode::SPLIT_OUTSIDE_WINDOW, __('firewatch::messages.split_outside_window', ['example' => $example]));
    }

    /**
     * Get the refusal of an identifier the whole store does not hold.
     */
    public static function notFound(string $argument, string $id, string $accepted, string $example): self
    {
        return new self(ErrorCode::NOT_FOUND, __('firewatch::messages.not_found', [
            'argument' => $argument,
            'id' => $id,
            'accepted' => $accepted,
            'example' => $example,
        ]));
    }

    /**
     * Get the refusal of a cursor that does not belong to the call it was passed to.
     */
    public static function badCursor(string $tool): self
    {
        return new self(ErrorCode::BAD_CURSOR, __('firewatch::messages.bad_cursor', ['tool' => $tool]));
    }

    /**
     * Get the answer to a failure no one expected: it names nothing of the failure, which is reported instead.
     */
    public static function internal(): self
    {
        return new self(ErrorCode::INTERNAL, __('firewatch::messages.internal'));
    }

    /**
     * Get the refusal of a format that is none.
     */
    public static function format(mixed $value, string $tool): self
    {
        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        return self::invalid(argument: 'format', expected: 'markdown or json', value: $shown, accepted: 'markdown or json', example: self::formatExample($tool));
    }

    /**
     * Get a valid call of the tool that asks for JSON.
     */
    protected static function formatExample(string $tool): string
    {
        return "{$tool}(format: \"json\")";
    }

    /**
     * Get the refusal of a boundary that is no time of the grammar.
     */
    public static function time(string $argument, mixed $value, string $tool): self
    {
        $shown = '`'.(is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR)).'`';

        return new self(ErrorCode::UNREADABLE_TIME, __('firewatch::messages.unreadable_time', [
            'argument' => $argument,
            'value' => $shown,
            'tool' => $tool,
            'maximum' => TimeGrammar::MAXIMUM_EPOCH,
        ]));
    }

    /**
     * Get the refusal of a window that does not start before it ends.
     */
    public static function window(float $since, float $until, string $timezone, string $tool): self
    {
        return new self(ErrorCode::EMPTY_WINDOW, __('firewatch::messages.empty_window', [
            'since' => Instant::format($since, $timezone),
            'until' => Instant::format($until, $timezone),
            'tool' => $tool,
        ]));
    }
}
