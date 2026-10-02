<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case ALERT = 'alert';
    case EMERGENCY = 'emergency';

    /**
     * Get the level and every worse one, as their names.
     *
     * @return list<string>
     */
    public function andWorse(): array
    {
        $names = [];
        $reached = false;

        foreach (self::cases() as $level) {
            $reached = $reached || $level === $this;

            if ($reached) {
                $names[] = $level->value;
            }
        }

        return $names;
    }
}
