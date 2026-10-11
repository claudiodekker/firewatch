<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Sql\Limits;
use ClaudioDekker\Firewatch\Sql\SqlRows;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use Closure;
use LogicException;

/**
 * The fake SQL runner: answers every call through a callback, for an outcome no real child can be timed into, and reports the availability it is given without spawning anything.
 */
class FakeSqlRunner implements SqlRunner
{
    /**
     * Create a new fake SQL runner instance.
     *
     * @param  (Closure(): SqlRows)|null  $outcome  the answer to a run, none when the test must not run a statement
     * @param  Unavailable|null  $reason  why the runner reports itself unavailable, null when it can run
     */
    public function __construct(protected ?Closure $outcome = null, protected ?Unavailable $reason = null)
    {
        //
    }

    /**
     * Answer the call through the callback.
     */
    public function run(string $sql, int $limit): SqlRows
    {
        return ($this->outcome ?? throw new LogicException('The fake SQL runner was given no outcome to run.'))();
    }

    /**
     * Report the reason the fake was given.
     */
    public function unavailable(): ?Unavailable
    {
        return $this->reason;
    }

    /**
     * Report the reason the fake was given, as no child is spawned to try.
     */
    public function probe(): ?Unavailable
    {
        return $this->reason;
    }

    /**
     * Report the real ceilings.
     */
    public function limits(): Limits
    {
        return new Limits;
    }

    /**
     * Report the binary of this process.
     */
    public function phpBinary(): string
    {
        return PHP_BINARY;
    }
}
