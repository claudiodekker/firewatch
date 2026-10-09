<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * @internal
 */
enum QueryStop: string
{
    case COMPLETE = 'complete';
    case LIMIT = 'limit';
    case BUDGET = 'budget';
    case DEADLINE = 'deadline';
    case MEMORY = 'memory';
    case ABORTED = 'aborted';
    case ERROR = 'error';

    /**
     * Determine if the statement was cut off before its rows were done, so the rows are partial.
     */
    public function isAbnormal(): bool
    {
        return match ($this) {
            self::DEADLINE, self::MEMORY, self::ABORTED, self::ERROR => true,
            default => false,
        };
    }
}
