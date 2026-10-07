<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum TaskKind: string
{
    case FAILED = 'failed';
    case SKIPPED_ONLY = 'skipped_only';

    /**
     * Get the kind of a group with a failed or skipped scheduled task, by how many of them failed.
     */
    public static function of(int $failed): self
    {
        return $failed > 0 ? self::FAILED : self::SKIPPED_ONLY;
    }
}
