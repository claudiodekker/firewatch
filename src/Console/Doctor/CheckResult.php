<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use Throwable;

/**
 * What one check found. It carries no id: the doctor pairs it with the check that ran, so a check can't report under another id.
 *
 * Built only through the named constructors, so a fix exists exactly on warn and fail.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class CheckResult
{
    /**
     * Create a new check result instance.
     */
    protected function __construct(
        public CheckStatus $status,
        public string $message,
        public ?string $fix,
    ) {
        //
    }

    /**
     * Create a result for a check that passed.
     */
    public static function ok(string $message): static
    {
        return new static(CheckStatus::OK, $message, fix: null);
    }

    /**
     * Create a result that informs and never counts toward the run's status.
     */
    public static function info(string $message): static
    {
        return new static(CheckStatus::INFO, $message, fix: null);
    }

    /**
     * Create a result for a problem that leaves the run passing.
     */
    public static function warn(string $message, string $fix): static
    {
        return new static(CheckStatus::WARN, $message, $fix);
    }

    /**
     * Create a result for a problem that fails the run.
     */
    public static function fail(string $message, string $fix): static
    {
        return new static(CheckStatus::FAIL, $message, $fix);
    }

    /**
     * Create the result of a check that threw: a failure with the exception's message.
     */
    public static function threw(Throwable $exception): static
    {
        return static::fail($exception->getMessage(), __('firewatch::messages.doctor.threw_fix'));
    }
}
