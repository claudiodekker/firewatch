<?php

namespace ClaudioDekker\Firewatch\Configuration;

/**
 * @internal
 */
readonly class Configuration
{
    /**
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
    ) {}
}
