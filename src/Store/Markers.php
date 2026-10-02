<?php

namespace ClaudioDekker\Firewatch\Store;

use Carbon\CarbonInterface;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

/**
 * @internal
 */
class Markers
{
    /**
     * The key of the instant the store was created.
     */
    protected const CREATED_AT = 'created_at';

    /**
     * The key of the instant the store last replaced another.
     */
    protected const REBUILT_AT = 'rebuilt_at';

    /**
     * The key of why the store last replaced another.
     */
    protected const REBUILT_WHY = 'rebuilt_why';

    /**
     * The key of the instant records were last pruned through.
     */
    protected const PRUNED_THROUGH = 'pruned_through';

    /**
     * The key of the reason of the pass that advanced the pruned-through instant.
     */
    protected const PRUNED_BY = 'pruned_by';

    /**
     * The key of the instant of the last clear of every type.
     */
    protected const CLEARED_AT = 'cleared_at';

    /**
     * The key of the JSON object of the last clear instant of each type.
     */
    protected const CLEARED_TYPES = 'cleared_types';

    /**
     * The key of the instant the last prune pass was claimed.
     */
    protected const PRUNE_CLAIMED_AT = 'prune_claimed_at';

    /**
     * The key of the Nightwatch release the latest batch came from.
     */
    protected const NIGHTWATCH_VERSION = 'nightwatch_version';

    /**
     * The key of whether that release is on the verified line.
     */
    protected const NIGHTWATCH_VERIFIED = 'nightwatch_verified';

    /**
     * The reason a prune is assumed to have when the store names none.
     */
    protected const DEFAULT_PRUNED_REASON = 'age';

    /**
     * Create a new markers instance.
     *
     * @param  string|null  $prunedReason  age, cap or size
     * @param  array<string, float>  $clearedTypes  the instant of the last clear of each record type, by its value
     */
    public function __construct(
        public readonly ?float $createdAt = null,
        public readonly ?float $rebuiltAt = null,
        public readonly ?string $rebuiltWhy = null,
        public readonly ?float $prunedThrough = null,
        public readonly ?string $prunedReason = null,
        public readonly ?float $clearedAt = null,
        public readonly array $clearedTypes = [],
        public readonly ?float $pruneClaimedAt = null,
        public readonly ?string $nightwatchVersion = null,
        public readonly ?bool $nightwatchVerified = null,
    ) {
        //
    }

    /**
     * Read the markers of the store, in the snapshot of the connection.
     */
    public static function read(SQLite3 $connection): self
    {
        /** @var SQLite3Result $result */
        $result = $connection->query('SELECT key, value FROM meta');
        $meta = [];

        while (is_array($row = $result->fetchArray(SQLITE3_NUM))) {
            $meta[(string) $row[0]] = (string) $row[1];
        }

        $prunedThrough = self::instant($meta[self::PRUNED_THROUGH] ?? null);

        return new self(
            createdAt: self::instant($meta[self::CREATED_AT] ?? null),
            rebuiltAt: self::instant($meta[self::REBUILT_AT] ?? null),
            rebuiltWhy: $meta[self::REBUILT_WHY] ?? null,
            prunedThrough: $prunedThrough,
            prunedReason: $prunedThrough === null ? null : $meta[self::PRUNED_BY] ?? self::DEFAULT_PRUNED_REASON,
            clearedAt: self::instant($meta[self::CLEARED_AT] ?? null),
            clearedTypes: self::types($meta[self::CLEARED_TYPES] ?? null),
            pruneClaimedAt: self::instant($meta[self::PRUNE_CLAIMED_AT] ?? null),
            nightwatchVersion: $meta[self::NIGHTWATCH_VERSION] ?? null,
            nightwatchVerified: match ($meta[self::NIGHTWATCH_VERIFIED] ?? null) {
                '1' => true,
                '0' => false,
                default => null,
            },
        );
    }

    /**
     * Get the instant a type was last cleared, or null when it never was alone.
     */
    public function clearedAtOf(RecordType $type): ?float
    {
        return $this->clearedTypes[$type->value] ?? null;
    }

    /**
     * Record when a new store was created.
     */
    public static function markCreated(SQLite3 $connection, CarbonInterface $at): void
    {
        self::put($connection, self::CREATED_AT, self::text($at));
    }

    /**
     * Record when and why a new store replaced another.
     */
    public static function markRebuilt(SQLite3 $connection, CarbonInterface $at, string $why): void
    {
        self::put($connection, self::REBUILT_AT, self::text($at));
        self::put($connection, self::REBUILT_WHY, $why);
    }

