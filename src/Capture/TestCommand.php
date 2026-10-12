<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
class TestCommand
{
    /**
     * How many commands are running, counting the outer one and those it started.
     */
    public int $depth = 1;

    /**
     * Create a new test command instance.
     */
    public function __construct(
        public readonly bool $recorded,
        public readonly TestPhase $resumes,
    ) {
        //
    }
}
