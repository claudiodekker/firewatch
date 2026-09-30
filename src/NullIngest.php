<?php

namespace ClaudioDekker\Firewatch;

use Laravel\Nightwatch\Contracts\Ingest;

/**
 * @internal
 */
class NullIngest implements Ingest
{
    /**
     * Discard a buffered record.
     *
     * @param  array<mixed>  $record
     */
    public function write(array $record): void
    {
        //
    }

    /**
     * Discard a record that would be sent at once.
     *
     * @param  array<mixed>  $record
     */
    public function writeNow(array $record): void
    {
        //
    }

    /**
     * Skip the ping to the agent.
     */
    public function ping(): void
    {
        //
    }

    /**
     * Ignore whether the buffer digests when full.
     */
    public function shouldDigest(bool $bool = true): void
    {
        //
    }

    /**
     * Ignore whether the buffer digests when full.
     */
    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        //
    }

    /**
     * Skip the digest, since nothing is buffered.
     */
    public function digest(): void
    {
        //
    }

    /**
     * Skip the flush, since nothing is buffered.
     */
    public function flush(): void
    {
        //
    }
}
