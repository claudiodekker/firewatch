<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
readonly class Within
{
    /**
     * The example a refusal of the group to rank within gives.
     */
    public const EXAMPLE = 'rank(type: "query", within: "<group id>")';

    /**
     * Create a new within instance.
     */
    public function __construct(
        public RecordType $type,
        public string $group,
    ) {
        //
    }

    /**
     * Resolve the execution group the store holds under the id, null when it holds none, refusing the group of a record that is no execution.
     */
    public static function resolve(SQLite3 $connection, string $group): ?self
    {
        $held = Stored::holders($connection, $group);
        $executions = array_values(array_filter($held, fn (RecordType $type) => $type->isExecution()));

        if ($held === []) {
            return null;
        }

        if ($executions === []) {
            $expected = __('firewatch::messages.argument_values.execution_group');

            throw Refusal::invalid(argument: 'within', expected: $expected, value: json_encode($group, JSON_THROW_ON_ERROR), accepted: $expected, example: self::EXAMPLE);
        }

        return new self($executions[0], $group);
    }

    /**
     * Get the SQL condition of the records the group's executions ran: the executions start in the window, and their records are read whole.
     */
    public function condition(Window $window): string
    {
        return "execution_id IN (SELECT execution_id FROM {$this->type->view()} WHERE group_hash = :within AND {$window->condition($this->type)})";
    }

    /**
     * Read how many executions of the group started in the window, and the label of the latest.
     *
     * @return array{group: string, type: string, label: string, executions: int}
     */
    public function read(SQLite3 $connection, Window $window): array
    {
        $row = Stored::rows($connection, "SELECT count(*) AS executions, max(CASE WHEN latest = 1 THEN label END) AS label FROM (
            SELECT {$this->type->labelColumn()} AS label, ROW_NUMBER() OVER (ORDER BY started_at DESC, id DESC) AS latest
            FROM {$this->type->view()} WHERE group_hash = :within AND {$window->condition($this->type)}
        )", ['within' => $this->group], $window)[0];

        return [
            'group' => $this->group,
            'type' => $this->type->value,
            'label' => $this->type->groupLabel($row['label']),
            'executions' => $row['executions'],
        ];
    }
}
