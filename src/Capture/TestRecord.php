<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
readonly class TestRecord
{
    /**
     * The source stored on what a test runs outside its executions, which no execution has.
     */
    public const BETWEEN_SOURCE = 'between';

    /**
     * Create a new test record instance.
     */
    public function __construct(
        public bool $inExecution,
        public ?string $test,
        public ?string $worker,
    ) {
        //
    }
}
