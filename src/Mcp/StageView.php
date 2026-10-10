<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Stage;

/**
 * @internal
 */
readonly class StageView
{
    /**
     * The decimals a share is rounded to.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * Create a new stage view instance.
     *
     * @param  list<Stage>  $stages
     * @param  array<string, int|float>  $sums  the microseconds of each stage over the executions, by stage
     * @param  array<string, mixed>|null  $slowest  the duration `d`, the execution id and each stage of the slowest execution
     */
    public function __construct(
        public array $stages,
        public int $executions,
        public int $excluded,
        public array $sums,
        public ?array $slowest,
    ) {
        //
    }

    /**
     * Get the stage fields of a group that has no stage view.
     *
     * @return array<string, null>
     */
    public static function none(): array
    {
        return [
            'dominant_stage' => null,
            'stage_executions' => null,
            'stage_avg_ms' => null,
            'slowest_execution_id' => null,
            'slowest_duration_ms' => null,
            'stages' => null,
        ];
    }

    /**
     * Get the sentence the summary ends with, naming the dominant stage, or nothing when there is none.
     */
    public function summary(): string
    {
        $dominant = $this->dominant();

        if ($dominant === null) {
            return '';
        }

        return __('firewatch::messages.rank_breakdown_dominant', [
            'stage' => $dominant->value,
            'share' => $this->share($dominant),
            'avg' => $this->average(),
        ]);
    }

    /**
     * Get the stage with the highest mean, the earlier one on a tie, or null when the stages took no time.
     */
    protected function dominant(): ?Stage
    {
        if ($this->total() <= 0) {
            return null;
        }

        $dominant = $this->stages[0];

        foreach ($this->stages as $stage) {
            if ($this->sums[$stage->value] > $this->sums[$dominant->value]) {
                $dominant = $stage;
            }
        }

        return $dominant;
    }

    /**
     * Get the milliseconds the stages of an execution took on average.
     */
    protected function average(): ?float
    {
        return Stored::milliseconds($this->total() / $this->executions);
    }

    /**
     * Get the share of a stage in the average, as a percentage, or null when the stages took no time.
     */
    protected function share(Stage $stage): ?float
    {
        $total = $this->total();

        return $total <= 0 ? null : round(Ranking::PERCENT * $this->sums[$stage->value] / $total, self::PERCENT_DECIMALS);
    }

    /**
     * Get the stage fields of the answer.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        if ($this->executions === 0) {
            return [
                ...self::none(),
                'stage_executions' => 0,
            ];
        }

        return [
            'dominant_stage' => $this->dominant()?->value,
            'stage_executions' => $this->executions,
            'stage_avg_ms' => $this->average(),
            'slowest_execution_id' => $this->slowest['execution_id'] ?? null,
            'slowest_duration_ms' => Stored::milliseconds($this->slowest['d'] ?? null),
            'stages' => array_map($this->row(...), $this->stages),
        ];
    }

    /**
     * Get the notes of the stage view.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];

        if ($this->excluded > 0) {
            $notes[] = trans_choice('firewatch::messages.rank_stages_excluded', $this->excluded, ['count' => $this->excluded]);
        }

        if ($this->executions > 0 && $this->sums[Stage::BOOTSTRAP->value] == 0) {
            $notes[] = __('firewatch::messages.rank_stages_bootstrap_zero');
        }

        return $notes;
    }

    /**
     * Get the row of the answer for a stage.
     *
     * @return array{stage: string, mean_ms: float|null, share_pct: float|null, slowest_ms: float|null}
     */
    protected function row(Stage $stage): array
    {
        return [
            'stage' => $stage->value,
            'mean_ms' => Stored::milliseconds($this->sums[$stage->value] / $this->executions),
            'share_pct' => $this->share($stage),
            'slowest_ms' => Stored::milliseconds($this->slowest[$stage->value] ?? null),
        ];
    }

    /**
     * Get the microseconds of every stage over the executions.
     */
    protected function total(): int|float
    {
        return array_sum($this->sums);
    }
}
