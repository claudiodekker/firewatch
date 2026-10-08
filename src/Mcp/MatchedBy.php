<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum MatchedBy: string
{
    case ID = 'id';
    case USERNAME = 'username';
    case NAME = 'name';
    case CONTAINS = 'contains';
    case RECORDS = 'records';
}
