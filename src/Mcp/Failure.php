<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\ExecutionType;

/**
 * @internal
 */
class Failure
{
    /**
     * Determine if an execution failed, by how its type says it ended: a status code of 400 or more, an exit code that is not zero, a failed or released attempt or a failed task.
     */
    public static function of(ExecutionType $type, mixed $outcome): bool
    {
        return match ($type) {
            ExecutionType::REQUEST => is_int($outcome) && $outcome >= Ranking::FIRST_FAILED_STATUS_CODE,
            ExecutionType::COMMAND => is_int($outcome) && $outcome !== 0,
            ExecutionType::JOB_ATTEMPT => in_array($outcome, [Outcome::FAILED->value, Outcome::RELEASED->value], true),
            ExecutionType::SCHEDULED_TASK => $outcome === Outcome::FAILED->value,
        };
    }
}
