<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Closure;

/**
 * @internal
 */
class Bounds
{
    /**
     * The most characters a cell has.
     */
    public const CELL_CHARACTERS = 2000;

    /**
     * The most characters an answer has, estimated from the 8,000 tokens of its hard budget at 3 characters to a token.
     */
    public const ANSWER_CHARACTERS = 24000;

    /**
     * Cut every cell of a result to its cap, and state how many each section had cut.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @return array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}
     */
    public static function capCells(array $result, array $truncated): array
    {
        foreach ($result as $section => $value) {
            $cut = 0;
            $result[$section] = self::cap($value, $cut);

            if ($cut > 0) {
                $how = __('firewatch::messages.cap_how', ['characters' => number_format(self::CELL_CHARACTERS), 'next' => self::CELL_CHARACTERS + 1]);

                $truncated[] = [
                    'section' => (string) $section,
                    'shown' => $cut,
                    'matched' => null,
                    'reason' => TruncationReason::CAP->value,
                    'how' => $how,
                ];
            }
        }

        return [$result, $truncated];
    }

    /**
     * Count the cells cut again for the rows the budget kept, so a row it dropped doesn't count.
     *
     * @param  array<string, mixed>  $original  the result before any cut
     * @param  array<string, mixed>  $fitted  the result after the budget
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    public static function recountCaps(array $original, array $fitted, array $truncated): array
    {
        $kept = array_values(array_filter($truncated, fn (array $entry) => $entry['reason'] !== TruncationReason::CAP->value));

        foreach ($fitted as $section => $value) {
            if (is_array($value) && array_is_list($value)) {
                $original[$section] = array_slice($original[$section], 0, count($value));
            }
        }

        [, $recounted] = self::capCells($original, $kept);

        return $recounted;
    }

    /**
     * Cut a value's strings to the cell cap on a character boundary, counting the cells cut.
     */
    protected static function cap(mixed $value, int &$cut): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::cap($item, $cut);
            }

            return $value;
        }

        if (! is_string($value) || mb_strlen($value) <= self::CELL_CHARACTERS) {
            return $value;
        }

        $cut++;

        $notice = __('firewatch::messages.cell_truncated', ['count' => mb_strlen($value) - self::CELL_CHARACTERS]);

        return mb_substr($value, 0, self::CELL_CHARACTERS).$notice;
    }

    /**
     * Drop whole rows from the tail of the lowest-priority list until the answer fits its budget, and state each list it cut.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  Closure(array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>): int  $size  the characters of the answer with the given result and truncated entries
     * @return array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}
     */
    public static function fitAnswer(array $result, array $truncated, Closure $size): array
    {
        $isList = fn (mixed $value) => is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0]);
        $lists = array_keys(array_filter($result, $isList));
        $matched = [];

        foreach (array_reverse($lists, preserve_keys: true) as $position => $section) {
            // The first row of the first list is never dropped: one row over the budget is returned whole.
            $floor = $position === 0 ? 1 : 0;

            while (count($result[$section]) > $floor) {
                $cuts = self::withCuts($truncated, $result, $matched);

                if ($size($result, $cuts) <= self::ANSWER_CHARACTERS) {
                    break;
                }

                $matched[$section] ??= count($result[$section]);
                array_pop($result[$section]);
            }
        }

        return [$result, self::withCuts($truncated, $result, $matched)];
    }

    /**
     * Get the truncated entries with a cut by size for each list the budget cut, replacing the entry a limit made of the same list.
     *
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $originals  the rows each cut list had
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    protected static function withCuts(array $truncated, array $result, array $originals): array
    {
        $entries = [];

        foreach ($originals as $section => $original) {
            $earlier = array_values(array_filter($truncated, fn (array $entry) => $entry['section'] === (string) $section && $entry['reason'] === TruncationReason::LIMIT->value))[0] ?? null;

            $entries[(string) $section] = [
                'section' => (string) $section,
                'shown' => count($result[$section]),
                'matched' => $earlier === null ? $original : $earlier['matched'],
                'reason' => TruncationReason::SIZE->value,
                'how' => __('firewatch::messages.size_how', ['characters' => number_format(self::ANSWER_CHARACTERS)]),
            ];
        }

        $kept = array_values(array_filter($truncated, fn (array $entry) => ! ($entry['reason'] === TruncationReason::LIMIT->value && isset($entries[$entry['section']]))));

        return [...$kept, ...array_values($entries)];
    }
}
