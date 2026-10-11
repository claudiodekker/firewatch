<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class GroupSelection
{
    /**
     * Create a new group selection instance.
     *
     * @param  list<RecordType>  $held
     */
    protected function __construct(
        protected ?string $group,
        protected ?RecordType $named,
        protected ?string $deploy,
        protected array $held,
    ) {
        //
    }

    /**
     * Resolve the selection against the store's snapshot, refusing a named type that does not hold the group.
     */
    public static function resolve(SQLite3 $connection, ?string $group, ?RecordType $named, ?string $deploy, string $example): self
    {
        $held = $group === null ? [] : Stored::holders($connection, $group);

        if ($named !== null && $held !== [] && ! in_array($named, $held, true)) {
            $types = implode(', ', array_map(fn (RecordType $type) => $type->value, $held));

            throw Refusal::conflicting(argument: 'type', with: 'group', accepted: __('firewatch::messages.argument_values.holding', ['types' => $types]), example: $example);
        }

        return new self($group, $named, $deploy, $held);
    }

    /**
     * Get the type a measure reads: the named type, else the job attempts of a job group, else the type that holds the group.
     */
    public function measured(): ?RecordType
    {
        if ($this->named !== null) {
            return $this->named;
        }

        return in_array(RecordType::JOB_ATTEMPT, $this->held, true) ? RecordType::JOB_ATTEMPT : ($this->held[0] ?? null);
    }

    /**
     * Get the type a listing reads: the named type, else the one type that holds the group, or null to list every type that does.
     */
    public function listed(): ?RecordType
    {
        if ($this->named !== null) {
            return $this->named;
        }

        return count($this->held) === 1 ? $this->held[0] : null;
    }

    /**
     * Determine if the store holds the group, or no group is selected.
     */
    public function isHeld(): bool
    {
        return $this->group === null || $this->held !== [];
    }

    /**
     * Get the note of a job group read without a type.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        if ($this->named !== null || ! in_array(RecordType::JOB_ATTEMPT, $this->held, true) || ! in_array(RecordType::QUEUED_JOB, $this->held, true)) {
            return [];
        }

        return [__('firewatch::messages.rank_job_group', ['group' => $this->group])];
    }

    /**
     * Get why the answer is empty, naming the selection's filters around the tool's own, or null when it is not.
     *
     * @param  list<string>  $own
     */
    public function vacancy(Frame $frame, int $inWindow, bool $hasRows, array $own = []): ?Emptiness
    {
        $filters = [
            ...($this->group === null ? [] : ["group: {$this->group}"]),
            ...($this->named === null ? [] : ["type: {$this->named->value}"]),
            ...$own,
            ...($this->deploy === null ? [] : ["deploy: {$this->deploy}"]),
        ];

        return $frame->vacancy(inWindow: $inWindow, groupHeld: $this->isHeld(), hasRows: $hasRows, filters: $filters);
    }
}
