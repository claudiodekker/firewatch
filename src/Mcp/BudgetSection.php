<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class BudgetSection
{
    /**
     * The most exceeded groups the section lists.
     */
    protected const LISTED = 10;

    /**
     * Create a new budget section instance.
     *
     * @param  array{exceeded: int, within: int, not_evaluated: int}|null  $counts  null when no valid entry is configured
     * @param  list<array<string, mixed>>  $exceeded  worst first, at most the listed
     */
    protected function __construct(
        protected ?array $counts,
        protected array $exceeded,
        protected int $matched,
        protected int $ignored,
    ) {
        //
    }

    /**
     * Judge the groups of the four execution types that have a record in the window.
     */
    public static function read(SQLite3 $connection, Window $window, Configuration $configuration): self
    {
        $ignored = $configuration->ignoredBudgetEntries;

        if ($configuration->budgets === []) {
            return new self(null, [], 0, $ignored);
        }

        $counts = [
            'exceeded' => 0,
            'within' => 0,
            'not_evaluated' => 0,
        ];
        $exceeded = [];

        foreach (ExecutionType::records() as $record) {
            $type = ExecutionType::from($record->value);
            $budgets = (new Ranking($record, Measure::default($record), $window, null))->budgets($connection);

            foreach ($budgets as $hash => $budget) {
                $verdict = BudgetVerdict::ofGroup($configuration, $type, $budget);
                $counts[$verdict->state->value]++;

                if ($verdict->state === BudgetState::EXCEEDED) {
                    $exceeded[] = self::row($record, $hash, $budget, $verdict, $window);
                }
            }
        }

        usort($exceeded, fn (array $a, array $b) => ($b['ratio'] <=> $a['ratio']) ?: strcmp($a['group'], $b['group']));

        $rows = array_map(fn (array $row) => array_diff_key($row, ['ratio' => 0]), array_slice($exceeded, 0, self::LISTED));

        return new self($counts, $rows, count($exceeded), $ignored);
    }

    /**
     * Get the counts and the exceeded groups as the keys of a result.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $budgets = $this->counts ?? [
            'state' => BudgetState::NOT_EVALUATED->value,
            'reason' => BudgetReason::NO_BUDGET_CONFIGURED->value,
        ];

        if ($this->ignored > 0) {
            $budgets['ignored_entries'] = $this->ignored;
        }

        $result = ['budgets' => $budgets];

        if ($this->exceeded !== []) {
            $result['budgets_exceeded'] = $this->exceeded;
        }

        return $result;
    }

    /**
     * Get how many groups exceeded their budget.
     */
    public function exceeded(): int
    {
        return $this->matched;
    }

    /**
     * Get the entry that says the exceeded list was cut, or null for a complete one.
     *
     * @return array{section: string, shown: int, matched: int|null, reason: string, how: string}|null
     */
    public function truncation(): ?array
    {
        if ($this->matched <= self::LISTED) {
            return null;
        }

        return [
            'section' => 'budgets_exceeded',
            'shown' => count($this->exceeded),
            'matched' => $this->matched,
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.overview_budgets_truncated_how'),
        ];
    }

    /**
     * Get the one line that states what the budgets found.
     */
    public function note(): string
    {
        $ignored = $this->ignored > 0 ? "; ignored_entries: {$this->ignored}" : '';

        if ($this->counts === null) {
            return __('firewatch::messages.overview_budgets_unevaluated', [
                'reason' => BudgetReason::NO_BUDGET_CONFIGURED->value,
                'ignored' => $ignored,
            ]);
        }

        return __('firewatch::messages.overview_budgets', array_merge($this->counts, ['ignored' => $ignored]));
    }

    /**
     * Get the row of an exceeded group, with the ratio it is ordered by.
     *
     * @param  array<string, mixed>  $budget
     * @return array<string, mixed>
     */
    protected static function row(RecordType $type, string $hash, array $budget, BudgetVerdict $verdict, Window $window): array
    {
        $measures = array_filter($verdict->measures, fn (array $measure) => $measure['exceeded']);
        $ratios = array_map(fn (array $measure) => $measure['measured'] / $measure['ceiling'], $measures);
        $worst = $measures[array_search(max($ratios), $ratios, true)];

        return [
            'type' => $type->value,
            'group' => $hash,
            'label' => Ranking::shownLabel($type, $budget['label']),
            'measure' => $worst['measure'],
            'unit' => $worst['unit'],
            'measured' => $worst['measured'],
            'ceiling' => $worst['ceiling'],
            'measured_on' => $verdict->measuredOn,
            'next' => Call::written('rank', array_merge(['group' => $hash], $window->arguments())),
            'ratio' => max($ratios),
        ];
    }
}
