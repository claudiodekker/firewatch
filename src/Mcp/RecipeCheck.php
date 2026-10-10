<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * Whether recomputing a type's recipe from its recent stored records reproduces the group hash they were stored with.
 *
 * @internal
 */
enum RecipeCheck: string
{
    case AGREES = 'agrees';
    case DISAGREES = 'disagrees';
    case NOT_EVALUATED = 'not_evaluated';

    /**
     * Get what the checks of the types one group id covers say together: any disagreement wins, then any agreement.
     *
     * @param  list<self>  $checks
     */
    public static function together(array $checks): self
    {
        if (in_array(self::DISAGREES, $checks, true)) {
            return self::DISAGREES;
        }

        if (in_array(self::AGREES, $checks, true)) {
            return self::AGREES;
        }

        return self::NOT_EVALUATED;
    }
}
