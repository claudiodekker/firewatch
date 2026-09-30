<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Actions\AppendBatch;
use Laravel\Nightwatch\Contracts\Ingest as IngestContract;
use Throwable;

/**
 * @internal
 */
class Ingest implements IngestContract
{
    /**
     * The number of records the buffer holds, as Nightwatch's own buffer does.
     */
    protected const BUFFER_LENGTH = 500;

    /**
     * The records waiting for the next digest, in the order the sensors wrote them.
     *
     * @var list<array<mixed>>
     */
    protected array $buffer = [];

    /**
     * Whether a full buffer is stored at once, rather than dropping its oldest record.
     */
    protected bool $digestsWhenBufferIsFull = true;

    /**
     * Whether a batch is being stored, so that what the sensors record meanwhile is ignored.
     */
    protected bool $storing = false;

    /**
     * Create a new ingest instance.
     */
    public function __construct(protected AppendBatch $appendBatch)
    {
        //
    }

    /**
     * Buffer a record, storing the buffer once it is full.
     *
     * @param  array<mixed>  $record
     */
    public function write(array $record): void
    {
        if ($this->storing) {
            return;
        }

        if ($this->isBufferFull()) {
            array_shift($this->buffer);
        }

        $this->buffer[] = $record;

        if ($this->digestsWhenBufferIsFull && $this->isBufferFull()) {
            $this->digest();
        }
    }

    /**
     * Store a record at once, apart from the buffer.
     *
     * @param  array<mixed>  $record
     */
    public function writeNow(array $record): void
    {
        $this->store([$record]);
    }

    /**
     * Skip the ping, as there is no agent to reach.
     */
    public function ping(): void
    {
        //
    }

    /**
     * Set whether a full buffer is stored at once.
     */
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    /**
     * Set whether a full buffer is stored at once.
     */
    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->digestsWhenBufferIsFull = $bool;
    }

    /**
     * Store the buffered records as one batch.
     */
    public function digest(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $records = $this->buffer;

        $this->flush();

        $this->store($records);
    }

    /**
     * Discard the buffered records.
     */
    public function flush(): void
    {
        $this->buffer = [];
    }

    /**
     * Determine if the buffer holds as many records as it can.
     */
    protected function isBufferFull(): bool
    {
        return count($this->buffer) >= static::BUFFER_LENGTH;
    }

    /**
     * Store a batch, keeping any failure from the host application.
     *
     * @param  list<array<mixed>>  $records
     */
    protected function store(array $records): void
    {
        $this->storing = true;

        try {
            $this->appendBatch->handle($records);
        } catch (Throwable $exception) {
            $this->report($exception);
        } finally {
            $this->storing = false;
        }
    }

    /**
     * Report a failed batch, swallowing a failure of the report itself.
     */
    protected function report(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            //
        }
    }
}
