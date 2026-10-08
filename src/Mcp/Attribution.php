<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Mcp\Detectors\Executions;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class Attribution
{
    /**
     * The class of work whose recorded user, or whose dispatch, names someone else.
     */
    protected const OTHER_ACTOR = 'other';

    /**
     * The class of work no link ties to anyone asked about: no recorded user, no traceable dispatch, or no child of the person.
     */
    protected const NO_ACTOR = 'none';

    /**
     * Create a new attribution instance.
     *
     * @param  array{requests: array{total: int, this_actor: int, other_actors: int, guest: int}, job_attempts: array{total: int, this_actor: int, other_actors: int, no_actor: int}, commands: array{total: int, this_actor: int, unattributable: int}, scheduled_tasks: array{total: int, this_actor: int, unattributable: int}, records: array{in_window: int, this_actor: int, without_actor: int}}  $counts  over every execution and record of the window
     * @param  list<array{type: string, direct: int, dispatch: int, can_carry_actor: bool}>  $activity  the twelve types in catalogue order
     * @param  Rows<array{started_at: float, type: string, execution_id: string, group_hash: string|null, label: string, link: string}>  $executions  newest first
     * @param  array{direct: int, dispatch: int, inside: int}  $links  the attributed executions of the window by link
     * @param  bool  $recorded  whether a record of the window carries the person's id as its own user
     */
    protected function __construct(
        public readonly array $counts,
        public readonly array $activity,
        public readonly Rows $executions,
        public readonly array $links,
        public readonly bool $recorded,
    ) {
        //
    }

    /**
     * Attribute the executions and records of the window to the person with the id, in the snapshot of the connection.
     */
    public static function of(SQLite3 $connection, Window $window, string $id, int $limit): self
    {
        $bindings = self::bindings($id);
        $executions = self::executionsByClass($connection, $window, $bindings);
        $records = self::recordsByClass($connection, $window, $bindings);
        $listed = self::listed($connection, $window, $bindings, $limit);

        $classes = fn (RecordType $type) => $executions[$type->value] ?? [];
        $requests = $classes(RecordType::REQUEST);
        $attempts = $classes(RecordType::JOB_ATTEMPT);

        $counts = [
            'requests' => [
                'total' => array_sum($requests),
                'this_actor' => $requests[Link::DIRECT->value] ?? 0,
                'other_actors' => $requests[self::OTHER_ACTOR] ?? 0,
                'guest' => $requests[self::NO_ACTOR] ?? 0,
            ],
            'job_attempts' => [
                'total' => array_sum($attempts),
                'this_actor' => ($attempts[Link::DIRECT->value] ?? 0) + ($attempts[Link::DISPATCH->value] ?? 0),
                'other_actors' => $attempts[self::OTHER_ACTOR] ?? 0,
                'no_actor' => $attempts[self::NO_ACTOR] ?? 0,
            ],
            'commands' => self::inside($classes(RecordType::COMMAND)),
            'scheduled_tasks' => self::inside($classes(RecordType::SCHEDULED_TASK)),
            'records' => self::records($records),
        ];

        $activity = array_map(fn (RecordType $type) => [
            'type' => $type->value,
            'direct' => self::sum($records, $type, Link::DIRECT),
            'dispatch' => self::sum($records, $type, Link::DISPATCH),
            'can_carry_actor' => ! in_array($type, [RecordType::COMMAND, RecordType::SCHEDULED_TASK], true),
        ], RecordType::events());

        $links = [
            'direct' => $counts['requests']['this_actor'] + ($attempts[Link::DIRECT->value] ?? 0),
            'dispatch' => $attempts[Link::DISPATCH->value] ?? 0,
            'inside' => $counts['commands']['this_actor'] + $counts['scheduled_tasks']['this_actor'],
        ];

        $recorded = array_filter($records, fn (array $row) => $row['own'] === 1) !== [];

        return new self($counts, $activity, Rows::bound($listed, $limit), $links, $recorded);
    }

    /**
     * Get the blocks of the result that follow the identity, in the fixed order.
     *
     * @return array{attribution: array<string, array<string, int>>, activity: list<array{type: string, direct: int, dispatch: int, can_carry_actor: bool}>, executions: list<array{started_at: float, type: string, execution_id: string, group_hash: string|null, label: string, link: string}>}
     */
    public function result(): array
    {
        return [
            'attribution' => $this->counts,
            'activity' => $this->activity,
            'executions' => $this->executions->rows,
        ];
    }

    /**
     * Get how many executions of the window there are, of every type.
     */
    public function total(): int
    {
        return $this->counts['requests']['total'] + $this->counts['job_attempts']['total'] + $this->counts['commands']['total'] + $this->counts['scheduled_tasks']['total'];
    }

    /**
     * Get how many executions of the window are attributed to the person, by any link.
     */
    public function attributed(): int
    {
        return array_sum($this->links);
    }

    /**
     * Get how many executions of the window no link ties to anyone asked about: the guests, the attempts with no actor and the commands and tasks with no child of the person.
     */
    public function unattributed(): int
    {
        return $this->counts['requests']['guest'] + $this->counts['job_attempts']['no_actor'] + $this->counts['commands']['unattributable'] + $this->counts['scheduled_tasks']['unattributable'];
    }

    /**
     * Get the SQL of the class of an execution record: its link to the person, or whether it belongs to someone else or to no one.
     *
     * This is the one place that decides a link. Direct and other are read from the record's own user on the two types that carry one; dispatch from the user of the job's dispatch, the latest when there are several, and only for an attempt with no user of its own; inside from a child of a command or task that carries the person. The links are looked up over the whole store, whatever the window.
     */
    protected static function classOf(string $execution): string
    {
        return "CASE
            WHEN {$execution}.type IN (:request, :job_attempt) AND NULLIF({$execution}.user_id, '') = :actor THEN :direct
            WHEN {$execution}.type IN (:request, :job_attempt) AND NULLIF({$execution}.user_id, '') IS NOT NULL THEN :other
            WHEN {$execution}.type = :job_attempt THEN COALESCE((
                SELECT CASE WHEN NULLIF(d.user_id, '') = :actor THEN :dispatch WHEN NULLIF(d.user_id, '') IS NOT NULL THEN :other END
                FROM records d WHERE d.job_id = {$execution}.job_id AND d.type = :queued_job ORDER BY d.id DESC LIMIT 1
            ), :none)
            WHEN {$execution}.type IN (:command, :scheduled_task) AND EXISTS (
                SELECT 1 FROM records c
                WHERE c.execution_id = {$execution}.execution_id AND c.source = {$execution}.source AND c.id <> {$execution}.id AND NULLIF(c.user_id, '') = :actor
            ) THEN :inside
            ELSE :none
        END";
    }

    /**
     * Get the SQL of the executions of the window with their class, as the table `classified`.
     */
    protected static function classified(Window $window): string
    {
        $class = self::classOf('e');

        return "WITH classified AS (
            SELECT e.id, e.type, e.execution_id, e.started_at, e.group_hash, {$class} AS class
            FROM records e WHERE e.type IN (:request, :command, :job_attempt, :scheduled_task) AND {$window->condition()}
        )";
    }

    /**
     * Count the executions of the window by type and class.
     *
     * @param  array<string, string>  $bindings
     * @return array<string, array<string, int>>
     */
    protected static function executionsByClass(SQLite3 $connection, Window $window, array $bindings): array
    {
        $rows = Stored::rows($connection, self::classified($window).' SELECT type, class, count(*) AS executions FROM classified GROUP BY type, class', $bindings, $window);
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['type']][$row['class']] = $row['executions'];
        }

        return $counts;
    }

    /**
     * Count the records of the window of the twelve types by type, by the class of the record and by whether the person is its own user.
     *
     * A record with no user of its own takes the class of its execution: itself for an execution record, else the latest execution record of its id and source, wherever it started. A command or task the person is inside of does not make its records the person's.
     *
     * @param  array<string, string>  $bindings
     * @return list<array{type: string, class: string, own: int, records: int}>
     */
    protected static function recordsByClass(SQLite3 $connection, Window $window, array $bindings): array
    {
        $types = implode(', ', array_map(fn (RecordType $type) => ':'.self::parameter($type), RecordType::events()));
        $ownClass = self::classOf('r');
        $executionClass = self::classOf('x');

        /** @var list<array{type: string, class: string, own: int, records: int}> */
        return Stored::rows($connection, "SELECT r.type, CASE
                WHEN NULLIF(r.user_id, '') = :actor THEN :direct
                WHEN NULLIF(r.user_id, '') IS NOT NULL THEN :other
                WHEN r.type IN (:request, :command, :job_attempt, :scheduled_task) THEN {$ownClass}
                ELSE COALESCE((
                    SELECT {$executionClass} FROM records x
                    WHERE x.execution_id = r.execution_id AND x.source = r.source AND x.type IN (:request, :command, :job_attempt, :scheduled_task)
                    ORDER BY x.id DESC LIMIT 1
                ), :none)
            END AS class, COALESCE(NULLIF(r.user_id, '') = :actor, 0) AS own, count(*) AS records
            FROM records r WHERE r.type IN ({$types}) AND {$window->condition()}
            GROUP BY 1, 2, 3", $bindings, $window);
    }

    /**
     * Read the attributed executions of the window, newest first, one more than the limit.
     *
     * @param  array<string, string>  $bindings
     * @return list<array{started_at: float, type: string, execution_id: string, group_hash: string|null, label: string, link: string}>
     */
    protected static function listed(SQLite3 $connection, Window $window, array $bindings, int $limit): array
    {
        $labels = implode(' ', array_map(
            fn (RecordType $type) => 'WHEN :'.self::parameter($type).' THEN (SELECT '.Ranking::labelField($type)." FROM {$type->view()} v WHERE v.id = classified.id)",
            Executions::TYPES,
        ));

        $rows = Stored::rows($connection, self::classified($window)." SELECT started_at, type, execution_id, group_hash, class, CASE type {$labels} END AS label
            FROM classified WHERE class IN (:direct, :dispatch, :inside) ORDER BY started_at DESC, id DESC LIMIT :limit", [
            ...$bindings,
            'limit' => Rows::fetch($limit),
        ], $window);

        return array_map(fn (array $row) => [
            'started_at' => $row['started_at'],
            'type' => $row['type'],
            'execution_id' => $row['execution_id'],
            'group_hash' => $row['group_hash'],
            'label' => Ranking::shownLabel(RecordType::from($row['type']), $row['label']),
            'link' => $row['class'],
        ], $rows);
    }

    /**
     * Get the counts of the commands or tasks of the window: those the person is inside of, and the rest.
     *
     * @param  array<string, int>  $classes
     * @return array{total: int, this_actor: int, unattributable: int}
     */
    protected static function inside(array $classes): array
    {
        $total = array_sum($classes);
        $inside = $classes[Link::INSIDE->value] ?? 0;

        return [
            'total' => $total,
            'this_actor' => $inside,
            'unattributable' => $total - $inside,
        ];
    }

    /**
     * Get the counts of the records of the window: the person's, and those with no actor at all.
     *
     * @param  list<array{type: string, class: string, own: int, records: int}>  $records
     * @return array{in_window: int, this_actor: int, without_actor: int}
     */
    protected static function records(array $records): array
    {
        $count = fn (string ...$classes) => array_sum(array_column(array_filter($records, fn (array $row) => in_array($row['class'], $classes, true)), 'records'));

        return [
            'in_window' => array_sum(array_column($records, 'records')),
            'this_actor' => $count(Link::DIRECT->value, Link::DISPATCH->value),
            'without_actor' => $count(self::NO_ACTOR, Link::INSIDE->value),
        ];
    }

    /**
     * Get how many records of the window of a type are the person's by a link.
     *
     * @param  list<array{type: string, class: string, own: int, records: int}>  $records
     */
    protected static function sum(array $records, RecordType $type, Link $link): int
    {
        $kept = array_filter($records, fn (array $row) => $row['type'] === $type->value && $row['class'] === $link->value);

        return array_sum(array_column($kept, 'records'));
    }

    /**
     * Get the values every query of the attribution binds: the person, the twelve types and the classes.
     *
     * @return array<string, string>
     */
    protected static function bindings(string $id): array
    {
        $types = [];

        foreach (RecordType::events() as $type) {
            $types[self::parameter($type)] = $type->value;
        }

        return [
            ...$types,
            'actor' => $id,
            'direct' => Link::DIRECT->value,
            'dispatch' => Link::DISPATCH->value,
            'inside' => Link::INSIDE->value,
            'other' => self::OTHER_ACTOR,
            'none' => self::NO_ACTOR,
        ];
    }

    /**
     * Get the name of the parameter a type is bound as.
     */
    protected static function parameter(RecordType $type): string
    {
        return str_replace('-', '_', $type->value);
    }
}
