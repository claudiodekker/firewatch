<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum TruncationReason: string
{
    case CAP = 'cap';
    case LIMIT = 'limit';
    case SIZE = 'size';
}
