<?php

namespace ClaudioDekker\Firewatch\Configuration;

/**
 * @internal
 *
 * @phpstan-consistent-constructor
 */
readonly class ConfigurationIssue
{
    /**
     * The characters of a string value quoted in an issue's reason.
     */
    protected const DESCRIBED_VALUE_CHARACTERS = 60;

    /**
     * Create a new configuration issue instance.
     */
    public function __construct(
        public string $key,
        public string $reason,
        public string $effect,
    ) {
        //
    }

    /**
     * Create an issue for a key that falls back to its default.
     */
    public static function fallBack(string $key, string $reason, string $default): static
    {
        return new static(key: $key, reason: $reason, effect: "using {$default}");
    }

    /**
     * Create an issue for a list item that is dropped while the rest of the list is kept.
     */
    public static function droppedItem(string $key, string $reason): static
    {
        return new static(key: $key, reason: $reason, effect: 'dropped');
    }

    /**
     * Create an issue for a budget entry that is ignored, numbered by its place in the file.
     */
    public static function ignoredBudgetEntry(int $number, string $reason): static
    {
        return new static(key: "budgets[{$number}]", reason: $reason, effect: 'entry ignored');
    }

    /**
     * Describe a value for an issue's reason.
     */
    public static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => json_encode(mb_substr($value, 0, static::DESCRIBED_VALUE_CHARACTERS), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $value === null => 'null',
            $value === [] => '[]',
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }

    /**
     * Get the issue as the one line the console report and the doctor print.
     */
    public function line(): string
    {
        return "firewatch.{$this->key}: {$this->reason}; {$this->effect}";
    }
}
