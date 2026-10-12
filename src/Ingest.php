<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Actions\AppendBatch;
use ClaudioDekker\Firewatch\Capture\InvalidFields;
use ClaudioDekker\Firewatch\Capture\QueryBindings;
use ClaudioDekker\Firewatch\Capture\TestRecord;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Pruner;
use ClaudioDekker\Firewatch\Store\Writer;
use Closure;
use JsonSerializable;
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
     * The longest a test run waits for a busy store, in milliseconds, as its parallel workers share the store.
     */
    public const TEST_RUN_BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * The records waiting for the next digest, in the order the sensors wrote them.
     *
     * @var list<array<mixed>>
     */
    protected array $buffer = [];

    /**
     * The bindings paired to each buffered record.
     *
     * @var list<list<mixed>|null>
     */
    protected array $bindings = [];

    /**
     * The fields that failed validation paired to each buffered record.
     *
     * @var list<list<string>|null>
     */
    protected array $pairedInvalidFields = [];

    /**
     * Where each buffered record fell in a test run, or null outside a test run.
     *
     * @var list<TestRecord|null>
     */
    protected array $testRecords = [];

    /**
     * Tell where a record a test runs falls, while Firewatch records a test run.
     *
     * @var (Closure(): TestRecord)|null
     */
    protected ?Closure $testRecord = null;

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
        protected InvalidFields $invalidFields,
        protected FailureLog $failures,
        protected Pruner $pruner,
        protected Writer $writer,
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

        $testRecord = $this->testRecord?->__invoke();

        // A test's last records are stored after its application is gone, so what runs between executions resolves its lazy fields now.
        if ($testRecord?->inExecution === false) {
            $record = array_map(fn (mixed $value) => $value instanceof JsonSerializable ? $value->jsonSerialize() : $value, $record);
        }

        if ($this->isBufferFull()) {
            array_shift($this->buffer);
            array_shift($this->bindings);
            array_shift($this->pairedInvalidFields);
            array_shift($this->testRecords);
        }

        $this->buffer[] = $record;
        $this->bindings[] = $this->queryBindings->pair($record);
        $this->pairedInvalidFields[] = $this->invalidFields->pair($record);
        $this->testRecords[] = $testRecord;

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

        $this->store([$record], bindings: [], testRecords: [$this->testRecord?->__invoke()], invalidFields: [$this->invalidFields->pair($record)]);
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
        $testRecords = $this->testRecords;
        $invalidFields = $this->pairedInvalidFields;

        $this->flush();

        $this->store($records, bindings: $bindings, testRecords: $testRecords, invalidFields: $invalidFields);
    }

    /**
     * Discard the buffered records.
     */
    public function flush(): void
    {
        $this->buffer = [];
        $this->bindings = [];
        $this->testRecords = [];
        $this->pairedInvalidFields = [];
    }

    /**
     * Record a test run, asking where each record the test writes falls.
     *
     * @param  Closure(): TestRecord  $testRecord
     */
    public function recordTestRun(Closure $testRecord): void
    {
        $this->testRecord = $testRecord;
    }

    /**
     * Determine if the buffer holds as many records as it can.
     */
    protected function isBufferFull(): bool
    {
        return count($this->buffer) >= static::BUFFER_LENGTH;
    }

    /**
     * Store a batch, dropping and recording it on any failure.
     *
     * @param  list<array<mixed>>  $records
     * @param  list<list<mixed>|null>  $bindings
     * @param  list<TestRecord|null>  $testRecords
     * @param  list<list<string>|null>  $invalidFields
     */
    protected function store(array $records, array $bindings, array $testRecords, array $invalidFields): void
    {
        $this->storing = true;

        try {
            if ($this->testRecord === null) {
                $this->append($records, $bindings, $testRecords, $invalidFields);

                return;
            }

            $this->writer->waitingUpTo(static::TEST_RUN_BUSY_TIMEOUT_MILLISECONDS, fn () => $this->append($records, $bindings, $testRecords, $invalidFields));
        } finally {
            $this->storing = false;
        }
    }

    /**
     * Append a batch and prune after it, recording the batch as dropped when it can't be written.
     *
     * @param  list<array<mixed>>  $records
     * @param  list<list<mixed>|null>  $bindings
     * @param  list<TestRecord|null>  $testRecords
     * @param  list<list<string>|null>  $invalidFields
     */
    protected function append(array $records, array $bindings, array $testRecords, array $invalidFields): void
    {
        try {
            $this->appendBatch->handle($records, bindings: $bindings, testRecords: $testRecords, invalidFields: $invalidFields);
        } catch (Throwable $exception) {
            $this->failures->record($exception, dropped: count($records));

            if (FailureKind::of($exception) === FailureKind::FULL) {
                $this->prune($this->pruner->runNow(...));
            }

            return;
        }

        $this->prune($this->pruner->run(...));
    }

    /**
     * Run a pruning pass, recording any failure of it.
     *
     * @param  Closure(): void  $pass
     */
    protected function prune(Closure $pass): void
    {
        try {
            $pass();
        } catch (Throwable $exception) {
            $this->failures->pruneFailed($exception);
        }
    }
}
