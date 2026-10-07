<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use stdClass;

/**
 * @internal
 */
class Judgement
{
    /**
     * Create a new judgement instance.
     *
     * @param  array<string, mixed>|null  $threshold
     * @param  list<array<string, mixed>>  $findings  at most the limit, worst first
     * @param  array<string, int|array<string, mixed>>  $saw  counts of input set aside, and any block of context a detector carries beside them
     * @param  list<string>  $caveats
     */
    public function __construct(
        public readonly DetectorName $detector,
        public readonly ?array $threshold,
        public readonly Verdict $verdict,
        public readonly ?Reason $reason,
        public readonly int $examined,
        public readonly int $total,
        public readonly array $findings = [],
        public readonly array $saw = [],
        public readonly array $caveats = [],
    ) {
        //
    }

    /**
     * Get the judgement of what a detector examined.
     *
     * @param  array<string, mixed>|null  $threshold
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, int|array<string, mixed>>  $saw
     * @param  list<string>  $caveats
     */
    public static function of(DetectorName $detector, ?array $threshold, int $examined, int $total, array $findings, array $saw = [], array $caveats = []): self
    {
        [$verdict, $reason] = match (true) {
            $total > 0 => [Verdict::FINDINGS, null],
            $examined > 0 => [Verdict::CLEAN, null],
            default => [Verdict::NOT_EVALUATED, Reason::NO_RECORDS],
        };

        return new self($detector, $threshold, $verdict, $reason, examined: $examined, total: $total, findings: $findings, saw: $saw, caveats: $caveats);
    }

    /**
     * Get the judgement of a detector that did not judge.
     *
     * @param  array<string, mixed>|null  $threshold
     * @param  array<string, int|array<string, mixed>>  $saw
     * @param  list<string>  $caveats
     */
    public static function notEvaluated(DetectorName $detector, ?array $threshold, Reason $reason, array $saw = [], array $caveats = []): self
    {
        return new self($detector, $threshold, Verdict::NOT_EVALUATED, $reason, examined: 0, total: 0, saw: $saw, caveats: $caveats);
    }

    /**
     * Determine if the detector found no record of its own.
     */
    public function matchedNothing(): bool
    {
        $setAside = array_sum(array_filter($this->saw, is_int(...)));

        return $this->examined === 0 && $this->total === 0 && $setAside === 0 && $this->reason !== Reason::OUTSIDE_COVERAGE;
    }

    /**
     * Get the judgement as the answer carries it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'detector' => $this->detector->value,
            'threshold' => $this->threshold,
            'verdict' => $this->verdict->value,
            'reason' => $this->reason?->value,
            'examined' => $this->examined,
            'total' => $this->total,
            'findings' => $this->findings,
            'saw' => $this->saw === [] ? new stdClass : $this->saw,
            'caveats' => $this->caveats,
        ];
    }

    /**
     * Get the row of the overview's count mode.
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        $worst = $this->findings[0] ?? null;

        return [
            'detector' => $this->detector->value,
            'verdict' => $this->verdict->value,
            'reason' => $this->reason?->value,
            'examined' => $this->examined,
            'total' => $this->total,
            'worst' => $worst === null ? null : [
                'name' => $worst['name'],
                'group' => $worst['group'],
            ],
        ];
    }
}
