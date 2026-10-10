<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
readonly class RankedSnapshot extends RankSnapshot
{
    /**
     * Create a new ranked snapshot instance.
     *
     * @param  array{rows: list<array<string, mixed>>, keys: list<array{value: int|float|null, occurrences: int, hash: string}>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}  $ranking
     */
    public function __construct(int $total, int $inWindow, ?float $oldest, ?float $newest, StoreFacts $facts, public RecordType $type, public Measure $by, public array $ranking)
    {
        parent::__construct($total, $inWindow, $oldest, $newest, $facts, [$type]);
    }
}
