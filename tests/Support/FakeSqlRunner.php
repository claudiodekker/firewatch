<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Sql\SqlRows;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use Closure;

/**
 * The fake SQL runner: answers every call through a callback, for an outcome no real child can be timed into.
 */
class FakeSqlRunner implements SqlRunner
{
    /**
     * Create a new fake SQL runner instance.
     *
     * @param  Closure(): SqlRows  $outcome
     */
    public function __construct(protected Closure $outcome)
    {
        //
    }

    /**
     * Answer the call through the callback.
     */
    public function run(string $sql, int $limit): SqlRows
    {
        return ($this->outcome)();
    }
}
