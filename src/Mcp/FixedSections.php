<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Mcp\Detectors\Executions;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class FixedSections
{
    /**
     * The most groups the slowest list holds.
     */
    protected const SLOWEST_GROUPS = 10;

    /**
     * The most groups of one type the slowest list holds.
     */
    protected const SLOWEST_PER_TYPE = 3;

    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * Create a new fixed sections instance.
     *
     * @param  array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}  $errorRate
     * @param  list<array{type: string, group: string, label: string, occurrences: int, total_ms: float|null}>  $slowest  worst first
     * @param  int  $records  the records of the window, those of no known type included
     * @param  list<array{type: string, records: int}>  $byType  the twelve types in catalogue order
     * @param  int  $userDirectory  the rows of the user directory, whatever the window
     * @param  array{executions: int, signed_in_actors: int, without_actor: int}  $actors  people and executions, never to be summed
     * @param  string|null  $latestExecution  the id of the execution of the window that finished last, or null when none finished
     */
    protected function __construct(
        public readonly array $errorRate,
        public readonly array $slowest,
        public readonly int $records,
        public readonly array $byType,
        public readonly int $userDirectory,
        public readonly array $actors,
        public readonly ?string $latestExecution,
    ) {
        //
    }

    /**
     * Read the sections of the window, in the fixed order.
     */
    public static function read(SQLite3 $connection, Window $window): self
    {
        $errorRate = self::errorRate($connection, $window);
        $leaders = self::leaders($connection, $window);
        $slowest = self::labelled($connection, $window, $leaders);
        $counts = self::countByType($connection, $window);
        $userDirectory = self::countUsers($connection);
        $actors = self::actors($connection, $window);
        $latestExecution = self::latestExecution($connection, $window);

        $byType = array_map(fn (RecordType $type) => [
            'type' => $type->value,
            'records' => $counts[$type->value] ?? 0,
        ], RecordType::events());

        return new self(
            errorRate: $errorRate,
            slowest: $slowest,
            records: array_sum($counts),
            byType: $byType,
            userDirectory: $userDirectory,
            actors: $actors,
            latestExecution: $latestExecution,
        );
    }

    /**
     * Get the sections as the keys of a result, in the fixed order.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return [
            'error_rate' => $this->errorRate,
            'slowest_by_total_time' => $this->slowest,
            'records' => $this->records,
            'records_by_type' => $this->byType,
            'user_directory' => $this->userDirectory,
            'actors' => $this->actors,
        ];
    }

    /**
     * Get the type of the group that tops the slowest list, or null when no group of the window has a duration.
     */
    public function slowestType(): ?RecordType
    {
        return $this->slowest === [] ? null : RecordType::from($this->slowest[0]['type']);
    }

    /**
     * Get how many records of the window are of none of the twelve types.
     */
    public function unknownTypes(): int
    {
        return $this->records - array_sum(array_column($this->byType, 'records'));
    }

    /**
     * Read the server errors and client errors among the requests of the window, with their shares of the requests that have a status.
     *
     * @return array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}
     */
    protected static function errorRate(SQLite3 $connection, Window $window): array
    {
        $status = Stored::number('status_code');
        $serverError = Failure::serverError("({$status})");
        $clientError = Failure::clientError("({$status})");

        /** @var array{requests: int, with_status: int, server_errors: int, client_errors: int} $counts */
        $counts = Stored::rows($connection, "SELECT count(*) AS requests, count({$status}) AS with_status,
            count(*) FILTER (WHERE {$serverError}) AS server_errors,
            count(*) FILTER (WHERE {$clientError}) AS client_errors
            FROM requests WHERE {$window->condition()}", window: $window)[0];

        return [
            'requests' => $counts['requests'],
            'with_status' => $counts['with_status'],
            'server_errors' => $counts['server_errors'],
            'server_error_pct' => self::share($counts['server_errors'], $counts['with_status']),
            'client_errors' => $counts['client_errors'],
            'client_error_pct' => self::share($counts['client_errors'], $counts['with_status']),
        ];
    }

    /**
     * Read the groups of the window with the largest total duration: at most three of one type and ten in all, worst first.
     *
     * @return list<array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float}>
     */
    protected static function leaders(SQLite3 $connection, Window $window): array
    {
        $types = array_values(array_filter(Measure::types(), Measure::TOTAL_DURATION->fits(...)));
        $bindings = ['per_type' => self::SLOWEST_PER_TYPE];
        $branches = [];

        foreach ($types as $position => $type) {
            $bindings["position{$position}"] = $position;
            $branches[] = "SELECT :position{$position} AS position, group_hash, ".Stored::duration($type)." AS d FROM {$type->view()} WHERE group_hash IS NOT NULL AND {$window->condition()}";
        }

        $rows = Stored::rows($connection, 'WITH timed AS ('.implode(' UNION ALL ', $branches).'),
            totals AS (SELECT position, group_hash, count(*) AS occurrences, count(d) AS timed_records, sum(d) AS total FROM timed GROUP BY position, group_hash),
            placed AS (SELECT *, ROW_NUMBER() OVER (PARTITION BY position ORDER BY total DESC, occurrences DESC, group_hash) AS place FROM totals WHERE timed_records > 0)
            SELECT position, group_hash, occurrences, total FROM placed WHERE place <= :per_type', $bindings, $window);

        $leaders = array_map(fn (array $row) => [
            'type' => $types[$row['position']],
            'position' => $row['position'],
            'hash' => $row['group_hash'],
            'occurrences' => $row['occurrences'],
            'value' => $row['total'],
        ], $rows);

        // A job attempt and its dispatch share one group hash, so the type decides last.
        usort($leaders, fn (array $a, array $b) => Ranking::compare($a, $b) ?: ($a['position'] <=> $b['position']));

        return array_slice($leaders, 0, self::SLOWEST_GROUPS);
    }

    /**
     * Read the label of each leader from its latest record in the window, and get the rows of the slowest list.
     *
     * @param  list<array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float}>  $leaders
     * @return list<array{type: string, group: string, label: string, occurrences: int, total_ms: float|null}>
     */
    protected static function labelled(SQLite3 $connection, Window $window, array $leaders): array
    {
        if ($leaders === []) {
            return [];
        }

        $bindings = [];
        $branches = [];

        foreach ($leaders as $row => $leader) {
            $bindings["row{$row}"] = $row;
            $bindings["group{$row}"] = $leader['hash'];
            $field = Ranking::labelField($leader['type']);
            $branches[] = "SELECT :row{$row} AS row, (SELECT {$field} FROM {$leader['type']->view()} WHERE group_hash = :group{$row} AND {$window->condition()} ORDER BY started_at DESC, id DESC LIMIT 1) AS label";
        }

        $latest = Stored::rows($connection, implode(' UNION ALL ', $branches), $bindings, $window);
        $labels = array_column($latest, 'label', 'row');

        return array_map(fn (array $leader, int $row) => [
            'type' => $leader['type']->value,
            'group' => $leader['hash'],
            'label' => Ranking::shownLabel($leader['type'], $labels[$row]),
            'occurrences' => $leader['occurrences'],
            'total_ms' => Stored::milliseconds($leader['value']),
        ], $leaders, array_keys($leaders));
    }

    /**
     * Count the records of the window by the type they were stored with, a type that is none of the twelve included.
     *
     * @return array<string, int>
     */
    protected static function countByType(SQLite3 $connection, Window $window): array
    {
        $rows = Stored::rows($connection, "SELECT COALESCE(type, '') AS type, count(*) AS records FROM records WHERE {$window->condition()} GROUP BY 1", window: $window);

        return array_column($rows, 'records', 'type');
    }

    /**
     * Count the rows of the user directory, which has no start to window by.
     */
    protected static function countUsers(SQLite3 $connection): int
    {
        return Stored::rows($connection, 'SELECT count(*) AS users FROM users')[0]['users'];
    }

    /**
     * Read the executions of the window, the distinct users recorded on them and how many have no recorded user.
     *
     * @return array{executions: int, signed_in_actors: int, without_actor: int}
     */
    protected static function actors(SQLite3 $connection, Window $window): array
    {
        [$types, $placeholders] = self::executionTypes();

        /** @var array{executions: int, signed_in_actors: int, without_actor: int} */
        return Stored::rows($connection, "SELECT count(*) AS executions,
            count(DISTINCT NULLIF(user_id, '')) AS signed_in_actors,
            count(*) FILTER (WHERE NULLIF(user_id, '') IS NULL) AS without_actor
            FROM records WHERE type IN ({$placeholders}) AND {$window->condition()}", $types, $window)[0];
    }

    /**
     * Read the id of the execution of the window that finished last, or null when none of them finished.
     */
    protected static function latestExecution(SQLite3 $connection, Window $window): ?string
    {
        [$types, $placeholders] = self::executionTypes();

        $latest = Stored::rows($connection, "SELECT execution_id FROM records
            WHERE type IN ({$placeholders}) AND {$window->condition()} AND ended_at IS NOT NULL AND execution_id IS NOT NULL
            ORDER BY ended_at DESC, id DESC LIMIT 1", $types, $window);

        return $latest[0]['execution_id'] ?? null;
    }

    /**
     * Get the four execution types as bindings by name, and the placeholders that name them.
     *
     * @return array{array<string, string>, string}
     */
    protected static function executionTypes(): array
    {
        $types = [];

        foreach (Executions::TYPES as $position => $type) {
            $types["type{$position}"] = $type->value;
        }

        $placeholders = implode(', ', array_map(fn (string $name) => ":{$name}", array_keys($types)));

        return [$types, $placeholders];
    }

    /**
     * Get a part as a percentage of a whole, or null for a whole of nothing.
     */
    protected static function share(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(Ranking::PERCENT * $part / $whole, self::PERCENT_DECIMALS);
    }
}
