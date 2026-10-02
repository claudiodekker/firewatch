<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
enum Outcome: string
{
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case RELEASED = 'released';
    case SKIPPED = 'skipped';

    /**
     * Get the outcomes a type has, which is none for a type that has no outcome.
     *
     * @return list<self>
     */
    public static function for(RecordType $type): array
    {
        return match ($type) {
            RecordType::JOB_ATTEMPT => [self::PROCESSED, self::FAILED, self::RELEASED],
            RecordType::SCHEDULED_TASK => [self::PROCESSED, self::FAILED, self::SKIPPED],
            default => [],
        };
    }
}
