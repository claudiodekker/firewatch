<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\NightwatchInstall;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureLog;

/**
 * @internal
 */
class Conditions
{
    /**
     * Create a new conditions instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected FailureLog $failures,
    ) {
        //
    }

    /**
     * Get the condition blind spots measured for a call: what is true of this store now that overlaps its types and window.
     *
     * @param  list<RecordType>  $types
     * @return list<array<string, mixed>>
     */
    public function for(?StoreFacts $facts, array $types, Window $window): array
    {
        $timezone = $window->timezone();
        $conditions = [];

        // Each is one sentence with its facts inline and the same facts as fields; a store that can't be read has no facts, so only the conditions that don't need it are measured.
        if ($facts !== null) {
            array_push($conditions, ...$this->history($facts, $types, $window, $timezone));
            array_push($conditions, ...$this->rebuilt($facts, $window, $timezone));
        }

        array_push($conditions, ...$this->dropped($window, $timezone));

        if ($facts !== null) {
            array_push($conditions, ...$this->drift($facts, $types, $timezone));
            array_push($conditions, ...$this->unverified($facts));
        }

        array_push($conditions, ...$this->redaction($types));

        return $conditions;
    }

    /**
     * Get `history-pruned` or `history-cleared` for a window that starts before the history the call read is complete from.
     *
     * @param  list<RecordType>  $types
     * @return list<array<string, mixed>>
     */
    protected function history(StoreFacts $facts, array $types, Window $window, string $timezone): array
    {
        $history = History::of($facts->meta, $types);

        if ($history->from === null || $history->reason === null || $history->reason === 'created' || ! $this->startsBefore($window, $history->from)) {
            return [];
        }

        $id = str_starts_with($history->reason, 'pruned-') ? 'history-pruned' : 'history-cleared';

        return [$this->condition($id, [
            'from' => Instant::format($history->from, $timezone),
            'reason' => $history->reason,
        ], [
            'from_at' => $history->from,
            'reason' => $history->reason,
        ])];
    }

    /**
     * Get `store-rebuilt` for a window that starts before the store was last rebuilt.
     *
     * @return list<array<string, mixed>>
     */
    protected function rebuilt(StoreFacts $facts, Window $window, string $timezone): array
    {
        $at = $facts->meta->rebuiltAt;
        $why = $facts->meta->rebuiltWhy;

        if ($at === null || $why === null || ! $this->startsBefore($window, $at)) {
            return [];
        }

        return [$this->condition('store-rebuilt', [
            'at' => Instant::format($at, $timezone),
            'why' => $why,
        ], [
            'rebuilt_at' => $at,
            'why' => $why,
        ])];
    }

    /**
     * Get `records-dropped` for batches the store failed to keep inside the window: a lower bound, as the file keeps only its latest lines.
     *
     * @return list<array<string, mixed>>
     */
    protected function dropped(Window $window, string $timezone): array
    {
        $lines = array_values(array_filter(
            $this->failures->dropped(),
            fn (array $line) => ($window->since() === null || $line['at'] >= $window->since()) && ($window->until() === null || $line['at'] < $window->until()),
        ));

        if ($lines === []) {
            return [];
        }

        $records = array_sum(array_column($lines, 'dropped'));
        $from = min(array_column($lines, 'at'));
        $to = max(array_column($lines, 'at'));
        $reason = $lines[array_key_last($lines)]['kind'];

        return [$this->condition(
            'records-dropped',
            [
                'n' => number_format($records),
                'from' => Instant::format($from, $timezone),
                'to' => Instant::format($to, $timezone),
                'reason' => $reason,
            ],
            [
                'records' => $records,
                'from_at' => $from,
                'to_at' => $to,
                'reason' => $reason,
            ],
        )];
    }

    /**
     * Get `drift` for each kind of drift counted on a type the call read.
     *
     * @param  list<RecordType>  $types
     * @return list<array<string, mixed>>
     */
    protected function drift(StoreFacts $facts, array $types, string $timezone): array
    {
        $read = array_map(fn (RecordType $type) => $type->value, $types);
        $conditions = [];

        foreach ($facts->drift as $row) {
            if (! in_array($row['type'], $read, true)) {
                continue;
            }

            $conditions[] = $this->condition(
                'drift',
                [
                    'count' => number_format($row['count']),
                    'kind' => $row['kind'],
                    'type' => $row['type'],
                    'last' => Instant::format($row['last_seen'], $timezone),
                ],
                [
                    'count' => $row['count'],
                    'drift_kind' => $row['kind'],
                    'type' => $row['type'],
                    'last_at' => $row['last_seen'],
                ],
            );
        }

        return $conditions;
    }

    /**
     * Get `nightwatch-unverified` when the latest batch came from a release above the verified line, or a development build.
     *
     * @return list<array<string, mixed>>
     */
    protected function unverified(StoreFacts $facts): array
    {
        if ($facts->meta->nightwatchVerified !== false) {
            return [];
        }

        $version = $facts->meta->nightwatchVersion ?? '';

        return [$this->condition('nightwatch-unverified', [
            'version' => $version,
            'line' => NightwatchInstall::VERIFIED_LINE,
        ], [
            'version' => $version,
            'line' => NightwatchInstall::VERIFIED_LINE,
        ])];
    }

    /**
     * Get `redaction-active` when requests are read and the developer redacts headers or payload fields; Firewatch's default redacts nothing.
     *
     * @param  list<RecordType>  $types
     * @return list<array<string, mixed>>
     */
    protected function redaction(array $types): array
    {
        $headers = $this->configuration->redactHeaders;
        $fields = $this->configuration->redactPayloadFields;

        if (! in_array(RecordType::REQUEST, $types, true) || ($headers === [] && $fields === [])) {
            return [];
        }

        return [$this->condition('redaction-active', [], [
            'headers' => $headers,
            'payload_fields' => $fields,
        ])];
    }

    /**
     * Determine if a window reaches back before an instant: it has no start, or starts earlier.
     */
    protected function startsBefore(Window $window, float $instant): bool
    {
        return $window->since() === null || $window->since() < $instant;
    }

    /**
     * Get a condition: its id, its pinned sentence with the facts inline, and the same facts as fields.
     *
     * @param  array<string, string>  $replace
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    protected function condition(string $id, array $replace, array $facts): array
    {
        return [
            'id' => $id,
            'kind' => BlindSpotKind::CONDITION->value,
            'message' => __("firewatch::messages.conditions.{$id}", $replace),
            ...$facts,
        ];
    }
}
