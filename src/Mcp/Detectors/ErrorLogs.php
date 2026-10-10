<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\LogLevel;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class ErrorLogs implements Thresholded, Ungrouped
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::ERROR_LOGS;
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
        return [RecordType::LOG];
    }

    /**
     * Judge the message shapes of the logs at error or worse that were written in the window, over the logs of every level.
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        $described = $this->threshold()->describe($threshold);
        $caveats = [__('firewatch::messages.detect_caveat_log_capture')];

        $examined = $this->examined($connection, $window);

        if ($examined === 0) {
            return Judgement::of($this->name(), $described, examined: 0, total: 0, findings: [], caveats: $caveats);
        }

        $shapes = $this->shapes($connection, $window, $threshold, $limit);
        $findings = array_map($this->finding(...), $shapes);

        return Judgement::of($this->name(), $described, examined: $examined, total: $shapes[0]['total'] ?? 0, findings: $findings, caveats: $caveats);
    }

    /**
     * Get the call that lists the lines at the level of a finding and worse, those that hold its fragment when it has one.
     *
     * @param  array<string, mixed>  $finding
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    public function occurrencesOf(array $finding): array
    {
        $arguments = [
            'type' => RecordType::LOG->value,
            'level' => $finding['evidence']['level'],
        ];

        $fragment = $finding['evidence']['fragment'];

        if ($fragment === null) {
            return [
                'tool' => 'occurrences',
                'arguments' => $arguments,
                'why' => __('firewatch::messages.detect_next_log_level'),
            ];
        }

        return [
            'tool' => 'occurrences',
            'arguments' => [
                ...$arguments,
                'matching' => $fragment,
            ],
            'why' => __('firewatch::messages.detect_next_log_lines'),
        ];
    }

    /**
     * Count the logs of every level that were written in the window.
     */
    protected function examined(SQLite3 $connection, Window $window): int
    {
        [$row] = Stored::rows($connection, "SELECT count(*) AS examined FROM logs WHERE {$window->condition()}", [], $window);

        return $row['examined'];
    }

    /**
     * Get the levels and shapes with at least the occurrences, worst first and at most the limit, each row with the total before the cut.
     *
     * @return list<array<string, mixed>>
     */
    protected function shapes(SQLite3 $connection, Window $window, int|float $occurrences, int $limit): array
    {
        $connection->createFunction('message_shape', fn (mixed $message) => $this->shape($message)->text, 1, SQLITE3_DETERMINISTIC);

        return Stored::rows($connection, "WITH errors AS MATERIALIZED (
            SELECT id, started_at, lower(level) AS level, message_shape(message) AS shape,
                NULLIF(execution_id, '') AS execution_id, NULLIF(user_id, '') AS user_id
            FROM logs WHERE {$window->condition()} AND lower(level) IN (SELECT value FROM json_each(:levels))
        ), placed AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY level, shape ORDER BY started_at DESC, id DESC) AS by_latest FROM errors
        ), shapes AS (
            SELECT level, shape, count(*) AS occurrences,
                max(CASE WHEN by_latest = 1 THEN id END) AS latest_id,
                max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
                min(started_at) AS first_seen, max(started_at) AS last_seen,
                count(DISTINCT user_id) AS actors,
                count(*) FILTER (WHERE user_id IS NULL) AS anonymous,
                count(*) FILTER (WHERE execution_id IN (SELECT execution_id FROM exceptions WHERE execution_id IS NOT NULL)) AS with_exception
            FROM placed GROUP BY level, shape HAVING count(*) >= :occurrences
        ), ranked AS (
            SELECT *, count(*) OVER () AS total FROM shapes
            ORDER BY occurrences DESC, last_seen DESC, shape ASC, level ASC LIMIT :limit
        )
        SELECT level, occurrences, latest_execution_id, first_seen, last_seen, actors, anonymous, with_exception, total,
            (SELECT message FROM logs WHERE logs.id = ranked.latest_id) AS message
        FROM ranked ORDER BY occurrences DESC, last_seen DESC, shape ASC, level ASC", [
            'levels' => json_encode(LogLevel::ERROR->andWorse(), JSON_THROW_ON_ERROR),
            'occurrences' => $occurrences,
            'limit' => $limit,
        ], $window);
    }

    /**
     * Get the shape of a stored message, which is the empty shape for one that is no text.
     */
    protected function shape(mixed $message): MessageShape
    {
        return MessageShape::of(is_string($message) ? $message : '');
    }

    /**
     * Get the finding of a level and shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        $shape = $this->shape($row['message']);

        return [
            'group' => null,
            'name' => $shape->text,
            'count' => $row['occurrences'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'level' => $row['level'],
                'message' => $row['message'],
                'shape' => $shape->text,
                'fragment' => $shape->fragment(),
                'occurrences' => $row['occurrences'],
                'in_executions_with_exception' => $row['with_exception'],
            ],
        ];
    }
}
