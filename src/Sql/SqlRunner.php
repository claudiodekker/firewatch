<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Store\StoreUnusable;

/**
 * @internal
 */
interface SqlRunner
{
    /**
     * Run one read-only statement of the assistant in the SQL child and get its rows.
     *
     * @param  int<1, 500>  $limit
     *
     * @throws StoreUnusable when the child found the store absent, foreign, mismatched, corrupt or busy
     * @throws SqlFailure when the statement was refused or could not be run
     */
    public function run(string $sql, int $limit): SqlRows;

    /**
     * Get why the runner can't run a statement on this machine without trying one, or null when nothing rules it out.
     */
    public function unavailable(): ?Unavailable;

    /**
     * Get why the runner can't run a statement, trying one, or null when it can.
     */
    public function probe(): ?Unavailable;

    /**
     * Get the ceilings a statement is held to.
     */
    public function limits(): Limits;

    /**
     * Get the PHP binary the runner's child is started with.
     */
    public function phpBinary(): string;
}
