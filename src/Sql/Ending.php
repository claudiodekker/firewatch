<?php

namespace ClaudioDekker\Firewatch\Sql;

/**
 * Why the parent stopped reading the child.
 *
 * @internal
 */
enum Ending
{
    case EXITED;
    case DEADLINE;
    case OUTPUT_CAP;
}
