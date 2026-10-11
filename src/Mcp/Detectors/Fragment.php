<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;

/**
 * @internal
 */
readonly class Fragment
{
    /**
     * Create a new fragment instance.
     *
     * @param  array<string, int|float|string|null>  $bindings  the values the SQL names, apart from the bounds of a window
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {
        //
    }

    /**
     * Get the condition of the records that started in the window, of one group when the call names it.
     */
    public static function selecting(Window $window, ?string $group): self
    {
        return new self($window->condition().' AND (:group IS NULL OR group_hash = :group)', ['group' => $group]);
    }

    /**
     * Get the columns of when and to whom the records that meet a predicate happened: the first and last time they started, the signed-in actors they reached and the records without one.
     *
     * Every record counts when no predicate is given.
     */
    public static function reach(?string $predicate = null): string
    {
        $filter = $predicate === null ? '' : " FILTER (WHERE {$predicate})";
        $anonymous = $predicate === null ? '' : "({$predicate}) AND ";

        return "min(started_at){$filter} AS first_seen, max(started_at){$filter} AS last_seen,
            count(DISTINCT NULLIF(user_id, '')){$filter} AS actors,
            count(*) FILTER (WHERE {$anonymous}NULLIF(user_id, '') IS NULL) AS anonymous";
    }
}
