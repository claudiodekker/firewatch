<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\PruneReason;

/**
 * @internal
 */
enum HistoryReason: string
{
    case CREATED = 'created';
    case CLEARED = 'cleared';
    case CLEARED_TYPE = 'cleared-type';
    case PRUNED_AGE = 'pruned-'.PruneReason::AGE->value;
    case PRUNED_CAP = 'pruned-'.PruneReason::CAP->value;
    case PRUNED_SIZE = 'pruned-'.PruneReason::SIZE->value;

    /**
     * Get the reason for a prune by a rule.
     */
    public static function pruned(PruneReason $reason): self
    {
        return match ($reason) {
            PruneReason::AGE => self::PRUNED_AGE,
            PruneReason::CAP => self::PRUNED_CAP,
            PruneReason::SIZE => self::PRUNED_SIZE,
        };
    }

    /**
     * Determine if the reason is a prune.
     */
    public function isPrune(): bool
    {
        return match ($this) {
            self::PRUNED_AGE, self::PRUNED_CAP, self::PRUNED_SIZE => true,
            self::CREATED, self::CLEARED, self::CLEARED_TYPE => false,
        };
    }
}
