<?php

namespace ClaudioDekker\Firewatch\Configuration;

/**
 * @internal
 */
readonly class ConfigurationIssue
{
    public function __construct(
        public string $key,
        public string $reason,
        public string $effect,
    ) {}

    public static function fallBack(string $key, string $reason, string $default): static
    {
        return new static(key: $key, reason: $reason, effect: "using {$default}");
    }

    public static function droppedItem(string $key, string $reason): static
    {
        return new static(key: $key, reason: $reason, effect: 'dropped');
    }

    public static function ignoredBudgetEntry(int $number, string $reason): static
    {
        return new static(key: "budgets[{$number}]", reason: $reason, effect: 'entry ignored');
    }

    public function line(): string
    {
        return "firewatch.{$this->key}: {$this->reason}; {$this->effect}";
    }
}
