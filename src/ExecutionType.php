<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
enum ExecutionType: string
{
    case REQUEST = 'request';
    case COMMAND = 'command';
    case JOB_ATTEMPT = 'job-attempt';
    case SCHEDULED_TASK = 'scheduled-task';

    /**
     * Get the four execution types as record types.
     *
     * @return list<RecordType>
     */
    public static function records(): array
    {
        return array_map(fn (self $type) => RecordType::from($type->value), self::cases());
    }
}
