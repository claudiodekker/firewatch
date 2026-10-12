<?php

namespace ClaudioDekker\Firewatch\Mcp;

use BackedEnum;
use ClaudioDekker\Firewatch\RecordType;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use LogicException;

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
        protected Tool $tool,
    ) {
        //
    }

    /**
     * The tool's schema, read once.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $schema = null;

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
     * Read the group id the argument gives, or null when none is given.
     */
    public function group(string $example, string $argument = 'group'): ?string
    {
        $value = $this->get($argument);

        if ($value === null || (is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1)) {
            return $value;
        }

        $groupId = __('firewatch::messages.argument_values.group');

        throw $this->invalid(argument: $argument, expected: $groupId, accepted: $groupId, example: $example);
    }

    /**
     * Read the most rows to list, or the declared default when none is given.
     *
     * @param  array<string, mixed>  $alongside
     */
    public function limit(array $alongside = []): int
    {
        return $this->between('limit', $alongside);
    }

    /**
     * Read a whole number of the declared range, or the declared default when none is given.
     *
     * @param  array<string, mixed>  $alongside
     */
    public function between(string $argument, array $alongside = []): int
    {
        /** @var array{minimum: int, maximum: int, default: int} $declared */
        $declared = $this->declared($argument);
        ['minimum' => $minimum, 'maximum' => $maximum, 'default' => $default] = $declared;

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

        $example = Call::written($this->tool->name(), [
            ...$alongside,
            $argument => $default,
        ]);

        throw $this->invalid(argument: $argument, expected: __('firewatch::messages.argument_values.range', $range), accepted: __('firewatch::messages.argument_values.whole_number', $range), example: $example);
    }

    /**
     * Read the record type, or null when none is given.
     */
    public function type(string $expected, string $example): ?RecordType
    {
        return $this->choice('type', RecordType::class, $expected, $example);
    }

    /**
     * Read the record type a call can't go without.
     */
    public function requiredType(string $expected, string $example): RecordType
    {
        return $this->type($expected, $example) ?? throw Refusal::missing(argument: 'type', accepted: $this->listed($this->cases('type', RecordType::class)), example: $example);
    }

    /**
     * Read the type with groups, which only a call that names a group can go without.
     */
    public function typeWithGroups(?string $group, string $example): ?RecordType
    {
        $expected = __('firewatch::messages.argument_values.grouped_type');

        if ($group === null) {
            return $this->requiredType($expected, $example);
        }

        return $this->type($expected, $example);
    }

    /**
     * Read the side of a test run's store the call keeps, or the declared default when none is given.
     */
    public function scope(string $example): Scope
    {
        return $this->choiceOrDefault('scope', Scope::class, __('firewatch::messages.argument_values.scope'), $example);
    }

    /**
     * Read the test the call is limited to, or null when none is given.
     */
    public function test(string $example): ?string
    {
        $value = $this->get('test');

        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        $test = __('firewatch::messages.argument_values.test');

        throw $this->invalid(argument: 'test', expected: $test, accepted: $test, example: $example);
    }

    /**
     * Read the worker of the actor the call names, or null when none is given.
     */
    public function worker(string $example): ?string
    {
        $value = $this->get('worker');

        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        $worker = __('firewatch::messages.argument_values.worker');

        throw $this->invalid(argument: 'worker', expected: $worker, accepted: $worker, example: $example);
    }

    /**
     * Read one of the declared cases, or the declared default when none is given.
     *
     * @template TCase of BackedEnum
     *
     * @param  class-string<TCase>  $enum
     * @return TCase|null
     */
    public function choice(string $argument, string $enum, string $expected, string $example): ?BackedEnum
    {
        return $this->among($argument, $this->cases($argument, $enum), $expected, $example);
    }

    /**
     * Read one of the declared cases, or the default an argument that declares one falls back to.
     *
     * @template TCase of BackedEnum
     *
     * @param  class-string<TCase>  $enum
     * @return TCase
     */
    public function choiceOrDefault(string $argument, string $enum, string $expected, string $example): BackedEnum
    {
        return $this->amongOrDefault($argument, $this->cases($argument, $enum), $expected, $example);
    }

    /**
     * Read one of the fitting cases, or the default an argument that declares one falls back to.
     *
     * @template TCase of BackedEnum
     *
     * @param  list<TCase>  $fitting
     * @return TCase
     */
    public function amongOrDefault(string $argument, array $fitting, string $expected, string $example): BackedEnum
    {
        return $this->among($argument, $fitting, $expected, $example) ?? throw new LogicException("`{$argument}` declares no default that fits.");
    }

    /**
     * Read one of the fitting cases, matched exactly, or the declared default when none is given.
     *
     * @template TCase of BackedEnum
     *
     * @param  list<TCase>  $fitting
     * @return TCase|null
     */
    public function among(string $argument, array $fitting, string $expected, string $example): ?BackedEnum
    {
        $value = $this->get($argument);

        if ($value === null) {
            return $this->declaredCase($argument, $fitting);
        }

        foreach ($fitting as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw $this->invalid(argument: $argument, expected: $expected, accepted: $this->listed($fitting), example: $example);
    }

    /**
     * Get the case the declared default names, or null when the argument declares none.
     *
     * @template TCase of BackedEnum
     *
     * @param  list<TCase>  $cases
     * @return TCase|null
     */
    protected function declaredCase(string $argument, array $cases): ?BackedEnum
    {
        $default = $this->declared($argument)['default'] ?? null;

        foreach ($cases as $case) {
            if ($case->value === $default) {
                return $case;
            }
        }

        return null;
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
     * Read the substring of the declared length a match must contain, or null when none is given.
     */
    public function matching(string $example): ?string
    {
        $value = $this->text('matching', $example);

        if ($value === null) {
            return null;
        }

        $declared = $this->declared('matching');
        $length = [
            'minimum' => $declared['minLength'],
            'maximum' => $declared['maxLength'],
        ];

        if (mb_strlen($value) >= $length['minimum'] && mb_strlen($value) <= $length['maximum']) {
            return $value;
        }

        throw $this->invalid(argument: 'matching', expected: __('firewatch::messages.argument_values.length', $length), accepted: __('firewatch::messages.argument_values.substring', $length), example: $example);
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
     * Get the refusal of the argument's value, shown as JSON.
     */
    public function invalid(string $argument, string $expected, string $accepted, string $example): Refusal
    {
        $shown = json_encode($this->get($argument), JSON_THROW_ON_ERROR);

        return Refusal::invalid(argument: $argument, expected: $expected, value: $shown, accepted: $accepted, example: $example);
    }

    /**
     * Get the cases the tool's schema declares for the argument, in its order.
     *
     * @template TCase of BackedEnum
     *
     * @param  class-string<TCase>  $enum
     * @return list<TCase>
     */
    protected function cases(string $argument, string $enum): array
    {
        /** @var list<string> $values */
        $values = $this->declared($argument)['enum'];

        return array_map($enum::from(...), $values);
    }

    /**
     * Read a boolean argument, or the default its schema declares when it is not given.
     */
    public function boolean(string $argument, string $example): bool
    {
        $value = $this->get($argument) ?? $this->declared($argument)['default'];

        if (is_bool($value)) {
            return $value;
        }

        throw $this->invalid($argument, 'true or false', 'true or false', $example);
    }

    /**
     * Get what the tool's schema declares for the argument.
     *
     * @return array<string, mixed>
     */
    protected function declared(string $argument): array
    {
        $this->schema ??= array_map(fn (Type $type) => $type->toArray(), $this->tool->schema(new JsonSchemaTypeFactory));

        return $this->schema[$argument];
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
