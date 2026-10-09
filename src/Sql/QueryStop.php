<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * @internal
 */
enum QueryStop: string
{
    case COMPLETE = 'complete';
    case LIMIT = 'limit';
}