    /**
     * Record that history was removed through an instant, with the reason of the pass that advanced it, unless it was already removed through a later one.
     */
    public static function advancePrunedThrough(SQLite3 $connection, float $through, string $reason): void
    {
        if (! self::moveForward($connection, self::PRUNED_THROUGH, $through)) {
            return;
        }

        self::put($connection, self::PRUNED_BY, $reason);
    }

    /**
     * Record that every type was cleared at an instant, unless it was already cleared later.
     */
    public static function markCleared(SQLite3 $connection, float $at): void
    {
        self::moveForward($connection, self::CLEARED_AT, $at);
    }

    /**
     * Record that one type was cleared at an instant, unless that type was already cleared later.
     */
    public static function markTypeCleared(SQLite3 $connection, RecordType $type, float $at): void
    {
        $cleared = self::types(self::get($connection, self::CLEARED_TYPES));
        $cleared[$type->value] = max($at, $cleared[$type->value] ?? 0.0);

        self::put($connection, self::CLEARED_TYPES, json_encode($cleared, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * Claim the prune pass for the minute that begins at the instant, and determine if this process now holds it.
     */
    public static function claimPrune(SQLite3 $connection, float $now, int $intervalSeconds): bool
    {
        self::put($connection, self::PRUNE_CLAIMED_AT, '0', replace: false);

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('UPDATE meta SET value = :now WHERE key = :key AND CAST(value AS REAL) < :before');
        $statement->bindValue(':now', self::format($now));
        $statement->bindValue(':key', self::PRUNE_CLAIMED_AT);
        $statement->bindValue(':before', $now - $intervalSeconds, SQLITE3_FLOAT);
        $statement->execute();

        return $connection->changes() === 1;
    }

    /**
     * Record how Nightwatch is installed, touching a fact only when it changed.
     */
    public static function recordNightwatch(SQLite3 $connection, string $version, bool $verified): void
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('INSERT INTO meta (key, value) VALUES (:key, :value) ON CONFLICT (key) DO UPDATE SET value = excluded.value WHERE value IS NOT excluded.value');

        foreach ([
            self::NIGHTWATCH_VERSION => $version,
            self::NIGHTWATCH_VERIFIED => $verified ? '1' : '0',
        ] as $key => $value) {
            $statement->bindValue(':key', $key);
            $statement->bindValue(':value', $value);
            $statement->execute();
            $statement->reset();
        }

        $statement->close();
    }

    /**
     * Read the stored text of a marker, or null when there is none.
     */
    protected static function get(SQLite3 $connection, string $key): ?string
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare('SELECT value FROM meta WHERE key = :key');
        $statement->bindValue(':key', $key);

        /** @var SQLite3Result $result */
        $result = $statement->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        $statement->close();

        return is_array($row) && is_string($row[0]) ? $row[0] : null;
    }

    /**
     * Write a marker, replacing the one it holds unless told to keep it.
     */
    protected static function put(SQLite3 $connection, string $key, string $value, bool $replace = true): void
    {
        $conflict = $replace ? 'DO UPDATE SET value = excluded.value' : 'DO NOTHING';

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("INSERT INTO meta (key, value) VALUES (:key, :value) ON CONFLICT (key) {$conflict}");
        $statement->bindValue(':key', $key);
        $statement->bindValue(':value', $value);
        $statement->execute();
    }

    /**
     * Write an instant that only ever moves forward, and determine if it did.
     */
    protected static function moveForward(SQLite3 $connection, string $key, float $instant): bool
    {
        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("INSERT INTO meta (key, value) VALUES (:key, :value) ON CONFLICT (key) DO UPDATE SET value = excluded.value WHERE CAST(excluded.value AS REAL) > CAST(meta.value AS REAL) OR NOT (meta.value GLOB '[0-9]*')");
        $statement->bindValue(':key', $key);
        $statement->bindValue(':value', self::format($instant));
        $statement->execute();

        return $connection->changes() === 1;
    }

    /**
     * Read the last clear instant of each type from its stored JSON object.
     *
     * @return array<string, float>
     */
    protected static function types(?string $stored): array
    {
        $decoded = json_decode($stored ?? '', associative: true);
        $types = [];

        foreach (is_array($decoded) ? $decoded : [] as $type => $instant) {
            if (is_numeric($instant)) {
                $types[(string) $type] = (float) $instant;
            }
        }

        return $types;
    }

    /**
     * Read a stored marker as an instant, or null for one that is absent or not a number.
     */
    protected static function instant(?string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Write an instant as the store holds it: Unix seconds with microseconds.
     */
    protected static function format(float $instant): string
    {
        return sprintf('%.6F', $instant);
    }

    /**
     * Write a moment as the store holds it, without the rounding of a float.
     */
    protected static function text(CarbonInterface $at): string
    {
        return $at->format('U.u');
    }
}
