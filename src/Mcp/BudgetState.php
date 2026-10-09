<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum BudgetState: string
{
    case WITHIN = 'within';
    case EXCEEDED = 'exceeded';
    case NOT_EVALUATED = 'not_evaluated';
}
