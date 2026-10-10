<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Sql\CutText;
use Closure;

/**
 * @internal
 */
class Bounds
{
    /**
     * The most characters a cell has.
     */
    public const CELL_CHARACTERS = Policy::CELL_CHARACTERS;

    /**
     * The tokens of the hard budget of an answer.
     */
    protected const ANSWER_TOKENS = 8000;

    /**
     * The characters estimated to a token.
     */
    protected const CHARACTERS_PER_TOKEN = 3;

    /**
     * The most characters an answer has.
     */
    public const ANSWER_CHARACTERS = self::ANSWER_TOKENS * self::CHARACTERS_PER_TOKEN;

    /**
     * Cut every cell of a result to its cap, and state how many each section had cut.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  string  $how  what the cap entry tells the reader to do about the cut
     * @return array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}
     */
    public static function capCells(array $result, array $truncated, string $how): array
    {
        foreach ($result as $section => $value) {
            $cut = 0;
            $result[$section] = self::cap($value, $cut);

            if ($cut > 0) {
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
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $fitted
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @return list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>
     */
    public static function recountCaps(array $original, array $fitted, array $truncated, string $how): array
    {
        $kept = array_values(array_filter($truncated, fn (array $entry) => $entry['reason'] !== TruncationReason::CAP->value));

        foreach ($fitted as $section => $value) {
            if (is_array($value) && array_is_list($value)) {
                $original[$section] = array_slice($original[$section], 0, count($value));
            }
        }

        [, $recounted] = self::capCells($original, $kept, $how);

        return $recounted;
    }

    /**
     * Cut a value's strings to the cell cap on a character boundary, counting the cells cut, and show a cell the child cut with its notice.
     */
    protected static function cap(mixed $value, int &$cut): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::cap($item, $cut);
            }

            return $value;
        }

        if ($value instanceof CutText) {
            $cut++;

            return $value->text.__('firewatch::messages.cell_truncated', ['count' => $value->omitted]);
        }

        if (! is_string($value) || mb_strlen($value) <= self::CELL_CHARACTERS) {
            return $value;
        }

        $cut++;

        $notice = __('firewatch::messages.cell_truncated', ['count' => mb_strlen($value) - self::CELL_CHARACTERS]);

        return mb_substr($value, 0, self::CELL_CHARACTERS).$notice;
    }

    /**
     * Drop whole rows from the tail of the lists in cut order until the answer fits its budget, and state each list it cut.
     *
     * The last list of the cut order keeps its first row, so one row over the budget is returned whole.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  Closure(array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>): int  $size
     * @param  list<string>|null  $cuttable  the lists that may lose rows, the first named first; null for every list, the last first
     * @return array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}
     */
    public static function fitAnswer(array $result, array $truncated, Closure $size, ?array $cuttable = null): array
    {
        $isList = fn (mixed $value) => is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0]);
        $lists = array_keys(array_filter($result, $isList));
        $order = $cuttable === null ? array_reverse($lists) : array_values(array_intersect($cuttable, $lists));
        $matched = [];

        foreach ($order as $position => $section) {
            $floor = $position === array_key_last($order) ? 1 : 0;

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
     * @param  array<string, int>  $originals
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
