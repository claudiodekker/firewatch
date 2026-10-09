<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Configuration\BudgetEntry;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Store\Microseconds;

/**
 * @internal
 */
readonly class BudgetVerdict
{
    /**
     * Create a new budget verdict instance.
     *
     * @param  list<array{measure: string, unit: string, ceiling: int|float, measured: float|null, exceeded: bool}>  $measures
     */
    public function __construct(
        public BudgetState $state,
        public ?BudgetReason $reason,
        public ?int $entry,
        public ?string $measuredOn,
        public array $measures,
        public int $ignoredEntries,
    ) {
        //
    }

    /**
     * Judge one execution against the entry that governs it.
     *
     * @param  array<string, mixed>  $row
     */
    public static function of(Configuration $configuration, ExecutionType $type, array $row): self
    {
        $ignored = $configuration->ignoredBudgetEntries;

        if ($configuration->budgets === []) {
            return self::notEvaluated(BudgetReason::NO_BUDGET_CONFIGURED, $ignored);
        }

        if ($type === ExecutionType::SCHEDULED_TASK && ($row['status'] ?? null) === Outcome::SKIPPED->value) {
            return self::notEvaluated(BudgetReason::NOT_RUN, $ignored);
        }

        $entry = self::governing($configuration->budgets, $type, $row);

        if ($entry === null) {
            return self::notEvaluated(BudgetReason::NO_MATCHING_BUDGET, $ignored);
        }

        return self::judge($entry, $row['duration'] ?? null, $row['peak_memory_usage'] ?? null, 'own', $ignored);
    }

    /**
     * Judge a duration in microseconds and a peak memory in bytes against the ceilings of the entry.
     */
    public static function judge(BudgetEntry $entry, mixed $duration, mixed $memory, string $measuredOn, int $ignored): self
    {
        $measures = [];

        if ($entry->durationMilliseconds !== null) {
            $exceeded = is_numeric($duration) && $duration > $entry->durationMilliseconds * Microseconds::PER_MILLISECOND;
            $measures[] = [
                'measure' => 'duration',
                'unit' => 'ms',
                'ceiling' => $entry->durationMilliseconds,
                'measured' => Stored::milliseconds($duration),
                'exceeded' => $exceeded,
            ];
        }

        if ($entry->memoryMegabytes !== null) {
            $exceeded = is_numeric($memory) && $memory > Stored::bytes($entry->memoryMegabytes);
            $measures[] = [
                'measure' => 'memory',
                'unit' => 'mb',
                'ceiling' => $entry->memoryMegabytes,
                'measured' => Stored::megabytes($memory),
                'exceeded' => $exceeded,
            ];
        }

        $measured = array_filter($measures, fn (array $measure) => $measure['measured'] !== null);
        $over = array_filter($measures, fn (array $measure) => $measure['exceeded']);

        [$state, $reason] = match (true) {
            $over !== [] => [BudgetState::EXCEEDED, null],
            count($measured) < count($measures) => [BudgetState::NOT_EVALUATED, BudgetReason::NO_MEASUREMENT],
            default => [BudgetState::WITHIN, null],
        };

        return new self($state, $reason, $entry->number, $measuredOn, $measures, $ignored);
    }

    /**
     * Create the verdict of an execution or group that was not judged.
     */
    public static function notEvaluated(BudgetReason $reason, int $ignored): self
    {
        return new self(BudgetState::NOT_EVALUATED, $reason, null, null, [], $ignored);
    }

    /**
     * Get the payload of the verdict.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reason' => $this->reason?->value,
            'entry' => $this->entry,
            'measured_on' => $this->measuredOn,
            'measures' => $this->measures,
            ...($this->ignoredEntries > 0 ? ['ignored_entries' => $this->ignoredEntries] : []),
        ];
    }

    /**
     * Get the verdict as the one line of markdown.
     */
    public function line(): string
    {
        $details = match ($this->state) {
            BudgetState::EXCEEDED => [$this->overruns(), "entry {$this->entry}"],
            BudgetState::WITHIN => ["entry {$this->entry}"],
            BudgetState::NOT_EVALUATED => [$this->reason?->value],
        };

        if ($this->ignoredEntries > 0) {
            $details[] = "ignored_entries: {$this->ignoredEntries}";
        }

        return __('firewatch::messages.budget_line', [
            'state' => str_replace('_', ' ', $this->state->value),
            'details' => implode('; ', $details),
        ]);
    }

    /**
     * Get the exceeded ceilings, each with what was measured against it.
     */
    protected function overruns(): string
    {
        $over = array_filter($this->measures, fn (array $measure) => $measure['exceeded']);

        $overruns = array_map(function (array $measure) {
            $measured = self::shown($measure['measured'], $measure['unit']);
            $ceiling = self::shown($measure['ceiling'], $measure['unit']);

            return "{$measure['measure']} {$measured} over {$ceiling}";
        }, $over);

        return implode(', ', $overruns);
    }

    /**
     * Get a value with its unit, to the decimals the answer contract gives the unit.
     */
    protected static function shown(int|float|null $value, string $unit): string
    {
        return $unit === 'ms' ? sprintf('%.2f ms', $value) : sprintf('%.1f MB', $value);
    }

    /**
     * Get the entry that governs the execution: the first specific entry that matches, else the first global entry.
     *
     * @param  list<BudgetEntry>  $budgets
     * @param  array<string, mixed>  $row
     */
    protected static function governing(array $budgets, ExecutionType $type, array $row): ?BudgetEntry
    {
        $entries = array_filter($budgets, fn (BudgetEntry $entry) => $entry->type === $type);

        foreach ($entries as $entry) {
            if ($entry->matches($row)) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if ($entry->isGlobal()) {
                return $entry;
            }
        }

        return null;
    }
}
