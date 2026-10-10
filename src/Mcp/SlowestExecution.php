<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
readonly class SlowestExecution
{
    /**
     * Create a new slowest execution instance.
     *
     * @param  array<string, int|float>  $stages  microseconds by stage
     */
    public function __construct(
        public ?string $executionId,
        public int|float $duration,
        public array $stages,
    ) {
        //
    }
}
