<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;

/**
 * @internal
 */
readonly class BrokenDownSnapshot extends RankSnapshot
{
    /**
     * Create a new broken-down snapshot instance.
     *
     * @param  list<RecordType>  $held
     * @param  array{rows: list<array<string, mixed>>, matched: int, records: int, label: string, stages: StageView|null}  $breakdown
     */
    public function __construct(int $total, int $inWindow, ?float $oldest, ?float $newest, StoreFacts $facts, public RecordType $type, public string $group, public array $held, public array $breakdown)
    {
        parent::__construct($total, $inWindow, $oldest, $newest, $facts, [$type]);
    }
}
