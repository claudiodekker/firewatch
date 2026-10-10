<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\ExceptionSection;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use SQLite3;

/**
 * @internal
 */
class ExceptionClusters implements Thresholded
{
    /**
     * The most units a finding lists.
     */
    protected const UNITS = 3;

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::EXCEPTION_CLUSTERS;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'occurrences', unit: 'occurrences', default: 1, minimum: 1);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::EXCEPTION, ...ExecutionType::records()];
    }

    /**
     * Judge the exception groups that occurred in the window, over the executions that started in it.
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $described = $this->threshold()->describe($threshold);

        $groups = $this->groups($connection, $window, $group);

        if ($group !== null && $groups === []) {
            return $this->withoutOccurrences($described);
        }

        $examined = $this->examined($connection, $window);
        $flagged = array_values(array_filter($groups, fn (array $row) => $row['occurrences'] >= $threshold));

        if ($flagged === []) {
            return Judgement::of($this->name(), $described, examined: $examined, total: 0, findings: []);
        }

        $shown = Judgement::worst($flagged, fn (array $a, array $b) => [$b['escaped'] > 0, $b['occurrences'], $b['last_seen']] <=> [$a['escaped'] > 0, $a['occurrences'], $a['last_seen']] ?: strcmp($a['group_hash'], $b['group_hash']), $limit);
        $hashes = array_column($shown, 'group_hash');
        $traces = $this->traces($connection, $window, $hashes);
        $units = $this->units($connection, $window, $hashes);
        $findings = array_map(fn (array $row) => $this->finding($row, $traces[$row['group_hash']] ?? null, $units[$row['group_hash']] ?? []), $shown);

        return Judgement::of($this->name(), $described, examined: $examined, total: count($flagged), findings: $findings);
    }

    /**
     * Get the judgement of a group that no exception in the window carries: the executions of the window say nothing of it.
     *
     * @param  array<string, mixed>  $described
     */
    protected function withoutOccurrences(array $described): Judgement
    {
        return Judgement::notEvaluated($this->name(), $described, Reason::NO_RECORDS);
    }

    /**
     * Get what the exceptions say of each group.
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(SQLite3 $connection, Window $window, ?string $group): array
    {
        $selected = Fragment::selecting($window, $group);

        return Stored::rows($connection, "WITH occurrences AS (
            SELECT id, execution_id, started_at, group_hash, user_id, class, message, file, line, handled, trace IS NULL AS fatal
            FROM exceptions WHERE {$selected->sql}
        ), placed AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY started_at DESC, id DESC) AS by_latest FROM occurrences
        )
        SELECT group_hash, count(*) AS occurrences,
            count(*) FILTER (WHERE handled = 0) AS escaped, count(*) FILTER (WHERE handled = 1) AS reported, max(fatal) AS fatal,
            max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
            max(CASE WHEN by_latest = 1 THEN class END) AS class,
            max(CASE WHEN by_latest = 1 THEN message END) AS message,
            max(CASE WHEN by_latest = 1 THEN file END) AS file,
            max(CASE WHEN by_latest = 1 THEN line END) AS line,
            min(started_at) AS first_seen, max(started_at) AS last_seen,
            count(DISTINCT NULLIF(user_id, '')) AS actors,
            count(*) FILTER (WHERE NULLIF(user_id, '') IS NULL) AS anonymous
        FROM placed GROUP BY group_hash", $selected->bindings, $window);
    }

    /**
     * Count the executions that started in the window after a clear or a prune last removed exceptions, whatever their group.
     */
    protected function examined(SQLite3 $connection, Window $window): int
    {
        $meta = Markers::read($connection);
        $executions = Executions::table($window, group: null);
        $bindings = [
            ...$executions->bindings,
            'from' => History::removedThrough($meta, [RecordType::EXCEPTION]),
        ];

        [$row] = Stored::rows($connection, $executions->sql.' SELECT count(*) AS examined FROM executions WHERE :from IS NULL OR started_at >= :from', $bindings, $window);

        return $row['examined'];
    }

    /**
     * Get the stored trace of the latest occurrence that has one, by group hash.
     *
     * @param  list<string>  $hashes
     * @return array<string, mixed>
     */
    protected function traces(SQLite3 $connection, Window $window, array $hashes): array
    {
        $rows = Stored::rows($connection, "SELECT shown.value AS group_hash, (
            SELECT trace FROM exceptions WHERE group_hash = shown.value AND {$window->condition()}
            ORDER BY trace IS NULL, started_at DESC, id DESC LIMIT 1
        ) AS trace FROM json_each(:hashes) AS shown", ['hashes' => json_encode($hashes, JSON_THROW_ON_ERROR)], $window);

        return array_column($rows, 'trace', 'group_hash');
    }

    /**
     * Get where the occurrences of each group ran, the units with the most occurrences first, by group hash.
     *
     * @param  list<string>  $hashes
     * @return array<string, list<array{source: mixed, label: mixed, occurrences: int}>>
     */
    protected function units(SQLite3 $connection, Window $window, array $hashes): array
    {
        $rows = Stored::rows($connection, Executions::labels().", occurrences AS (
            SELECT execution_id, execution_source AS source, group_hash
            FROM exceptions WHERE {$window->condition()} AND group_hash IN (SELECT value FROM json_each(:hashes))
        ), labelled AS (
            SELECT execution_id, COALESCE(label, '') AS label, ROW_NUMBER() OVER (PARTITION BY execution_id ORDER BY started_at DESC, id DESC) AS position
            FROM (SELECT DISTINCT execution_id FROM occurrences WHERE execution_id IS NOT NULL) AS carried JOIN labels USING (execution_id)
        ), units AS (
            SELECT occurrences.group_hash, occurrences.source, labelled.label, count(*) AS occurrences,
                ROW_NUMBER() OVER (PARTITION BY occurrences.group_hash ORDER BY count(*) DESC, occurrences.source, labelled.label) AS position
            FROM occurrences LEFT JOIN labelled ON labelled.execution_id = occurrences.execution_id AND labelled.position = 1
            GROUP BY occurrences.group_hash, occurrences.source, labelled.label
        )
        SELECT group_hash, source, label, occurrences FROM units WHERE position <= ".self::UNITS.' ORDER BY group_hash, position', ['hashes' => json_encode($hashes, JSON_THROW_ON_ERROR)], $window);

        $units = [];

        foreach ($rows as $row) {
            $units[$row['group_hash']][] = $this->unit($row);
        }

        return $units;
    }

    /**
     * Get a unit as a finding lists it, without a label when the store holds no execution of its occurrences.
     *
     * @param  array<string, mixed>  $row
     * @return array{source: mixed, label: mixed, occurrences: int}
     */
    protected function unit(array $row): array
    {
        return [
            'source' => $row['source'],
            'label' => Executions::label($row['label']),
            'occurrences' => $row['occurrences'],
        ];
    }

    /**
     * Get the finding of an exception group.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{source: mixed, label: mixed, occurrences: int}>  $units
     * @return array<string, mixed>
     */
    protected function finding(array $row, mixed $trace, array $units): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => is_string($row['class']) ? $row['class'] : '',
            'count' => $row['occurrences'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'class' => $row['class'],
                'message' => $row['message'],
                'file' => $row['file'],
                'line' => $row['line'],
                'app_frame' => ExceptionSection::applicationFrame($trace),
                'escaped' => $row['escaped'],
                'reported' => $row['reported'],
                'fatal' => $row['fatal'] === 1,
                'units' => $units,
            ],
        ];
    }
}
