<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum JobOutcome: string
{
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case RETRYING = 'retrying';
    case PENDING = 'pending';

    /**
     * Get the outcome a queued job has from the status of its last attempt, or null for a status that says none.
     */
    public static function of(mixed $status): ?self
    {
        $outcome = is_string($status) ? Outcome::tryFrom($status) : null;

        return match ($outcome) {
            Outcome::PROCESSED => self::PROCESSED,
            Outcome::FAILED => self::FAILED,
            Outcome::RELEASED => self::RETRYING,
            default => null,
        };
    }
}
