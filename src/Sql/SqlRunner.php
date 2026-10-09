<?php

namespace ClaudioDekker\Firewatch\Sql;

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
}
