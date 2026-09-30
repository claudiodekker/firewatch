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
     * Create a new configuration issue instance.
     */
    public function __construct(
        public string $key,
        public string $reason,
        public string $effect,
    ) {}

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
     * Get the issue as the one line the console report and the doctor print.
     */
    public function line(): string
    {
        return "firewatch.{$this->key}: {$this->reason}; {$this->effect}";
    }
}
