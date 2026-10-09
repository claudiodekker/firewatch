<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlFailure;
use RuntimeException;

/**
 * @internal
 */
class Refusal extends RuntimeException
{
    /**
     * The most characters of SQLite's message an invalid statement is refused with.
     */
    public const SQL_MESSAGE_CHARACTERS = 300;

    /**
     * Create a new refusal instance.
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
     * Get the refusal of an argument whose value is none of the accepted ones.
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
     * Get the refusal of an argument that no tool takes.
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
     * Get the refusal of an execution id that the store holds only as a trace id.
     */
    public static function traceIdNotExecution(string $argument, string $id, string $accepted, string $example): self
    {
        return new self(ErrorCode::NOT_FOUND, __('firewatch::messages.execution_not_found_trace', [
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
     * Get the answer to a failure no one expected.
     */
    public static function internal(): self
    {
        return new self(ErrorCode::INTERNAL, __('firewatch::messages.internal'));
    }

    /**
     * Get the refusal of a SQL statement that was refused or could not be run; the SQL itself is never echoed.
     */
    public static function sql(SqlFailure $failure): self
    {
        $text = match ($failure->error) {
            ErrorCode::NOT_ALLOWED => self::notAllowed($failure->denied ?? Denied::ACTION, (string) $failure->name),
            ErrorCode::INVALID_SQL => self::invalidSql((string) $failure->detail),
            ErrorCode::ABORTED => self::aborted((string) $failure->detail),
            ErrorCode::DEADLINE => __('firewatch::messages.deadline', ['seconds' => (int) ChildRunner::DEADLINE_SECONDS]),
            ErrorCode::MEMORY => __('firewatch::messages.memory', ['mebibytes' => Policy::HEAP_LIMIT_MEBIBYTES]),
            ErrorCode::ROW_TOO_LARGE => __('firewatch::messages.row_too_large', ['bytes' => number_format(Policy::ROW_BUDGET_BYTES)]),
            ErrorCode::UNAVAILABLE => self::unavailable($failure->unavailable),
            default => __('firewatch::messages.failed'),
        };

        return new self($failure->error, $text);
    }

    /**
     * Get the text of a statement SQLite could not compile or run, with the start of SQLite's message.
     */
    protected static function invalidSql(string $detail): string
    {
        $message = mb_substr($detail, 0, self::SQL_MESSAGE_CHARACTERS);

        return __('firewatch::messages.invalid_sql', ['message' => $message]);
    }

    /**
     * Get the text of a call whose isolation could not be established.
     */
    protected static function unavailable(?Unavailable $reason): string
    {
        $because = __("firewatch::messages.sql_unavailable.{$reason?->value}");

        return __('firewatch::messages.unavailable', ['reason' => $because]);
    }

    /**
     * Get the text of a statement the SQL policy refuses, with a hint where there is a next step.
     */
    protected static function notAllowed(Denied $denied, string $name): string
    {
        $message = __("firewatch::messages.sql_denied.{$denied->value}", [
            'name' => $name,
            'bytes' => number_format(Policy::SQL_BYTES),
        ]);

        $text = __('firewatch::messages.not_allowed', ['message' => $message]);

        return match ($denied) {
            Denied::FUNCTION => $text,
            Denied::TABLE => $text."\n".__('firewatch::messages.sql_hint_table'),
            default => $text."\n".__('firewatch::messages.sql_hint_forms'),
        };
    }

    /**
     * Get the text of a query process that ended without a complete result, with the start of its stderr.
     */
    protected static function aborted(string $stderr): string
    {
        $text = __('firewatch::messages.aborted');

        if ($stderr === '') {
            return $text;
        }

        $flattened = preg_replace('/\s+/', ' ', $stderr);

        return $text."\n".__('firewatch::messages.aborted_stderr', ['stderr' => $flattened]);
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
