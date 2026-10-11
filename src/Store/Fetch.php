<?php

namespace ClaudioDekker\Firewatch\Store;

use Closure;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Fetch
{
    /**
     * Read the rows of a query in the snapshot of the connection.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @param  (Closure(SQLite3Stmt): void)|null  $bind  binds what the bindings by name cannot, such as a typed bound
     * @return list<array<string, mixed>>
     */
    public static function rows(SQLite3 $connection, string $sql, array $bindings = [], ?Closure $bind = null): array
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare($sql);

        foreach ($bindings as $name => $value) {
            $statement->bindValue(":{$name}", $value);
        }

        if ($bind !== null) {
            $bind($statement);
        }

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $rows = [];

        while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $row;
        }

        return $rows;
    }
}
