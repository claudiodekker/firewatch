<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-import-type Side from Comparison
 */
class SplitPoint implements Boundary
{
    /**
     * How long before the split an execution may have started to be counted as straddling it, in seconds.
     */
    protected const STRADDLING_SECONDS = 3600;

    /**
     * How much older than the split the oldest record of the store must be for the note to move `since`, in seconds.
     */
    protected const EARLIER_CHANGES_SECONDS = 3600;

    /**
     * A valid call, as a refusal shows it.
     */
    public const EXAMPLE = 'compare(type: "request", split_at: "<now of an earlier answer>")';

    /**
     * Create a new split point instance.
     */
    public function __construct(
        public readonly float $instant,
    ) {
        //
    }

    /**
     * Get the window before the split and the window from it on, only the before side counting the records that started before the coverage start.
     *
     * @return array{Side, Side}
     */
    public function sides(Window $window, ?float $coverageStart): array
    {
        return [
            Comparison::side(since: $window->since(), until: $this->instant, deploy: null, coverageStart: $coverageStart, countsEarlier: true),
            Comparison::side(since: $this->instant, until: $window->until(), deploy: null, coverageStart: $coverageStart, countsEarlier: false),
        ];
    }

    /**
     * Refuse a window the split does not lie strictly inside.
     */
    public function refuseOutside(Window $window): void
    {
        if ($this->instant <= ($window->since() ?? -INF) || $this->instant >= ($window->until() ?? INF)) {
            throw Refusal::splitOutsideWindow(self::EXAMPLE);
        }
    }

    /**
     * Determine if the boundary is an instant, which a split is.
     */
    public function isInstant(): bool
    {
        return true;
    }

    /**
     * Count the executions of the type that started in the hour before the split, on the before side, and ended after it, or get null for a type that is no execution.
     *
     * @param  Side  $before
     */
    public function straddling(SQLite3 $connection, RecordType $type, array $before, ?string $group): ?int
    {
        if (! $type->isExecution()) {
            return null;
        }

        if ($before['outside']) {
            return 0;
        }

        $from = max($before['since'] ?? -INF, $this->instant - self::STRADDLING_SECONDS);
        $rows = Stored::rows($connection, 'SELECT count(*) AS straddling FROM '.$type->view().' WHERE started_at >= :from AND started_at < :split AND ended_at > :split AND (:group IS NULL OR group_hash = :group)', [
            'from' => $from,
            'split' => $this->instant,
            'group' => $group,
        ]);

        return is_int($rows[0]['straddling']) ? $rows[0]['straddling'] : 0;
    }

    /**
     * Get the split among the filters of the call, where it names none.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Get the summary of a comparison that ran, before and after the split.
     */
    public function summary(Comparison $comparison): string
    {
        return trans_choice('firewatch::messages.compare_summary', $comparison->matched(), [
            'groups' => $comparison->matched(),
            'type' => $comparison->type->value,
            'by' => $comparison->by->value,
            'changes' => $comparison->counts(),
        ]);
    }

    /**
     * Get the summary of a comparison whose side holds no record of the type.
     */
    public function emptySideSummary(Comparison $comparison, string $side): string
    {
        return __('firewatch::messages.compare_empty_side_summary', [
            'side' => $side,
            'type' => $comparison->type->value,
        ]);
    }

    /**
     * Get the note of a comparison that ran on nothing, which a later split or since may fix.
     */
    public function notEvaluatedNote(): string
    {
        return __('firewatch::messages.compare_not_evaluated_note');
    }

    /**
     * Get the count of the records that started before the coverage start, then the note to move `since` when the store holds changes much older than the split.
     *
     * @return list<string>
     */
    public function notes(Comparison $comparison, bool $sinceOmitted, ?float $oldest): array
    {
        $notes = [];
        $earlier = $comparison->before['earlier'];

        if ($earlier !== null && $earlier['records'] > 0) {
            $notes[] = trans_choice('firewatch::messages.'.($earlier['more'] ? 'compare_earlier_more_note' : 'compare_earlier_note'), $earlier['records'], [
                'count' => $earlier['records'],
                'type' => $comparison->type->value,
            ]);
        }

        if ($sinceOmitted && $oldest !== null && $oldest < $this->instant - self::EARLIER_CHANGES_SECONDS) {
            $notes[] = __('firewatch::messages.compare_move_since_note');
        }

        return $notes;
    }

    /**
     * Get the notes every answer across the split carries: none.
     *
     * @return list<string>
     */
    public function fixedNotes(): array
    {
        return [];
    }

    /**
     * Get the one call that lists the records of the first group on both sides.
     *
     * @return list<array{arguments: array<string, string>, why: string}>
     */
    public function occurrences(Comparison $comparison): array
    {
        return [
            [
                'arguments' => [],
                'why' => __('firewatch::messages.compare_next_occurrences'),
            ],
        ];
    }
}
