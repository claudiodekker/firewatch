<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Actions\AppendBatch;
use ClaudioDekker\Firewatch\Capture\QueryBindings;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Pruner;
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
     * The bindings paired to each buffered record, null for any record without them.
     *
     * @var list<list<mixed>|null>
     */
    protected array $bindings = [];

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
    public function __construct(
        protected AppendBatch $appendBatch,
        protected QueryBindings $queryBindings,
        protected FailureLog $failures,
        protected Pruner $pruner,
    ) {
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
            array_shift($this->bindings);
        }

        $this->buffer[] = $record;
        $this->bindings[] = $this->queryBindings->pair($record);

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
        if ($this->storing) {
            return;
        }

        $this->store([$record], []);
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
        $bindings = $this->bindings;

        $this->flush();

        $this->store($records, $bindings);
    }

    /**
     * Discard the buffered records.
     */
    public function flush(): void
    {
        $this->buffer = [];
        $this->bindings = [];
    }

    /**
     * Determine if the buffer holds as many records as it can.
     */
    protected function isBufferFull(): bool
    {
        return count($this->buffer) >= static::BUFFER_LENGTH;
    }

    /**
     * Store a batch, dropping and recording it on any failure, which never reaches the host application.
     *
     * @param  list<array<mixed>>  $records
     * @param  list<list<mixed>|null>  $bindings
     */
    protected function store(array $records, array $bindings): void
    {
        $this->storing = true;

        try {
            $this->appendBatch->handle($records, $bindings);
        } catch (Throwable $exception) {
            $this->failures->record($exception, dropped: count($records));

            $this->storing = false;

            return;
        }

        try {
            $this->pruner->run();
        } catch (Throwable $exception) {
            $this->failures->record($exception, dropped: 0);
        } finally {
            $this->storing = false;
        }
    }
}
