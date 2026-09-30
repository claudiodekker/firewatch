<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
class Drift
{
    /**
     * The occurrences counted so far, by key.
     *
     * @var array<string, array{kind: string, type: string, v: string, detail: string, count: int}>
     */
    protected array $occurrences = [];

    /**
     * Count one occurrence of a drift, with the type and version as sent and an empty string for their absence.
     */
    public function record(DriftKind $kind, mixed $type = '', mixed $v = '', string $detail = ''): void
    {
        $type = $this->asSent($type);
        $v = $this->asSent($v);
        $key = implode("\0", [$kind->value, $type, $v, $detail]);

        $this->occurrences[$key] ??= ['kind' => $kind->value, 'type' => $type, 'v' => $v, 'detail' => $detail, 'count' => 0];
        $this->occurrences[$key]['count']++;
    }

    /**
     * Get the counted drift, one entry per kind, type, version and detail, in the order first counted.
     *
     * @return list<array{kind: string, type: string, v: string, detail: string, count: int}>
     */
    public function all(): array
    {
        return array_values($this->occurrences);
    }

    /**
     * Get a wire value as the text a drift is keyed by, or an empty string for one that is absent or not a scalar.
     */
    protected function asSent(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }
}
