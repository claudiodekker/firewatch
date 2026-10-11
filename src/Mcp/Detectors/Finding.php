<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
readonly class Finding
{
    /**
     * Create a new finding instance.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public ?string $group,
        public string $name,
        public int|float|null $count,
        public int|float|null $firstSeenAt,
        public int|float|null $lastSeenAt,
        public ?string $latestExecutionId,
        public int $signedInActors,
        public int $withoutActor,
        public array $evidence,
        public ?string $worstExecutionId = null,
    ) {
        //
    }

    /**
     * Get the finding of a row that carries the columns `Fragment::reach()` selects beside its group and latest execution.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $evidence
     */
    public static function from(array $row, string $name, int|float|null $count, array $evidence, ?string $worstExecutionId = null): self
    {
        return new self(
            group: $row['group_hash'] ?? null,
            name: $name,
            count: $count,
            firstSeenAt: $row['first_seen'],
            lastSeenAt: $row['last_seen'],
            latestExecutionId: $row['latest_execution_id'],
            signedInActors: $row['actors'],
            withoutActor: $row['anonymous'],
            evidence: $evidence,
            worstExecutionId: $worstExecutionId,
        );
    }

    /**
     * Get the execution to open for the finding: the worst one when the finding names it, the latest otherwise.
     */
    public function executionId(): ?string
    {
        return $this->worstExecutionId ?? $this->latestExecutionId;
    }

    /**
     * Get the finding as the answer carries it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'name' => $this->name,
            'count' => $this->count,
            'first_seen_at' => $this->firstSeenAt,
            'last_seen_at' => $this->lastSeenAt,
            'latest_execution_id' => $this->latestExecutionId,
            ...($this->worstExecutionId === null ? [] : ['worst_execution_id' => $this->worstExecutionId]),
            'reaches' => [
                'signed_in_actors' => $this->signedInActors,
                'without_actor' => $this->withoutActor,
            ],
            'evidence' => $this->evidence,
        ];
    }
}
