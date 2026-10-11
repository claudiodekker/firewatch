<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\ExecutionType;
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
     * The most deploys the slowest list shows as blocks.
     */
    protected const SLOWEST_DEPLOYS = 5;

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
     * @param  array{blocks: list<array{deploy: string|null, groups: list<array{type: string, group: string, label: string, occurrences: int, total_ms: float|null, avg_ms: float|null}>}>, matched: int}|null  $deploys  the slowest list per deploy, oldest first, or null when it was not asked for
     */
    protected function __construct(
        public readonly array $errorRate,
        public readonly array $slowest,
        public readonly int $records,
        public readonly array $byType,
        public readonly int $userDirectory,
        public readonly array $actors,
        public readonly ?string $latestExecution,
        public readonly ?array $deploys = null,
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
     * Read the sections of the window, with the slowest list broken down per deploy.
     */
    public static function readByDeploy(SQLite3 $connection, Window $window): self
    {
        $sections = self::read($connection, $window);

        return new self(
            errorRate: $sections->errorRate,
            slowest: $sections->slowest,
            records: $sections->records,
            byType: $sections->byType,
            userDirectory: $sections->userDirectory,
            actors: $sections->actors,
            latestExecution: $sections->latestExecution,
            deploys: self::deployBlocks($connection, $window),
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
            'slowest_by_total_time' => $this->deploys === null ? $this->slowest : array_map($this->block(...), $this->deploys['blocks']),
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
     * Get the truncated entry for the deploys the slowest list left out, or null when it shows them all.
     *
     * @return array{section: string, shown: int, matched: int, reason: string, how: string}|null
     */
    public function truncation(): ?array
    {
        if ($this->deploys === null || $this->deploys['matched'] <= count($this->deploys['blocks'])) {
            return null;
        }

        return [
            'section' => 'slowest_by_total_time',
            'shown' => count($this->deploys['blocks']),
            'matched' => $this->deploys['matched'],
            'reason' => TruncationReason::LIMIT->value,
            'how' => __('firewatch::messages.overview_deploys_truncated_how'),
        ];
    }

    /**
     * Get the type of the slowest group of the newest deploy shown, with the two newest deploys shown by name, or null when fewer than two are.
     *
     * @return array{type: RecordType, before: string, after: string}|null
     */
    public function newestDeploys(): ?array
    {
        $named = array_values(array_filter($this->deploys['blocks'] ?? [], fn (array $block) => $block['deploy'] !== null));

        if (count($named) < 2) {
            return null;
        }

        [$before, $after] = array_slice($named, -2);

        return [
            'type' => RecordType::from($after['groups'][0]['type']),
            'before' => $before['deploy'],
            'after' => $after['deploy'],
        ];
    }

    /**
     * Get a block of the slowest list as it is answered: the deploy, named as a ranking names the records of none, and its groups.
     *
     * @param  array{deploy: string|null, groups: list<array<string, mixed>>}  $block
     * @return array{deploy: string, groups: list<array<string, mixed>>}
     */
    protected function block(array $block): array
    {
        return [
            'deploy' => $block['deploy'] ?? __('firewatch::messages.rank_no_deploy'),
            'groups' => $block['groups'],
        ];
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
            'server_error_pct' => Percentile::percent($counts['server_errors'], $counts['with_status']),
            'client_errors' => $counts['client_errors'],
            'client_error_pct' => Percentile::percent($counts['client_errors'], $counts['with_status']),
        ];
    }

    /**
     * Read the groups of the window with the largest total duration: at most three of one type and ten in all, worst first.
     *
     * @return list<array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float}>
     */
    protected static function leaders(SQLite3 $connection, Window $window): array
    {
        $types = self::timedTypes();
        [$timed, $bindings] = self::timed($types, $window);

        $rows = Stored::rows($connection, "WITH timed AS ({$timed}),
            totals AS (SELECT position, group_hash, count(*) AS occurrences, count(d) AS timed_records, sum(d) AS total FROM timed GROUP BY position, group_hash),
            placed AS (SELECT *, ROW_NUMBER() OVER (PARTITION BY position ORDER BY total DESC, occurrences DESC, group_hash) AS place FROM totals WHERE timed_records > 0)
            SELECT position, group_hash, occurrences, total FROM placed WHERE place <= :per_type", [
            ...$bindings,
            'per_type' => self::SLOWEST_PER_TYPE,
        ], $window);

        return self::top(array_map(fn (array $row) => self::leader($types, $row), $rows));
    }

    /**
     * Read the groups of each of the most recent deploys of the window with the largest total duration, as the blocks of the slowest list, oldest deploy first.
     *
     * @return array{blocks: list<array{deploy: string|null, groups: list<array{type: string, group: string, label: string, occurrences: int, total_ms: float|null, avg_ms: float|null}>}>, matched: int}
     */
    protected static function deployBlocks(SQLite3 $connection, Window $window): array
    {
        $types = self::timedTypes();
        [$timed, $bindings] = self::timed($types, $window, "NULLIF(deploy, '') AS deploy, started_at, ");

        $rows = Stored::rows($connection, "WITH timed AS ({$timed}),
            totals AS (SELECT deploy, position, group_hash, count(*) AS occurrences, count(d) AS timed_records, sum(d) AS total FROM timed GROUP BY deploy, position, group_hash),
            placed AS (SELECT *, ROW_NUMBER() OVER (PARTITION BY deploy, position ORDER BY total DESC, occurrences DESC, group_hash) AS place FROM totals WHERE timed_records > 0),
            seen AS (SELECT deploy, min(started_at) AS first_at, max(started_at) AS last_at FROM timed WHERE d IS NOT NULL GROUP BY deploy),
            recent AS (SELECT *, ROW_NUMBER() OVER (ORDER BY last_at DESC, deploy) AS recency, count(*) OVER () AS matched FROM seen)
            SELECT placed.deploy, first_at, matched, position, group_hash, occurrences, timed_records, total
            FROM placed JOIN recent ON recent.deploy IS placed.deploy
            WHERE place <= :per_type AND recency <= :deploys", [
            ...$bindings,
            'per_type' => self::SLOWEST_PER_TYPE,
            'deploys' => self::SLOWEST_DEPLOYS,
        ], $window);

        $seen = [];

        foreach ($rows as $row) {
            $key = $row['deploy'] ?? '';
            $seen[$key] ??= [
                'deploy' => $row['deploy'],
                'first' => $row['first_at'],
                'leaders' => [],
            ];

            $seen[$key]['leaders'][] = [
                ...self::leader($types, $row),
                'timed' => $row['timed_records'],
            ];
        }

        usort($seen, fn (array $a, array $b) => ($a['first'] <=> $b['first']) ?: strcmp($a['deploy'] ?? '', $b['deploy'] ?? ''));

        $blocks = array_map(fn (array $block) => [
            'deploy' => $block['deploy'],
            'groups' => self::deployGroups($connection, $window, self::top($block['leaders'])),
        ], $seen);

        return [
            'blocks' => $blocks,
            'matched' => $rows[0]['matched'] ?? 0,
        ];
    }

    /**
     * Get the rows of one deploy's block: the labelled leaders, each with the mean of its timed records.
     *
     * @param  list<array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float, timed: int}>  $leaders
     * @return list<array{type: string, group: string, label: string, occurrences: int, total_ms: float|null, avg_ms: float|null}>
     */
    protected static function deployGroups(SQLite3 $connection, Window $window, array $leaders): array
    {
        return array_map(fn (array $row, array $leader) => [
            ...$row,
            'avg_ms' => Stored::milliseconds($leader['value'] / $leader['timed']),
        ], self::labelled($connection, $window, $leaders), $leaders);
    }

    /**
     * Get the types whose records have a duration to total.
     *
     * @return list<RecordType>
     */
    protected static function timedTypes(): array
    {
        return array_values(array_filter(Measure::types(), Measure::TOTAL_DURATION->fits(...)));
    }

    /**
     * Get the union of the duration of every record of the types in the window, as the SQL of the table `timed`, with the bindings of its positions.
     *
     * @param  list<RecordType>  $types
     * @param  string  $columns  the columns to select beside the position, the group and the duration, each followed by a comma
     * @return array{string, array<string, int>}
     */
    protected static function timed(array $types, Window $window, string $columns = ''): array
    {
        $bindings = [];
        $branches = [];

        foreach ($types as $position => $type) {
            $bindings["position{$position}"] = $position;
            $branches[] = "SELECT :position{$position} AS position, {$columns}group_hash, ".Stored::duration($type)." AS d FROM {$type->view()} WHERE group_hash IS NOT NULL AND {$window->condition()}";
        }

        return [implode(' UNION ALL ', $branches), $bindings];
    }

    /**
     * Get a row of totals as a leader.
     *
     * @param  list<RecordType>  $types
     * @param  array<string, mixed>  $row
     * @return array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float}
     */
    protected static function leader(array $types, array $row): array
    {
        return [
            'type' => $types[$row['position']],
            'position' => $row['position'],
            'hash' => $row['group_hash'],
            'occurrences' => $row['occurrences'],
            'value' => $row['total'],
        ];
    }

    /**
     * Order leaders worst first and keep the ten worst.
     *
     * @template TLeader of array{type: RecordType, position: int, hash: string, occurrences: int, value: int|float}
     *
     * @param  list<TLeader>  $leaders
     * @return list<TLeader>
     */
    protected static function top(array $leaders): array
    {
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
            $field = $leader['type']->labelColumn();
            $branches[] = "SELECT :row{$row} AS row, (SELECT {$field} FROM {$leader['type']->view()} WHERE group_hash = :group{$row} AND {$window->condition()} ORDER BY started_at DESC, id DESC LIMIT 1) AS label";
        }

        $latest = Stored::rows($connection, implode(' UNION ALL ', $branches), $bindings, $window);
        $labels = array_column($latest, 'label', 'row');

        return array_map(fn (array $leader, int $row) => [
            'type' => $leader['type']->value,
            'group' => $leader['hash'],
            'label' => $leader['type']->groupLabel($labels[$row]),
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

        foreach (ExecutionType::records() as $position => $type) {
            $types["type{$position}"] = $type->value;
        }

        $placeholders = implode(', ', array_map(fn (string $name) => ":{$name}", array_keys($types)));

        return [$types, $placeholders];
    }
}
