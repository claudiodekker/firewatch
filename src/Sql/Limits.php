<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * The ceilings a SQL runner holds a statement to that the assistant can be told about.
 *
 * @internal
 */
class Limits
{
    /**
     * The wall-clock deadline of one statement from spawn, in seconds.
     */
    public const DEADLINE_SECONDS = 10.0;

    /**
     * The PHP memory limit of the child, in mebibytes.
     */
    public const MEMORY_LIMIT_MEBIBYTES = 64;

    /**
     * The most bytes of stdout the parent reads before it kills the child.
     */
    public const OUTPUT_CAP_BYTES = 1048576;

    /**
     * Create a new limits instance.
     */
    public function __construct(
        public readonly float $deadlineSeconds = self::DEADLINE_SECONDS,
        public readonly int $outputBytes = self::OUTPUT_CAP_BYTES,
        public readonly int $memoryMebibytes = self::MEMORY_LIMIT_MEBIBYTES,
    ) {
        //
    }
}
