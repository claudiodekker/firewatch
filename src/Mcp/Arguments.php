<?php

namespace ClaudioDekker\Firewatch\Mcp;

use BackedEnum;
use ClaudioDekker\Firewatch\RecordType;
use Laravel\Mcp\Request;
use SQLite3;

/**
 * @internal
 */
class Arguments
{
    /**
     * Create a new arguments instance.
     */
    public function __construct(
        protected Request $request,
        protected string $tool,
    ) {
        //
    }

    /**
     * Get the value the call gives the argument, as it was sent.
     */
    public function get(string $argument): mixed
    {
        return $this->request->get($argument);
    }

    /**
     * Get the names of the arguments the call gives.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->request->all());
    }

    /**
     * Read the group id, or null when none is given.
     */
    public function group(string $example): ?string
    {
        $value = $this->get('group');

        if ($value === null || (is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1)) {
            return $value;
        }

        $groupId = __('firewatch::messages.argument_values.group');

        throw $this->invalid(argument: 'group', expected: $groupId, accepted: $groupId, example: $example);
    }

    /**
     * Read the most rows to list, or the default when none is given.
     *
     * @param  array<string, mixed>  $alongside
     */
    public function limit(int $maximum, int $default, array $alongside = []): int
    {
        return $this->between('limit', minimum: 1, maximum: $maximum, default: $default, alongside: $alongside);
    }

    /**
     * Read a whole number of the range, or the default when none is given.
     *
     * @param  array<string, mixed>  $alongside
     */
    public function between(string $argument, int $minimum, int $maximum, int $default, array $alongside = []): int
    {
        $value = $this->get($argument);

        if ($value === null) {
            return $default;
        }

        if (is_int($value) && $value >= $minimum && $value <= $maximum) {
            return $value;
        }

        $range = [
            'minimum' => $minimum,
            'maximum' => $maximum,
        ];

        $example = Call::written($this->tool, [
            ...$alongside,
            $argument => $default,
        ]);

        throw $this->invalid(argument: $argument, expected: __('firewatch::messages.argument_values.range', $range), accepted: __('firewatch::messages.argument_values.whole_number', $range), example: $example);
    }

    /**
     * Read the record type, or null when none is given.
     *
     * @param  list<RecordType>  $accepted
     */
    public function type(array $accepted, string $expected, string $example): ?RecordType
    {
        return $this->choice('type', $accepted, $expected, $example);
    }

    /**
     * Read the record type a call can't go without.
     *
     * @param  list<RecordType>  $accepted
     */
    public function requiredType(array $accepted, string $expected, string $example): RecordType
    {
        return $this->type($accepted, $expected, $example) ?? throw Refusal::missing(argument: 'type', accepted: $this->listed($accepted), example: $example);
    }

    /**
     * Read the type with groups, which only a call that names a group can go without.
     */
    public function typeWithGroups(?string $group, string $example): ?RecordType
    {
        $expected = __('firewatch::messages.argument_values.grouped_type');

        if ($group === null) {
            return $this->requiredType(Measure::types(), $expected, $example);
        }

        return $this->type(Measure::types(), $expected, $example);
    }

    /**
     * Read one of the accepted cases, matched exactly, or null when none is given.
     *
     * @template TCase of BackedEnum
     *
     * @param  list<TCase>  $accepted
     * @return TCase|null
     */
    public function choice(string $argument, array $accepted, string $expected, string $example): ?BackedEnum
    {
        $value = $this->get($argument);

        if ($value === null) {
            return null;
        }

        foreach ($accepted as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw $this->invalid(argument: $argument, expected: $expected, accepted: $this->listed($accepted), example: $example);
    }

    /**
     * Read text, or null when none is given.
     */
    public function text(string $argument, string $example): ?string
    {
        $value = $this->get($argument);

        if ($value === null || is_string($value)) {
            return $value;
        }

        $text = __('firewatch::messages.argument_values.text');

        throw $this->invalid(argument: $argument, expected: $text, accepted: $text, example: $example);
    }

    /**
     * Read the exact deploy the records are restricted to, or null for all of them.
     */
    public function deploy(string $example): ?string
    {
        $value = $this->get('deploy');

        if ($value === null || is_string($value)) {
            return $value;
        }

        $deploy = __('firewatch::messages.argument_values.deploy');

        throw $this->invalid(argument: 'deploy', expected: $deploy, accepted: $deploy, example: $example);
    }

    /**
     * Resolve the type a group is read as, with the types that hold it: the type the call names, else the one the group is best known by.
     *
     * @return array{RecordType|null, list<RecordType>}
     */
    public function resolveGroupType(SQLite3 $connection, ?string $group, ?RecordType $type, string $example): array
    {
        if ($group === null) {
            return [$type, []];
        }

        $held = Ranking::holders($connection, $group);

        if ($type !== null && $held !== [] && ! in_array($type, $held, true)) {
            throw Refusal::conflicting(argument: 'type', with: 'group', accepted: __('firewatch::messages.argument_values.holding', ['types' => $this->listed($held)]), example: $example);
        }

        return [$type ?? Ranking::preferred($held), $held];
    }

    /**
     * Get the refusal of the argument's value, shown as JSON.
     */
    public function invalid(string $argument, string $expected, string $accepted, string $example): Refusal
    {
        $shown = json_encode($this->get($argument), JSON_THROW_ON_ERROR);

        return Refusal::invalid(argument: $argument, expected: $expected, value: $shown, accepted: $accepted, example: $example);
    }

    /**
     * Get the values of the cases as a refusal lists them.
     *
     * @param  list<BackedEnum>  $cases
     */
    protected function listed(array $cases): string
    {
        return implode(', ', array_map(fn (BackedEnum $case) => $case->value, $cases));
    }
}
