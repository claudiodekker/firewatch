<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
class Failure
{
    /**
     * The first HTTP status code that counts as a failure.
     */
    protected const FIRST_FAILED_STATUS_CODE = 400;

    /**
     * Determine if a record failed, by how its type says it ended.
     */
    public static function of(RecordType $type, mixed $outcome): bool
    {
        return match ($type) {
            RecordType::REQUEST, RecordType::OUTGOING_REQUEST => is_int($outcome) && $outcome >= static::FIRST_FAILED_STATUS_CODE,
            RecordType::COMMAND => is_int($outcome) && $outcome !== 0,
            RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK => in_array($outcome, static::statuses($type), true),
            default => false,
        };
    }

    /**
     * Get the SQL expression that is 1 for a failed record, 0 for one that did not fail and null for one without the field it is judged by, or null for a type with no such rule.
     */
    public static function expression(RecordType $type): ?string
    {
        return match ($type) {
            RecordType::REQUEST, RecordType::OUTGOING_REQUEST => 'CASE WHEN status_code IS NULL THEN NULL WHEN status_code >= '.static::FIRST_FAILED_STATUS_CODE.' THEN 1 ELSE 0 END',
            RecordType::COMMAND => 'CASE WHEN exit_code IS NULL THEN NULL WHEN exit_code <> 0 THEN 1 ELSE 0 END',
            RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK => "CASE WHEN status IS NULL THEN NULL WHEN status IN ('".implode("', '", static::statuses($type))."') THEN 1 ELSE 0 END",
            default => null,
        };
    }

    /**
     * Get what counts as failed for a type, in words, or null for a type with no such rule.
     */
    public static function definition(RecordType $type): ?string
    {
        return match ($type) {
            RecordType::REQUEST, RecordType::OUTGOING_REQUEST => 'status >= '.static::FIRST_FAILED_STATUS_CODE,
            RecordType::COMMAND => 'exit_code <> 0',
            RecordType::JOB_ATTEMPT, RecordType::SCHEDULED_TASK => 'status is '.implode(' or ', static::statuses($type)),
            default => null,
        };
    }

    /**
     * Get the statuses that count as failed for a job attempt or a scheduled task.
     *
     * @return list<string>
     */
    protected static function statuses(RecordType $type): array
    {
        $failed = $type === RecordType::JOB_ATTEMPT ? [Outcome::FAILED, Outcome::RELEASED] : [Outcome::FAILED];

        return array_map(fn (Outcome $outcome) => $outcome->value, $failed);
    }
}
