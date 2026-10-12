<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum RunPattern: string
{
    case LOOP = 'loop';
    case REPEATED = 'repeated';
    case UNKNOWN = 'unknown';

    /**
     * Get the pattern of a group's runs, from the most runs one application call site and one vendor call site made in one execution.
     */
    public static function of(int $application, int $vendor, int|float $threshold): self
    {
        return match (true) {
            $application >= $threshold => self::LOOP,
            // A vendor call site stands for every application caller the trace lost, so it may be one loop or several lines.
            $vendor >= $threshold => self::UNKNOWN,
            default => self::REPEATED,
        };
    }

    /**
     * Get the precedence of the pattern in a list of findings, the lowest first.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::LOOP => 0,
            self::REPEATED => 1,
            self::UNKNOWN => 2,
        };
    }
}
