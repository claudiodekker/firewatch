<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum AccountingState: string
{
    case MATCH = 'match';
    case FEWER = 'fewer';
    case MORE = 'more';

    /**
     * Get how the children captured compare with what the counter counted.
     */
    public static function of(int $counted, int $captured): self
    {
        return match (true) {
            $captured < $counted => self::FEWER,
            $captured > $counted => self::MORE,
            default => self::MATCH,
        };
    }
}
