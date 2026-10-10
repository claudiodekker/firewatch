<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
enum Stage: string
{
    case BOOTSTRAP = 'bootstrap';
    case BEFORE_MIDDLEWARE = 'before_middleware';
    case ACTION = 'action';
    case RENDER = 'render';
    case AFTER_MIDDLEWARE = 'after_middleware';
    case SENDING = 'sending';
    case TERMINATING = 'terminating';

    /**
     * Get the stages an execution of the type records, in the order they run.
     *
     * @return list<self>
     */
    public static function of(RecordType $type): array
    {
        return match ($type) {
            RecordType::REQUEST => self::cases(),
            RecordType::COMMAND => [self::BOOTSTRAP, self::ACTION, self::TERMINATING],
            default => [],
        };
    }
}
