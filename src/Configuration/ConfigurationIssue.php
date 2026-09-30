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

    public function line(): string
    {
        return "firewatch.{$this->key}: {$this->reason}; {$this->effect}";
    }
}
