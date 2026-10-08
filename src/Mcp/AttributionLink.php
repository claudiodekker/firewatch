<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum AttributionLink: string
{
    case DIRECT = 'direct';
    case DISPATCH = 'dispatch';
    case INSIDE = 'inside';
    case OTHER_ACTOR = 'other_actor';
    case UNATTRIBUTABLE = 'unattributable';

    /**
     * Get the cases that tie work to the person, as opposed to the two that say why nothing does.
     *
     * @return list<self>
     */
    public static function links(): array
    {
        return [self::DIRECT, self::DISPATCH, self::INSIDE];
    }

    /**
     * Determine whether the case ties work to the person.
     */
    public function isLink(): bool
    {
        return in_array($this, self::links(), true);
    }
}
