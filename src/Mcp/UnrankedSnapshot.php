<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
readonly class UnrankedSnapshot extends RankSnapshot
{
    /**
     * Create a new unranked snapshot instance.
     *
     * @param  list<RecordType>  $types
     * @param  list<RecordType>  $held
     */
    public function __construct(int $total, int $inWindow, ?float $oldest, ?float $newest, StoreFacts $facts, array $types, public array $held)
    {
        parent::__construct($total, $inWindow, $oldest, $newest, $facts, $types);
    }
}
