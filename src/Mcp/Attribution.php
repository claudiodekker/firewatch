<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class Attribution
{
    /**
     * The executions that carry no user of their own, so that only a child can tie them to the person.
     */
    protected const WITHOUT_USER = [RecordType::COMMAND, RecordType::SCHEDULED_TASK];

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
        $executionCounts = self::executionsByLink($connection, $window, $bindings);
        $recordCounts = self::recordsByLink($connection, $window, $bindings);
        $listed = self::listed($connection, $window, $bindings, $limit);

        $linksOf = fn (RecordType $type) => $executionCounts[$type->value] ?? [];
        $requests = $linksOf(RecordType::REQUEST);
        $attempts = $linksOf(RecordType::JOB_ATTEMPT);

        $counts = [
            'requests' => [
                'total' => array_sum($requests),
                'this_actor' => $requests[AttributionLink::DIRECT->value] ?? 0,
                'other_actors' => $requests[AttributionLink::OTHER_ACTOR->value] ?? 0,
                'guest' => $requests[AttributionLink::UNATTRIBUTABLE->value] ?? 0,
            ],
            'job_attempts' => [
                'total' => array_sum($attempts),
                'this_actor' => ($attempts[AttributionLink::DIRECT->value] ?? 0) + ($attempts[AttributionLink::DISPATCH->value] ?? 0),
                'other_actors' => $attempts[AttributionLink::OTHER_ACTOR->value] ?? 0,
                'no_actor' => $attempts[AttributionLink::UNATTRIBUTABLE->value] ?? 0,
            ],
            'commands' => self::insideCounts($linksOf(RecordType::COMMAND)),
            'scheduled_tasks' => self::insideCounts($linksOf(RecordType::SCHEDULED_TASK)),
            'records' => self::recordTotals($recordCounts),
        ];

        $activity = array_map(fn (RecordType $type) => [
            'type' => $type->value,
            'direct' => self::recordsOf($recordCounts, $type, AttributionLink::DIRECT),
            'dispatch' => self::recordsOf($recordCounts, $type, AttributionLink::DISPATCH),
            'can_carry_actor' => ! in_array($type, self::WITHOUT_USER, true),
        ], RecordType::events());

        $links = [
            'direct' => $counts['requests']['this_actor'] + ($attempts[AttributionLink::DIRECT->value] ?? 0),
            'dispatch' => $attempts[AttributionLink::DISPATCH->value] ?? 0,
            'inside' => $counts['commands']['this_actor'] + $counts['scheduled_tasks']['this_actor'],
        ];

        $recorded = array_filter($recordCounts, fn (array $row) => $row['own'] === 1) !== [];

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
     * Get how many executions of the window are unattributable: the requests with no recorded user, the attempts with no actor and the commands and tasks with no child of the person.
     */
    public function unattributable(): int
    {
        return $this->requestsWithoutUser() + $this->attemptsWithoutActor() + $this->counts['commands']['unattributable'] + $this->counts['scheduled_tasks']['unattributable'];
    }

    /**
     * Get how many requests of the window carry no recorded user.
     */
    public function requestsWithoutUser(): int
    {
        return $this->counts['requests']['guest'];
    }

    /**
     * Get how many job attempts of the window have no recorded user and no traceable dispatch.
     */
    public function attemptsWithoutActor(): int
    {
        return $this->counts['job_attempts']['no_actor'];
    }

    /**
     * Get how many commands ran in the window.
     */
    public function commands(): int
    {
        return $this->counts['commands']['total'];
    }

    /**
     * Get how many scheduled tasks ran in the window.
     */
    public function scheduledTasks(): int
    {
        return $this->counts['scheduled_tasks']['total'];
    }

    /**
     * Get how many commands and tasks of the window the person is inside of.
     */
    public function inside(): int
    {
        return $this->links['inside'];
    }

    /**
     * Get the SQL of the attribution link of an execution record to the person, or why it has none.
     *
     * This is the one place that decides a link. Direct and other actor are read from the record's own user on the types that carry one; dispatch from the user of the job's dispatch, the highest store id when there are several, as the specification fixes it, and only for an attempt with no user of its own; inside from a child of a command or task that carries the person. The links are looked up over the whole store, whatever the window.
     */
    protected static function linkOf(string $execution): string
    {
        $withUser = self::parameters(array_filter(ExecutionType::records(), fn (RecordType $type) => ! in_array($type, self::WITHOUT_USER, true)));
        $withoutUser = self::parameters(self::WITHOUT_USER);
        $sources = implode(', ', array_map(fn (RecordType $type) => ':'.self::parameter($type).'_source', self::WITHOUT_USER));

        return "CASE
            WHEN {$execution}.type IN ({$withUser}) AND NULLIF({$execution}.user_id, '') = :actor THEN :direct
            WHEN {$execution}.type IN ({$withUser}) AND NULLIF({$execution}.user_id, '') IS NOT NULL THEN :other_actor
            WHEN {$execution}.type = :job_attempt THEN COALESCE((
                SELECT CASE WHEN NULLIF(d.user_id, '') = :actor THEN :dispatch WHEN NULLIF(d.user_id, '') IS NOT NULL THEN :other_actor END
                FROM records d WHERE d.job_id = {$execution}.job_id AND d.type = :queued_job ORDER BY d.id DESC LIMIT 1
            ), :unattributable)
            WHEN {$execution}.type IN ({$withoutUser}) AND ({$execution}.execution_id, {$execution}.source) IN (
                SELECT execution_id, source FROM records WHERE user_id = NULLIF(:actor, '') AND source IN ({$sources})
            ) THEN :inside
            ELSE :unattributable
        END";
    }

    /**
     * Get the SQL of the executions of the window with their link, as the table `linked`.
     */
    protected static function linked(Window $window): string
    {
        $link = self::linkOf('e');
        $executions = self::parameters(ExecutionType::records());

        return "WITH linked AS (
            SELECT e.id, e.type, e.execution_id, e.started_at, e.group_hash, {$link} AS link
            FROM records e WHERE e.type IN ({$executions}) AND {$window->condition()}
        )";
    }

    /**
     * Count the executions of the window by type and link.
     *
     * @param  array<string, string>  $bindings
     * @return array<string, array<string, int>>
     */
    protected static function executionsByLink(SQLite3 $connection, Window $window, array $bindings): array
    {
        $rows = Stored::rows($connection, self::linked($window).' SELECT type, link, count(*) AS executions FROM linked GROUP BY type, link', $bindings, $window);
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['type']][$row['link']] = $row['executions'];
        }

        return $counts;
    }

    /**
     * Count the records of the window of the twelve types by type, by what ties the record to the person and by whether the person is its own user.
     *
     * A record is direct by its own user, else someone else's by its own user, else it takes the direct or dispatch link of its execution, else it is unattributable. Its execution is itself for an execution record, else the execution record of its id and source that started last, the highest store id among those that started together, wherever it started. Such children are counted by type and execution first, so that their execution is classified once per type of child, never once per child. A command or task the person is inside of does not make its records the person's.
     *
     * @param  array<string, string>  $bindings
     * @return list<array{type: string, link: string, own: int, records: int}>
     */
    protected static function recordsByLink(SQLite3 $connection, Window $window, array $bindings): array
    {
        $types = self::parameters(RecordType::events());
        $executions = self::parameters(ExecutionType::records());
        $children = self::parameters(array_filter(RecordType::events(), fn (RecordType $type) => ! in_array($type, ExecutionType::records(), true)));
        $inheriting = "type IN ({$children}) AND NULLIF(user_id, '') IS NULL AND execution_id IS NOT NULL";
        $ownLink = self::linkOf('r');
        $executionLink = self::linkOf('x');

        /** @var list<array{type: string, link: string, own: int, records: int}> */
        return Stored::rows($connection, "WITH inheriting AS (
                SELECT type, execution_id, source, count(*) AS records FROM records
                WHERE {$inheriting} AND {$window->condition()}
                GROUP BY type, execution_id, source
            )
            SELECT type, CASE
                WHEN own_user = :actor THEN :direct
                WHEN own_user IS NOT NULL THEN :other_actor
                WHEN execution_link IN (:direct, :dispatch) THEN execution_link
                ELSE :unattributable
            END AS link, COALESCE(own_user = :actor, 0) AS own, sum(records) AS records
            FROM (
                SELECT type, NULL AS own_user, (
                    SELECT {$executionLink} FROM records x WHERE x.id = (
                        SELECT y.id FROM records y
                        WHERE y.execution_id = inheriting.execution_id AND y.source = inheriting.source AND y.type IN ({$executions})
                        ORDER BY y.started_at DESC, y.id DESC LIMIT 1
                    )
                ) AS execution_link, records
                FROM inheriting
                UNION ALL
                SELECT r.type, NULLIF(r.user_id, ''), CASE WHEN r.type IN ({$executions}) THEN {$ownLink} END, 1
                FROM records r WHERE r.type IN ({$types}) AND {$window->condition()} AND NOT ({$inheriting})
            )
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
            fn (RecordType $type) => 'WHEN :'.self::parameter($type).' THEN (SELECT '.Ranking::labelField($type)." FROM {$type->view()} v WHERE v.id = linked.id)",
            ExecutionType::records(),
        ));

        $links = implode(', ', array_map(fn (AttributionLink $link) => ":{$link->value}", AttributionLink::links()));

        $rows = Stored::rows($connection, self::linked($window)." SELECT started_at, type, execution_id, group_hash, link, CASE type {$labels} END AS label
            FROM linked WHERE link IN ({$links}) ORDER BY started_at DESC, id DESC LIMIT :limit", [
            ...$bindings,
            'limit' => Rows::fetch($limit),
        ], $window);

        return array_map(fn (array $row) => [
            'started_at' => $row['started_at'],
            'type' => $row['type'],
            'execution_id' => $row['execution_id'],
            'group_hash' => $row['group_hash'],
            'label' => Ranking::shownLabel(RecordType::from($row['type']), $row['label']),
            'link' => AttributionLink::from($row['link'])->value,
        ], $rows);
    }

    /**
     * Get the counts of the commands or of the tasks of the window: those the person is inside of, and the rest.
     *
     * @param  array<string, int>  $executions  by link
     * @return array{total: int, this_actor: int, unattributable: int}
     */
    protected static function insideCounts(array $executions): array
    {
        $total = array_sum($executions);
        $inside = $executions[AttributionLink::INSIDE->value] ?? 0;

        return [
            'total' => $total,
            'this_actor' => $inside,
            'unattributable' => $total - $inside,
        ];
    }

    /**
     * Get the counts of the records of the window: the person's, and those with no user of their own whose execution is not the person's.
     *
     * @param  list<array{type: string, link: string, own: int, records: int}>  $recordCounts
     * @return array{in_window: int, this_actor: int, without_actor: int}
     */
    protected static function recordTotals(array $recordCounts): array
    {
        $count = fn (AttributionLink ...$links) => array_sum(array_column(array_filter($recordCounts, fn (array $row) => in_array(AttributionLink::from($row['link']), $links, true)), 'records'));

        return [
            'in_window' => array_sum(array_column($recordCounts, 'records')),
            'this_actor' => $count(AttributionLink::DIRECT, AttributionLink::DISPATCH),
            'without_actor' => $count(AttributionLink::UNATTRIBUTABLE),
        ];
    }

    /**
     * Get how many records of the window of a type are the person's by a link.
     *
     * @param  list<array{type: string, link: string, own: int, records: int}>  $recordCounts
     */
    protected static function recordsOf(array $recordCounts, RecordType $type, AttributionLink $link): int
    {
        $kept = array_filter($recordCounts, fn (array $row) => $row['type'] === $type->value && $row['link'] === $link->value);

        return array_sum(array_column($kept, 'records'));
    }

    /**
     * Get the values every query of the attribution binds: the person, the twelve types and the links.
     *
     * @return array<string, string>
     */
    protected static function bindings(string $id): array
    {
        $values = ['actor' => $id];

        foreach (RecordType::events() as $type) {
            $values[self::parameter($type)] = $type->value;
        }

        foreach (self::WITHOUT_USER as $type) {
            $values[self::parameter($type).'_source'] = (string) $type->source();
        }

        foreach (AttributionLink::cases() as $link) {
            $values[$link->value] = $link->value;
        }

        return $values;
    }

    /**
     * Get the parameters the types are bound as, for a list in SQL.
     *
     * @param  array<RecordType>  $types
     */
    protected static function parameters(array $types): string
    {
        return implode(', ', array_map(fn (RecordType $type) => ':'.self::parameter($type), $types));
    }

    /**
     * Get the name of the parameter a type is bound as.
     */
    protected static function parameter(RecordType $type): string
    {
        return str_replace('-', '_', $type->value);
    }
}
