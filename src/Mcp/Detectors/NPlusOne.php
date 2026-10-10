<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class NPlusOne implements Detector
{
    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * The most call sites a finding lists.
     */
    protected const CALL_SITES = 3;

    /**
     * The characters a read's SQL may begin with before its first keyword.
     */
    protected const LEADING_WHITESPACE = 'char(32, 9, 10, 13)';

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::N_PLUS_ONE;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'runs', unit: 'runs', default: 3, minimum: 2);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::QUERY, ...ExecutionType::records()];
    }

    /**
     * Judge the executions that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $this->refuseQueryGroup($connection, $group);

        $meta = Markers::read($connection);
        $bindings = [
            'runs' => $threshold,
            'group' => $group ?? '',
            'from' => History::removedThrough($meta, [RecordType::QUERY]),
        ];
        $executions = Executions::table($window);
        $population = $this->population($connection, $window, $executions, $bindings);
        $caveats = $this->caveats($population['incomplete']);
        $described = $this->threshold()->describe($threshold);

        if ($population['examined'] === 0) {
            return Judgement::notEvaluated($this->name(), $described, Reason::NO_RECORDS, caveats: $caveats);
        }

        $ranked = $this->ranked($executions);
        $groups = Stored::rows($connection, "{$ranked} SELECT eg, qg, max(runs) AS worst_runs, count(*) AS affected, sum(runs) AS total_runs, total(micros) AS total_micros,
            min(started_at) AS first_seen, max(started_at) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) AS actors, count(*) FILTER (WHERE NULLIF(user_id, '') IS NULL) AS anonymous
            FROM ranked GROUP BY eg, qg", $bindings, $window);

        if ($groups === []) {
            return Judgement::of($this->name(), $described, examined: $population['examined'], total: 0, findings: [], caveats: $caveats);
        }

        usort($groups, fn (array $a, array $b) => [$b['worst_runs'], $b['total_micros'], $a['eg'], $a['qg']] <=> [$a['worst_runs'], $a['total_micros'], $b['eg'], $b['qg']]);

        $shown = array_slice($groups, 0, $limit);
        $details = $this->details($connection, $window, $ranked, $bindings);
        $findings = array_map(fn (array $row) => $this->finding($row, $details), $shown);

        return Judgement::of($this->name(), $described, examined: $population['examined'], total: count($groups), findings: $findings, caveats: $caveats);
    }

    /**
     * Refuse the hash of a query group.
     */
    protected function refuseQueryGroup(SQLite3 $connection, ?string $group): void
    {
        if ($group === null || Stored::rows($connection, 'SELECT 1 FROM queries WHERE group_hash = :group LIMIT 1', ['group' => $group]) === []) {
            return;
        }

        throw Refusal::invalid(
            argument: 'group',
            expected: 'the group id of a route, command, job or scheduled task',
            value: json_encode($group, JSON_THROW_ON_ERROR),
            accepted: 'the group id of an execution; a query group is a different question: rank the queries with `rank`',
            example: 'detect(shape: "n-plus-one", group: "<group id of an execution>")',
        );
    }

    /**
     * Get what the window holds.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return array{examined: int, incomplete: int}
     */
    protected function population(SQLite3 $connection, Window $window, string $executions, array $bindings): array
    {
        $row = Stored::rows($connection, "{$executions} SELECT count(*) FILTER (WHERE captured > 0) AS examined, count(*) FILTER (WHERE captured > 0 AND counted > captured) AS incomplete
            FROM (SELECT counted,
                CASE WHEN :from IS NULL OR started_at >= :from THEN (SELECT count(*) FROM queries WHERE queries.execution_id = executions.execution_id) ELSE 0 END AS captured
                FROM executions)", $bindings, $window)[0];

        return [
            'examined' => $row['examined'],
            'incomplete' => $row['incomplete'],
        ];
    }

    /**
     * Get the SQL of the runs that qualify.
     */
    protected function ranked(string $executions): string
    {
        $trimmed = 'lower(ltrim(queries.sql, '.self::LEADING_WHITESPACE.'))';

        return "{$executions}, runs AS (
            SELECT executions.execution_id, executions.group_hash AS eg, queries.group_hash AS qg, executions.id, executions.started_at, executions.user_id,
                executions.duration, executions.source, executions.label, count(*) AS runs, total(queries.duration) AS micros
            FROM executions JOIN queries ON queries.execution_id = executions.execution_id
            WHERE (:from IS NULL OR executions.started_at >= :from) AND ({$trimmed} LIKE 'select%' OR {$trimmed} LIKE 'with%')
            GROUP BY executions.execution_id, queries.group_hash HAVING count(*) >= :runs
        ), ranked AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY eg, qg ORDER BY runs DESC, micros DESC, started_at DESC, id DESC) AS worst_position,
                ROW_NUMBER() OVER (PARTITION BY eg, qg ORDER BY started_at DESC, id DESC) AS latest_position
            FROM runs
        )";
    }

    /**
     * Get the details of the findings, by the pair of groups.
     *
     * @param  array<string, int|float|string|null>  $bindings
     * @return array{executions: array<string, array<string, mixed>>, sites: array<string, list<array<string, mixed>>>, queries: array<string, array<string, mixed>>}
     */
    protected function details(SQLite3 $connection, Window $window, string $ranked, array $bindings): array
    {
        $executions = [];
        $sites = [];
        $queries = [];

        foreach (Stored::rows($connection, "{$ranked} SELECT eg, qg, execution_id, runs, micros, duration, source, label, worst_position, latest_position FROM ranked WHERE worst_position = 1 OR latest_position = 1", $bindings, $window) as $row) {
            $executions[$this->key($row)][$row['worst_position'] === 1 ? 'worst' : 'latest'] = $row;

            if ($row['worst_position'] === 1 && $row['latest_position'] === 1) {
                $executions[$this->key($row)]['latest'] = $row;
            }
        }

        foreach (Stored::rows($connection, "{$ranked} SELECT eg, qg, file, line, runs FROM (
            SELECT ranked.eg, ranked.qg, queries.file, queries.line, count(*) AS runs,
                ROW_NUMBER() OVER (PARTITION BY ranked.eg, ranked.qg ORDER BY count(*) DESC, queries.file, queries.line) AS position
            FROM ranked JOIN queries ON queries.execution_id = ranked.execution_id AND queries.group_hash = ranked.qg
            GROUP BY ranked.eg, ranked.qg, queries.file, queries.line) WHERE position <= ".self::CALL_SITES, $bindings, $window) as $row) {
            $sites[$this->key($row)][] = [
                'file' => $row['file'],
                'line' => $row['line'],
                'runs' => $row['runs'],
            ];
        }

        foreach (Stored::rows($connection, "{$ranked} SELECT ranked.eg, ranked.qg, count(DISTINCT queries.bindings) AS distinct_bindings, count(*) FILTER (WHERE queries.bindings IS NULL) AS unpaired, min(queries.sql) AS sql
            FROM ranked JOIN queries ON queries.execution_id = ranked.execution_id AND queries.group_hash = ranked.qg
            WHERE ranked.worst_position = 1 GROUP BY ranked.eg, ranked.qg", $bindings, $window) as $row) {
            $queries[$this->key($row)] = $row;
        }

        return [
            'executions' => $executions,
            'sites' => $sites,
            'queries' => $queries,
        ];
    }

    /**
     * Get the finding of one execution group and query group.
     *
     * @param  array<string, mixed>  $row
     * @param  array{executions: array<string, array<string, mixed>>, sites: array<string, list<array<string, mixed>>>, queries: array<string, array<string, mixed>>}  $details
     * @return array<string, mixed>
     */
    protected function finding(array $row, array $details): array
    {
        $key = $this->key($row);
        $worst = $details['executions'][$key]['worst'];
        $latest = $details['executions'][$key]['latest'];
        $query = $details['queries'][$key];
        $label = Stored::blank($worst['label']) ?? __('firewatch::messages.rank_no_route');
        $share = is_numeric($worst['duration']) && $worst['duration'] > 0 ? round(100 * $worst['micros'] / $worst['duration'], self::PERCENT_DECIMALS) : null;

        return [
            'group' => $row['eg'],
            'name' => "{$label}: {$query['sql']}",
            'count' => $row['worst_runs'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $latest['execution_id'],
            'worst_execution_id' => $worst['execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'worst_runs' => $row['worst_runs'],
                'executions_affected' => $row['affected'],
                'total_runs' => $row['total_runs'],
                'total_ms' => Stored::milliseconds($row['total_micros']),
                'worst_share_pct' => $share,
                'distinct_bindings' => $query['unpaired'] > 0 ? null : $query['distinct_bindings'],
                'unit' => [
                    'source' => $worst['source'],
                    'label' => $label,
                ],
                'call_sites' => $details['sites'][$key],
            ],
        ];
    }

    /**
     * Get the key of the pair of an execution group and a query group.
     *
     * @param  array<string, mixed>  $row
     */
    protected function key(array $row): string
    {
        return "{$row['eg']}|{$row['qg']}";
    }

    /**
     * Get the caveats of the answer.
     *
     * @return list<string>
     */
    protected function caveats(int $incomplete): array
    {
        return array_values(array_filter([
            __('firewatch::messages.detect_caveat_reads'),
            $incomplete > 0 ? trans_choice('firewatch::messages.detect_caveat_incomplete', $incomplete, ['count' => $incomplete]) : null,
        ]));
    }
}
