<?php

namespace ClaudioDekker\Firewatch\Sql\Child;

/**
 * @internal
 */
enum Denied: string
{
    case FUNCTION = 'function';
    case TABLE = 'table';
    case ACTION = 'action';
    case SECOND_STATEMENT = 'second_statement';
    case NO_COLUMNS = 'no_columns';
    case TOO_LONG = 'too_long';
    case NUL = 'nul';
}
