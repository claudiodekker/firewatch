<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
abstract readonly class RankSnapshot
{
    /**
     * Create a new rank snapshot instance.
     *
     * @param  list<RecordType>  $types
     */
    public function __construct(
        public int $total,
        public int $inWindow,
        public ?float $oldest,
        public ?float $newest,
        public StoreFacts $facts,
        public array $types,
    ) {
        //
    }
}
