<?php

namespace ClaudioDekker\Firewatch\Configuration;

use ClaudioDekker\Firewatch\ExecutionType;

/**
 * @internal
 */
readonly class BudgetEntry
{
    /**
     * Create a new budget entry instance.
     *
     * @param  list<string>|null  $methods
     */
    public function __construct(
        public int $number,
        public ExecutionType $type,
        public ?array $methods,
        public ?string $path,
        public ?string $name,
        public int|float|null $durationMilliseconds,
        public int|float|null $memoryMegabytes,
    ) {
        //
    }
}
