<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
readonly class RankSnapshot
{
    /**
     * Create a new rank snapshot instance.
     *
     * @param  list<RecordType>  $held
     * @param  array{rows: list<array<string, mixed>>, keys: list<array{value: int|float|null, occurrences: int, hash: string}>, records: int, withoutGroup: int, untimed: int, orderedBy: Measure}|null  $ranking
     * @param  array{rows: list<array<string, mixed>>, matched: int, records: int, label: string}|null  $breakdown
     */
    public function __construct(
        public int $total,
        public int $inWindow,
        public ?float $oldest,
        public ?float $newest,
        public StoreFacts $facts,
        public ?RecordType $type,
        public array $held,
        public ?array $ranking = null,
        public ?array $breakdown = null,
    ) {
        //
    }
}
