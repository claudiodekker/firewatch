<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

/**
 * @internal
 */
enum CheckStatus: string
{
    case OK = 'ok';
    case WARN = 'warn';
    case FAIL = 'fail';
    case INFO = 'info';

    /**
     * Get the worst of the statuses: fail over warn over ok, with info never counting and ok when nothing else does.
     */
    public static function worst(self ...$statuses): self
    {
        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }

        if (in_array(self::WARN, $statuses, true)) {
            return self::WARN;
        }

        return self::OK;
    }
}
