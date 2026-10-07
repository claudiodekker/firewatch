<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class Cache implements Detector
{
    /**
     * The fewest reads of a key whose hit rate is judged, as one miss is a cold fill.
     */
    protected const READS_FLOOR = 3;

    /**
     * The percent a share is of its whole.
     */
    protected const PERCENT = 100;

    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * The reason of a key whose hit rate is below the threshold.
     */
    protected const LOW_HIT_RATE = 'low_hit_rate';

    /**
     * The reason of a key with a write that failed.
     */
    protected const WRITE_FAILING = 'write_failing';

    /**
     * The reason of a key with a delete that failed.
     */
    protected const DELETE_FAILING = 'delete_failing';

    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName
    {
        return DetectorName::CACHE;
    }

    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold
    {
        return new Threshold(name: 'percent', unit: 'percent', default: 50, minimum: 1, maximum: self::PERCENT, whole: false);
    }

    /**
     * Get the record types the detector examines.
     *
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::CACHE_EVENT];
    }

    /**
     * Judge the keys of the cache events that started in the window.
     */
    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $percent = $threshold ?? $this->threshold()->default;
        $described = $this->threshold()->describe($threshold);
        $caveats = [__('firewatch::messages.detect_caveat_cache_keys')];

        $stores = $this->stores($connection, $window, $group);
        $examined = array_sum(array_column($stores, 'events'));
        $saw = ['activity' => $this->activity($stores)];

        if ($examined === 0) {
            return Judgement::of($this->name(), $described, examined: 0, total: 0, findings: [], saw: $saw, caveats: $caveats);
        }

        $keys = $this->keys($connection, $window, $percent, $group, $limit);
        $findings = array_map($this->finding(...), $keys);

        return Judgement::of($this->name(), $described, examined: $examined, total: $keys[0]['total'] ?? 0, findings: $findings, saw: $saw, caveats: $caveats);
    }

    /**
     * Get what the cache events that started in the window say of each store, by store name, with every one counted in `events`.
     *
     * @return list<array<string, mixed>>
     */
    protected function stores(SQLite3 $connection, Window $window, ?string $group): array
    {
        return Stored::rows($connection, "SELECT store, count(*) AS events,
                count(*) FILTER (WHERE event = 'hit') AS hits,
                count(*) FILTER (WHERE event = 'miss') AS misses,
                count(*) FILTER (WHERE event = 'write') AS writes,
                count(*) FILTER (WHERE event = 'write-failure') AS write_failures,
                count(*) FILTER (WHERE event = 'delete') AS deletes,
                count(*) FILTER (WHERE event = 'delete-failure') AS delete_failures
            FROM cache_events WHERE {$this->selected($window)}
            GROUP BY store ORDER BY store", ['group' => $group ?? ''], $window);
    }

    /**
     * Get the keys that qualify, worst first and at most the limit, each row with the total before the cut.
     *
     * @return list<array<string, mixed>>
     */
    protected function keys(SQLite3 $connection, Window $window, int|float $percent, ?string $group, int $limit): array
    {
        $whole = self::PERCENT;
        $worstFirst = 'failures DESC, hit_rate IS NULL, hit_rate ASC, group_hash ASC';

        return Stored::rows($connection, "WITH events AS (
            SELECT id, started_at, group_hash, store, \"key\", event,
                NULLIF(execution_id, '') AS execution_id, NULLIF(user_id, '') AS user_id,
                event IN ('hit', 'miss') AS read,
                event IN ('write-failure', 'delete-failure') AS failed
            FROM cache_events WHERE {$this->selected($window)}
        ), placed AS (
            SELECT *, count(*) FILTER (WHERE failed) OVER (PARTITION BY group_hash) > 0 AS failing FROM events
        ), judged AS (
            SELECT *, CASE WHEN failing THEN failed ELSE event = 'miss' END AS qualifying FROM placed
        ), ranked AS (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY group_hash ORDER BY qualifying DESC, started_at DESC, id DESC) AS by_latest FROM judged
        ), keys AS (
            SELECT group_hash, max(CASE WHEN by_latest = 1 THEN store END) AS store, max(CASE WHEN by_latest = 1 THEN \"key\" END) AS \"key\",
                count(*) FILTER (WHERE event = 'hit') AS hits,
                count(*) FILTER (WHERE event = 'miss') AS misses,
                count(*) FILTER (WHERE event = 'write') AS writes,
                count(*) FILTER (WHERE event = 'write-failure') AS write_failures,
                count(*) FILTER (WHERE event = 'delete') AS deletes,
                count(*) FILTER (WHERE event = 'delete-failure') AS delete_failures,
                count(*) FILTER (WHERE read) AS reads,
                count(*) FILTER (WHERE failed) AS failures,
                CASE WHEN count(*) FILTER (WHERE read) > 0
                    THEN CAST(count(*) FILTER (WHERE event = 'hit') AS REAL) / count(*) FILTER (WHERE read) END AS hit_rate,
                max(CASE WHEN by_latest = 1 THEN execution_id END) AS latest_execution_id,
                min(started_at) FILTER (WHERE qualifying) AS first_seen, max(started_at) FILTER (WHERE qualifying) AS last_seen,
                count(DISTINCT user_id) FILTER (WHERE qualifying) AS actors,
                count(*) FILTER (WHERE qualifying AND user_id IS NULL) AS anonymous
            FROM ranked GROUP BY group_hash
        ), weighed AS (
            SELECT *, reads >= :floor AND {$whole} * hits < :percent * reads AS low_hit_rate FROM keys
        ), cut AS (
            SELECT *, count(*) OVER () AS total FROM weighed
            WHERE failures > 0 OR low_hit_rate
            ORDER BY {$worstFirst} LIMIT :limit
        )
        SELECT * FROM cut ORDER BY {$worstFirst}", [
            'floor' => self::READS_FLOOR,
            'percent' => $percent,
            'group' => $group ?? '',
            'limit' => $limit,
        ], $window);
    }

    /**
     * Get the SQL condition of the cache events that started in the window, of one group when the call names it.
     */
    protected function selected(Window $window): string
    {
        return $window->condition().' AND (:group = \'\' OR group_hash = :group)';
    }

    /**
     * Get the activity: the counters of each store, and of all of them together.
     *
     * @param  list<array<string, mixed>>  $stores
     * @return array{stores: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function activity(array $stores): array
    {
        $total = array_fill_keys(['hits', 'misses', 'writes', 'write_failures', 'deletes', 'delete_failures'], 0);

        foreach ($stores as $store) {
            foreach (array_keys($total) as $counter) {
                $total[$counter] += $store[$counter];
            }
        }

        return [
            'stores' => array_map($this->store(...), $stores),
            'total' => $this->counters($total),
        ];
    }

    /**
     * Get a store as the activity lists it, under the name the wire gave it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function store(array $row): array
    {
        return [
            'store' => $row['store'],
            ...$this->counters($row),
        ];
    }

    /**
     * Get the counters of a row of cache events, with no hit rate for a row without a read.
     *
     * @param  array<string, mixed>  $row
     * @return array{hits: int, misses: int, writes: int, write_failures: int, deletes: int, delete_failures: int, hit_rate_pct: float|null}
     */
    protected function counters(array $row): array
    {
        $reads = $row['hits'] + $row['misses'];

        return [
            'hits' => $row['hits'],
            'misses' => $row['misses'],
            'writes' => $row['writes'],
            'write_failures' => $row['write_failures'],
            'deletes' => $row['deletes'],
            'delete_failures' => $row['delete_failures'],
            'hit_rate_pct' => $reads > 0 ? round(self::PERCENT * $row['hits'] / $reads, self::PERCENT_DECIMALS) : null,
        ];
    }

    /**
     * Get the finding of a key, counted by its failures when it has any and by its reads when not.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function finding(array $row): array
    {
        return [
            'group' => $row['group_hash'],
            'name' => is_string($row['key']) ? $row['key'] : '',
            'count' => $row['failures'] > 0 ? $row['failures'] : $row['reads'],
            'first_seen_at' => $row['first_seen'],
            'last_seen_at' => $row['last_seen'],
            'latest_execution_id' => $row['latest_execution_id'],
            'reaches' => [
                'signed_in_actors' => $row['actors'],
                'without_actor' => $row['anonymous'],
            ],
            'evidence' => [
                'reasons' => $this->reasons($row),
                'store' => $row['store'],
                'key' => $row['key'],
                ...$this->counters($row),
            ],
        ];
    }

    /**
     * Get the reasons a key qualifies by, in their fixed order.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    protected function reasons(array $row): array
    {
        return array_keys(array_filter([
            self::LOW_HIT_RATE => $row['low_hit_rate'] === 1,
            self::WRITE_FAILING => $row['write_failures'] > 0,
            self::DELETE_FAILING => $row['delete_failures'] > 0,
        ]));
    }
}
