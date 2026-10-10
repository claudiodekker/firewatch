<?php

namespace ClaudioDekker\Firewatch\Console\Doctor;

use Closure;

/**
 * What every check found, once each and in the order of the ids.
 *
 * Only `collect()` builds one, and it walks `Check::cases()`, so a report can't miss, repeat or reorder an id.
 *
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class DoctorReport
{
    /**
     * Create a new doctor report instance.
     *
     * @param  list<array{check: Check, result: CheckResult}>  $lines  one for each result, in case order
     */
    protected function __construct(protected array $lines)
    {
        //
    }

    /**
     * Run the callback for every check, in order, and keep what each found.
     *
     * @param  Closure(Check): (CheckResult|list<CheckResult>)  $check  a check with several issues answers a result for each
     */
    public static function collect(Closure $check): static
    {
        $lines = [];

        foreach (Check::cases() as $id) {
            $found = $check($id);

            foreach (is_array($found) ? $found : [$found] as $result) {
                $lines[] = ['check' => $id, 'result' => $result];
            }
        }

        return new static($lines);
    }

    /**
     * Get the run's status: the worst result, with info never counting.
     */
    public function status(): CheckStatus
    {
        return CheckStatus::worst(...array_map(fn (array $line) => $line['result']->status, $this->lines));
    }

    /**
     * Determine if a check failed, which is the only thing that fails the command.
     */
    public function failed(): bool
    {
        return $this->status() === CheckStatus::FAIL;
    }

    /**
     * Get every result with the check that found it.
     *
     * @return list<array{check: Check, result: CheckResult}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Get the report as the `--json` document.
     *
     * @return array{status: string, checks: list<array{id: string, status: string, message: string, fix: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'checks' => array_map(fn (array $line) => [
                'id' => $line['check']->value,
                'status' => $line['result']->status->value,
                'message' => $line['result']->message,
                'fix' => $line['result']->fix,
            ], $this->lines),
        ];
    }
}
