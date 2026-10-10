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
        return new self($window->condition().' AND (:group = \'\' OR group_hash = :group)', ['group' => $group ?? '']);
    }
}
