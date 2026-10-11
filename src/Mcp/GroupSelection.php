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
     * @param  list<RecordType>  $held  the types that hold the group, none when no group is selected
     */
    protected function __construct(
        public readonly ?string $group,
        public readonly ?RecordType $named,
        public readonly ?string $deploy,
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
     * Get the type a measure reads: the named type, else the type the group is best known by.
     *
     * A job group is held by its dispatches and its attempts, and the attempts carry the execution measures.
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
     * Determine if the store holds the group, which a selection without one always does.
     */
    public function isHeld(): bool
    {
        return $this->group === null || $this->held !== [];
    }

    /**
     * Get the note of a job group read without a type, whose dispatches and attempts share its id.
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
     * Get the filters an empty answer names: the group, the named type, the tool's own, then the deploy.
     *
     * @param  list<string>  $own
     * @return list<string>
     */
    public function filters(array $own = []): array
    {
        return [
            ...($this->group === null ? [] : ["group: {$this->group}"]),
            ...($this->named === null ? [] : ["type: {$this->named->value}"]),
            ...$own,
            ...($this->deploy === null ? [] : ["deploy: {$this->deploy}"]),
        ];
    }
}
