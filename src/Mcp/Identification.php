<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Store\UserDirectory;
use SQLite3;

/**
 * @internal
 */
class Identification
{
    /**
     * The most people an answer lists.
     */
    protected const LISTED = 10;

    /**
     * The stages that read the user directory, in the order they are tried.
     *
     * @var list<MatchedBy::ID|MatchedBy::USERNAME|MatchedBy::NAME|MatchedBy::CONTAINS>
     */
    protected const STAGES = [MatchedBy::ID, MatchedBy::USERNAME, MatchedBy::NAME, MatchedBy::CONTAINS];

    /**
     * The characters a pattern reads as a wildcard or as its escape character.
     */
    protected const PATTERN_CHARACTERS = '\\%_';

    /**
     * Create a new identification instance.
     *
     * @param  MatchedBy|null  $matchedBy  what decided, or null when nothing found anyone
     * @param  Rows<array{id: string, worker: string|null, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $found  the people the deciding stage found, newest sighting first: none when nothing found anyone
     * @param  int  $foundCount  all the people the deciding stage found, of whom the most recently seen are listed
     * @param  Rows<array{id: string, worker: string|null, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $known  the people of the user directory, newest sighting first, read only when nothing found anyone
     * @param  int  $knownCount  all the people of the user directory, of whom the most recently seen are listed
     */
    protected function __construct(
        public readonly ?MatchedBy $matchedBy,
        public readonly Rows $found,
        public readonly int $foundCount,
        public readonly Rows $known,
        public readonly int $knownCount,
    ) {
        //
    }

    /**
     * Resolve who is meant by the first stage that finds anyone, in the snapshot of the connection.
     *
     * A call that names a worker reads only the people of that worker. Any other call reads the people of no worker first, through every stage and then the records, as a store that holds no parallel run is read, and the people of every worker only when it finds no one there.
     */
    public static function of(SQLite3 $connection, string $who, ?string $worker = null): self
    {
        $workerConditions = $worker === null ? ['worker IS NULL', null] : ['worker = :worker'];
        $bindings = $worker === null ? [] : ['worker' => $worker];

        foreach ($workerConditions as $workerCondition) {
            $identification = self::among($connection, $who, $workerCondition, $bindings);

            if ($identification !== null) {
                return $identification;
            }
        }

        [$known, $count] = UserDirectory::list($connection, Rows::fetch(self::LISTED));

        return new self(null, Rows::bound([], self::LISTED), 0, Rows::bound($known, self::LISTED), $count);
    }

    /**
     * Resolve who is meant among the people a condition on their worker keeps, or among everyone for none, or get null when no stage and no record finds anyone.
     *
     * @param  array<string, string>  $bindings
     */
    protected static function among(SQLite3 $connection, string $who, ?string $workerCondition, array $bindings): ?self
    {
        foreach (self::STAGES as $stage) {
            [$found, $count] = UserDirectory::list($connection, Rows::fetch(self::LISTED), self::condition($stage, $workerCondition), [
                ...$bindings,
                'who' => self::term($stage, $who),
            ]);

            if ($count > 0) {
                return self::decidedBy($stage, $found, $count);
            }
        }

        [$found, $count] = self::recorded($connection, $who, $workerCondition, $bindings);

        return $count > 0 ? self::decidedBy(MatchedBy::RECORDS, $found, $count) : null;
    }

    /**
     * Create the identification of the people found, with what decided.
     *
     * @param  list<array{id: string, worker: string|null, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $found
     */
    protected static function decidedBy(MatchedBy $matchedBy, array $found, int $count): self
    {
        return new self($matchedBy, Rows::bound($found, self::LISTED), $count, Rows::bound([], self::LISTED), 0);
    }

    /**
     * Determine if several people were found, of whom none is chosen.
     */
    public function isAmbiguous(): bool
    {
        return $this->foundCount > 1;
    }

    /**
     * Determine if nothing found anyone and the user directory holds no one.
     */
    public function knowsNoOne(): bool
    {
        return $this->matchedBy === null && $this->knownCount === 0;
    }

    /**
     * Get the condition a stage puts on the user directory, among the people a condition on their worker keeps, or among everyone for none.
     *
     * @param  MatchedBy::ID|MatchedBy::USERNAME|MatchedBy::NAME|MatchedBy::CONTAINS  $stage
     */
    protected static function condition(MatchedBy $stage, ?string $workerCondition): string
    {
        $stageCondition = match ($stage) {
            MatchedBy::ID => 'id = :who',
            MatchedBy::USERNAME => 'lower(username) = lower(:who)',
            MatchedBy::NAME => 'lower(name) = lower(:who)',
            MatchedBy::CONTAINS => "lower(name) LIKE lower(:who) ESCAPE '\\' OR lower(username) LIKE lower(:who) ESCAPE '\\'",
        };

        return $workerCondition === null ? $stageCondition : "({$stageCondition}) AND {$workerCondition}";
    }

    /**
     * Get what a stage compares the user directory with: the text itself, or the pattern that holds it as plain characters.
     *
     * @param  MatchedBy::ID|MatchedBy::USERNAME|MatchedBy::NAME|MatchedBy::CONTAINS  $stage
     */
    protected static function term(MatchedBy $stage, string $who): string
    {
        return match ($stage) {
            MatchedBy::ID, MatchedBy::USERNAME, MatchedBy::NAME => $who,
            MatchedBy::CONTAINS => '%'.addcslashes($who, self::PATTERN_CHARACTERS).'%',
        };
    }

    /**
     * Read the people the records hold under the text as their user id, one per worker, among those a condition on the worker keeps, or among everyone for none, with how many there are in all.
     *
     * The user directory holds none of them, so only the id and the worker of each are known.
     *
     * @param  array<string, string>  $bindings
     * @return array{list<array{id: string, worker: string|null, name: null, username: null, first_seen: null, last_seen: null}>, int}
     */
    protected static function recorded(SQLite3 $connection, string $who, ?string $workerCondition, array $bindings): array
    {
        $andWorker = $workerCondition === null ? '' : "AND {$workerCondition}";

        /** @var list<array{worker: string|null, matched: int}> $rows */
        $rows = Stored::rows($connection, "SELECT worker, count(*) OVER () AS matched FROM records WHERE user_id = :who {$andWorker} GROUP BY worker ORDER BY worker LIMIT :limit", [
            ...$bindings,
            'who' => $who,
            'limit' => Rows::fetch(self::LISTED),
        ]);

        $people = array_map(fn (array $row) => [
            'id' => $who,
            'worker' => $row['worker'],
            'name' => null,
            'username' => null,
            'first_seen' => null,
            'last_seen' => null,
        ], $rows);

        return [$people, $rows[0]['matched'] ?? 0];
    }
}
