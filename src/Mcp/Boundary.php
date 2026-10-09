<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-import-type Side from Comparison
 */
interface Boundary
{
    /**
     * Get the before and the after side of the window, each clipped to the type's coverage start.
     *
     * @return array{Side, Side}
     */
    public function sides(Window $window, ?float $coverageStart): array;

    /**
     * Refuse a window the boundary does not divide.
     */
    public function refuseOutside(Window $window): void;

    /**
     * Determine if the boundary is an instant, so that work finishing after it is recorded on the other side, as the `visible-at-completion` blind spot says.
     */
    public function isInstant(): bool;

    /**
     * Count the executions of the type that started on the before side and ended past the boundary, or get null where that cannot happen.
     *
     * @param  Side  $before
     */
    public function straddling(SQLite3 $connection, RecordType $type, array $before, ?string $group): ?int;

    /**
     * Get the boundary as an empty answer names it among the filters of the call.
     *
     * @return list<string>
     */
    public function filters(): array;

    /**
     * Get the summary of a comparison that ran: its changes, counted over every group.
     */
    public function summary(Comparison $comparison): string;

    /**
     * Get the summary of a comparison that ran on nothing because a side, or both, holds no record of the type.
     */
    public function emptySideSummary(Comparison $comparison, string $side): string;

    /**
     * Get the note of a comparison that ran on nothing, saying how to retry.
     */
    public function notEvaluatedNote(): string;

    /**
     * Get the notes of the boundary on a comparison, after the note of a comparison that ran on nothing.
     *
     * @return list<string>
     */
    public function notes(Comparison $comparison, bool $sinceOmitted, ?float $oldest): array;

    /**
     * Get the notes every answer across the boundary carries, also when empty.
     *
     * @return list<string>
     */
    public function fixedNotes(): array;

    /**
     * Get the arguments and the reason of each call that lists the records of the first group, beside its group, type and window.
     *
     * @return list<array{arguments: array<string, string>, why: string}>
     */
    public function occurrences(Comparison $comparison): array;
}
