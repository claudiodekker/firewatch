<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\RecordType;
use Workbench\App\Fixtures\Placeholder;
use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\Sensors;
use Workbench\App\Fixtures\WireFixture;

class RecordBuilder
{
    /**
     * The wire fields of the record, in the order the fixture holds them.
     *
     * @var array<string, mixed>
     */
    protected array $fields;

    /**
     * Create a new record builder instance from the type's wire fixture.
     */
    public function __construct(protected RecordType $type)
    {
        $fixture = (new WireFixture(new Sensors))->load(Producer::from($type->value));

        $this->fields = array_map(
            fn (mixed $value) => is_string($value) ? Placeholder::tryFrom($value)?->resolve() ?? $value : $value,
            $fixture,
        );
    }

    /**
     * Set wire fields, replacing the fixture's values.
     *
     * @param  array<string, mixed>  $fields
     * @return $this
     */
    public function with(array $fields): static
    {
        $this->fields = [...$this->fields, ...$fields];

        return $this;
    }

    /**
     * Leave wire fields out, as a sensor that stopped writing them would.
     *
     * @return $this
     */
    public function without(string ...$fields): static
    {
        $this->fields = array_diff_key($this->fields, array_flip($fields));

        return $this;
    }

    /**
     * Place the record in the given execution, or make an execution record that execution.
     *
     * @return $this
     */
    public function inExecution(string $id): static
    {
        $field = match (true) {
            $this->type === RecordType::JOB_ATTEMPT => 'attempt_id',
            $this->type->source() !== null => 'trace_id',
            default => 'execution_id',
        };

        return $this->with([$field => $id]);
    }

    /**
     * Stamp the record with the given deploy.
     *
     * @return $this
     */
    public function deploy(string $deploy): static
    {
        return $this->with(['deploy' => $deploy]);
    }

    /**
     * Get the record as the wire array a sensor would write.
     *
     * @return array<string, mixed>
     */
    public function make(): array
    {
        return $this->fields;
    }
}
