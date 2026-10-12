<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
class MappedRecord
{
    /**
     * Create a new mapped record instance.
     *
     * @param  array<string, mixed>|null  $record
     * @param  array{id: string, name: mixed, username: mixed, seen_at: int|float|null}|null  $user
     */
    public function __construct(
        public readonly ?array $record = null,
        public readonly ?array $user = null,
    ) {
        //
    }
}
