<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\Markers;

/**
 * @internal
 */
class Accounting
{
    /**
     * Compare what an execution counted with the children the store holds for it, counter by counter, and state each difference without explaining it.
     *
     * @param  array<string, mixed>  $execution
     * @param  list<array<string, mixed>>  $children
     * @return array{counters: list<array{counter: string, counted: int, captured: int, state: string}>, lines: list<string>}
     */
    public static function of(array $execution, array $children, Markers $meta, string $timezone): array
    {
        $held = array_count_values(array_column($children, 'type'));
        $startedAt = $execution['started_at'];
        $counters = [];
        $lines = [];

        foreach (Counter::cases() as $counter) {
            $type = $counter->type();
            $counted = is_int($execution[$counter->value] ?? null) ? $execution[$counter->value] : 0;
            $captured = $held[$type->value] ?? 0;
            $state = AccountingState::of($counted, $captured);

            $counters[] = [
                'counter' => $counter->value,
                'counted' => $counted,
                'captured' => $captured,
                'state' => $state->value,
            ];

            if ($state === AccountingState::MORE) {
                $lines[] = __('firewatch::messages.accounting_more', [
                    'captured' => $captured,
                    'noun' => $counter->noun(),
                    'counted' => $counted,
                ]);
            }

            if ($state === AccountingState::FEWER) {
                $lines[] = self::missing($counter, $captured, $counted, $meta, $startedAt, $timezone);
            }
        }

        return [
            'counters' => $counters,
            'lines' => $lines,
        ];
    }

    /**
     * Get the line for children that are fewer than counted: removed history when their type's coverage starts after the execution did, otherwise incomplete.
     */
    protected static function missing(Counter $counter, int $captured, int $counted, Markers $meta, mixed $startedAt, string $timezone): string
    {
        $from = History::of($meta, [$counter->type()])->from;

        if ($from !== null && is_numeric($startedAt) && $from > $startedAt) {
            return __('firewatch::messages.accounting_outside_coverage', [
                'noun' => $counter->noun(),
                'from' => Instant::format($from, $timezone),
            ]);
        }

        return __('firewatch::messages.accounting_incomplete', [
            'captured' => $captured,
            'counted' => $counted,
            'noun' => $counter->noun(),
        ]);
    }
}
