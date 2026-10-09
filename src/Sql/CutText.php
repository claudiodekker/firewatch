<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * A text cell the child cut at the cell cap, with the number of characters it left out.
 *
 * @internal
 */
final readonly class CutText
{
    /**
     * Create a new cut text instance.
     */
    public function __construct(
        public string $text,
        public int $omitted,
    ) {
        //
    }
}
