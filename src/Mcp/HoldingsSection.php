<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\History;
use ClaudioDekker\Firewatch\Store\Holdings;
use ClaudioDekker\Firewatch\Store\Markers;

/**
 * @internal
 */
class HoldingsSection
{
    /**
     * Get one row per event type with its span and the instant its history is complete from.
     *
     * @return list<array<string, mixed>>
     */
    public static function types(Holdings $holdings, Markers $meta): array
    {
        return array_map(function (RecordType $type) use ($holdings, $meta) {
            $history = History::of($meta, [$type]);

            return [
                'type' => $type->value,
                'object' => Catalogue::object($type),
                'records' => $holdings->of($type),
                'oldest_at' => $holdings->types[$type->value]['oldest'] ?? null,
                'newest_at' => $holdings->types[$type->value]['newest'] ?? null,
                'complete_from_at' => $history->from,
                'complete_reason' => $history->reason?->value,
            ];
        }, RecordType::events());
    }

    /**
     * Get the `truncated` entry of the deploys the limit cut, or null when every deploy is listed.
     *
     * @return array{section: string, shown: int, matched: int|null, reason: string, how: string}|null
     */
    public static function truncation(Holdings $holdings): ?array
    {
        if (count($holdings->deploys) <= Holdings::DEPLOYS) {
            return null;
        }

        return [
            'section' => 'deploys',
            'shown' => Holdings::DEPLOYS,
            'matched' => null,
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.describe_deploys_how'),
        ];
    }
}
