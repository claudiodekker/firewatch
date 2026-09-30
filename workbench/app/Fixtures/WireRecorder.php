<?php

namespace Workbench\App\Fixtures;

use Laravel\Nightwatch\Contracts\Ingest;

class WireRecorder implements Ingest
{
    /**
     * The records the sensors wrote, in order.
     *
     * @var list<array<mixed>>
     */
    public array $records = [];

    /**
     * Record a record.
     *
     * @param  array<mixed>  $record
     */
    public function write(array $record): void
    {
        $this->records[] = $record;
    }

    /**
     * Record a record written at once.
     *
     * @param  array<mixed>  $record
     */
    public function writeNow(array $record): void
    {
        $this->records[] = $record;
    }

    /**
     * Skip the ping.
     */
    public function ping(): void
    {
        //
    }

    /**
     * Ignore the digest setting, as every record is kept.
     */
    public function shouldDigest(bool $bool = true): void
    {
        //
    }

    /**
     * Ignore the digest setting, as every record is kept.
     */
    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        //
    }

    /**
     * Keep the records, as there is nowhere to send them.
     */
    public function digest(): void
    {
        //
    }

    /**
     * Keep the records, as every execution is recorded.
     */
    public function flush(): void
    {
        //
    }
}
