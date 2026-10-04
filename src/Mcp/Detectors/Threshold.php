<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Refusal;

/**
 * @internal
 */
class Threshold
{
    /**
     * Create a new threshold instance.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $unit,
        public readonly int|float $default,
        public readonly int|float $minimum,
        public readonly int|float|null $maximum = null,
        public readonly bool $whole = true,
    ) {
        //
    }

    /**
     * Read the value a call passes: a number in range, whole when the threshold is, never clamped.
     */
    public function read(mixed $value, string $example): int|float
    {
        if ((is_int($value) || (is_float($value) && ! $this->whole)) && $value >= $this->minimum && ($this->maximum === null || $value <= $this->maximum)) {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'threshold', expected: $this->accepted(), value: $shown, accepted: $this->accepted(), example: $example);
    }

    /**
     * Get what the threshold accepts, in words.
     */
    public function accepted(): string
    {
        $kind = $this->whole ? 'a whole number' : 'a number';

        return $this->maximum === null ? "{$kind} of {$this->minimum} or more" : "{$kind} of {$this->minimum} to {$this->maximum}";
    }

    /**
     * Get the threshold as an answer states it: the value in force, the default, the unit and the range.
     *
     * @return array{name: string, value: int|float, default: int|float, unit: string, range: array{min: int|float, max: int|float|null}, is_default: bool}
     */
    public function describe(int|float|null $value = null): array
    {
        return [
            'name' => $this->name,
            'value' => $value ?? $this->default,
            'default' => $this->default,
            'unit' => $this->unit,
            'range' => [
                'min' => $this->minimum,
                'max' => $this->maximum,
            ],
            'is_default' => $value === null || $value === $this->default,
        ];
    }
}
