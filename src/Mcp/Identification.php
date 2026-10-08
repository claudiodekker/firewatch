<?php

namespace ClaudioDekker\Firewatch\Mcp;

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
     * @param  Rows<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $found  the people the deciding stage found, newest sighting first: none when nothing found anyone
     * @param  int  $foundCount  all the people the deciding stage found, of whom the most recently seen are listed
     * @param  Rows<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $known  the people of the user directory, newest sighting first, read only when nothing found anyone
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
     */
    public static function of(SQLite3 $connection, string $who): self
    {
        foreach (self::STAGES as $stage) {
            [$found, $count] = self::people($connection, self::condition($stage), ['who' => self::term($stage, $who)]);

            if ($count > 0) {
                return self::decidedBy($stage, $found, $count);
            }
        }

        if (self::recorded($connection, $who)) {
            return self::decidedBy(MatchedBy::RECORDS, [self::withoutDirectoryRow($who)], 1);
        }

        [$known, $count] = self::people($connection);

        return new self(null, Rows::bound([], self::LISTED), 0, Rows::bound($known, self::LISTED), $count);
    }

    /**
     * Create the identification of the people found, with what decided.
     *
     * @param  list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>  $found
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
     * Get the condition a stage puts on the user directory.
     *
     * @param  MatchedBy::ID|MatchedBy::USERNAME|MatchedBy::NAME|MatchedBy::CONTAINS  $stage
     */
    protected static function condition(MatchedBy $stage): string
    {
        return match ($stage) {
            MatchedBy::ID => 'id = :who',
            MatchedBy::USERNAME => 'lower(username) = lower(:who)',
            MatchedBy::NAME => 'lower(name) = lower(:who)',
            MatchedBy::CONTAINS => "lower(name) LIKE lower(:who) ESCAPE '\\' OR lower(username) LIKE lower(:who) ESCAPE '\\'",
        };
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
     * Determine if any record carries the text as its user id.
     */
    protected static function recorded(SQLite3 $connection, string $who): bool
    {
        return Stored::rows($connection, 'SELECT 1 AS held FROM records WHERE user_id = :who LIMIT 1', ['who' => $who]) !== [];
    }

    /**
     * Get the person of an id that the user directory does not hold, of whom only the id is known.
     *
     * @return array{id: string, name: null, username: null, first_seen: null, last_seen: null}
     */
    protected static function withoutDirectoryRow(string $id): array
    {
        return [
            'id' => $id,
            'name' => null,
            'username' => null,
            'first_seen' => null,
            'last_seen' => null,
        ];
    }

    /**
     * Read the people of the user directory, or those a condition keeps, newest sighting first and one more than is listed, and count them all.
     *
     * @param  array<string, string>  $bindings
     * @return array{list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}>, int}
     */
    protected static function people(SQLite3 $connection, ?string $condition = null, array $bindings = []): array
    {
        $where = $condition === null ? '' : "WHERE {$condition}";

        /** @var list<array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null, matched: int}> $rows */
        $rows = Stored::rows($connection, "SELECT id, name, username, first_seen, last_seen, count(*) OVER () AS matched
            FROM users {$where} ORDER BY last_seen DESC, id LIMIT :limit", [
            ...$bindings,
            'limit' => Rows::fetch(self::LISTED),
        ]);

        $people = array_map(fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'username' => $row['username'],
            'first_seen' => $row['first_seen'],
            'last_seen' => $row['last_seen'],
        ], $rows);

        return [$people, $rows[0]['matched'] ?? 0];
    }
}
