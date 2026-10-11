<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
readonly class Examination
{
    /**
     * The condition that counts every execution as examined.
     */
    protected const EVERY = 'TRUE';

    /**
     * The condition that counts an execution as examined when it captured a query.
     */
    protected const QUERIED = 'captured > 0';

    /**
     * Create a new examination instance.
     */
    public function __construct(
        public Fragment $executions,
        public int $examined,
        public int $incomplete,
    ) {
        //
    }

    /**
     * Get the executions of the types that started in the window whose queries history did not remove, each examined.
     *
     * @param  list<RecordType>  $types
     */
    public static function every(SQLite3 $connection, Markers $meta, Window $window, ?string $group, array $types): self
    {
        return self::examining($connection, $meta, $window, $group, $types, self::EVERY);
    }

    /**
     * Get the executions of the types that started in the window whose queries history did not remove, examined when they captured a query.
     *
     * @param  list<RecordType>  $types
     */
    public static function queried(SQLite3 $connection, Markers $meta, Window $window, ?string $group, array $types): self
    {
        return self::examining($connection, $meta, $window, $group, $types, self::QUERIED);
    }

    /**
     * Get the examination, counting as examined the executions that meet the condition.
     *
     * @param  list<RecordType>  $types
     */
    protected static function examining(SQLite3 $connection, Markers $meta, Window $window, ?string $group, array $types, string $examinedWhere): self
    {
        $executions = Executions::covered($meta, RecordType::QUERY, $window, $group, $types);

        [$row] = Stored::rows($connection, "{$executions->sql} SELECT count(*) FILTER (WHERE {$examinedWhere}) AS examined, count(*) FILTER (WHERE {$examinedWhere} AND counted > captured) AS incomplete
            FROM (SELECT counted, (SELECT count(*) FROM queries WHERE queries.execution_id = executions.execution_id) AS captured FROM executions)", $executions->bindings, $window);

        return new self($executions, $row['examined'], $row['incomplete']);
    }

    /**
     * Get the caveat of the examined executions that captured fewer queries than they counted, when there are any.
     *
     * @return list<string>
     */
    public function caveats(): array
    {
        return $this->incomplete > 0 ? [trans_choice('firewatch::messages.detect_caveat_incomplete', $this->incomplete, ['count' => $this->incomplete])] : [];
    }
}
