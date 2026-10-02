<?php

namespace ClaudioDekker\Firewatch\Configuration;

/**
 * @internal
 */
readonly class Configuration
{
    /**
     * Create a new resolved configuration instance.
     *
     * @param  list<string>  $environments
     * @param  list<string>  $redactPayloadFields
     * @param  list<string>  $redactHeaders
     * @param  list<BudgetEntry>  $budgets
     * @param  list<ConfigurationIssue>  $issues
     */
    public function __construct(
        public bool $enabled,
        public array $environments,
        public string $database,
        public int $busyTimeoutMilliseconds,
        public string $retentionAge,
        public int $retentionAgeSeconds,
        public int $retentionRecords,
        public ?string $deploy,
        public bool $captureLogs,
        public bool $captureRequestPayload,
        public array $redactPayloadFields,
        public array $redactHeaders,
        public array $budgets,
        public int $ignoredBudgetEntries,
        public array $issues,
    ) {
        //
    }

    /**
     * Get the same configuration with another busy timeout, for work that waits longer than capture does.
     */
    public function withBusyTimeout(int $milliseconds): self
    {
        return new self(
            $this->enabled,
            $this->environments,
            $this->database,
            $milliseconds,
            $this->retentionAge,
            $this->retentionAgeSeconds,
            $this->retentionRecords,
            $this->deploy,
            $this->captureLogs,
            $this->captureRequestPayload,
            $this->redactPayloadFields,
            $this->redactHeaders,
            $this->budgets,
            $this->ignoredBudgetEntries,
            $this->issues,
        );
    }
}
