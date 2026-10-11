<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Instant;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use Closure;
use LogicException;
use SQLite3;

/**
 * @internal
 */
class Frame
{
    /**
     * The store clock: Unix seconds with microseconds.
     */
    public readonly float $now;

    /**
     * The record types the answer read.
     *
     * @var list<RecordType>
     */
    protected array $types = [];

    /**
     * The window the answer covers.
     */
    protected Window $window;

    /**
     * What holds of the call beyond the types it read.
     *
     * @var list<CallFact>
     */
    protected array $facts = [];

    /**
     * The number of executions of a compared type that straddle the split, or null when nothing is compared.
     */
    protected ?int $straddling = null;

    /**
     * What reading the store found: its facts, why it can't be read, or null before it is read.
     */
    protected StoreFacts|StoreUnusable|null $store = null;

    /**
     * Create a new frame instance.
     */
    public function __construct(
        protected string $tool,
        CarbonImmutable $now,
        public readonly string $timezone,
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
    ) {
        $this->now = Instant::of($now);
    }

    /**
     * Set what the answer covers: the record types it read, its window and the structural blind spots beyond the types.
     *
     * @param  list<RecordType>  $types
     * @param  list<CallFact>  $facts
     * @return $this
     */
    public function about(array $types, Window $window, array $facts = [], ?int $straddling = null): static
    {
        $this->types = $types;
        $this->window = $window;
        $this->facts = $facts;
        $this->straddling = $straddling;

        return $this;
    }

    /**
     * Read the store's facts, then run the body on the same snapshot, or get null when the store can't be read.
     *
     * @template TResult
     *
     * @param  Closure(SQLite3, StoreFacts): TResult  $body
     * @return TResult|null
     */
    public function read(Closure $body): mixed
    {
        return $this->attempt(fn () => $this->reader->snapshot(function (SQLite3 $connection) use ($body) {
            $facts = StoreFacts::read($connection);
            $this->store = $facts;

            return $body($connection, $facts) ?? throw new LogicException('A frame reads null only from a store that cannot be read.');
        }));
    }

    /**
     * Run work that may find the store unusable, or get null when it does.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult|null
     */
    public function attempt(Closure $work): mixed
    {
        try {
            return $work();
        } catch (StoreUnusable $unusable) {
            $this->store = $unusable;

            return null;
        }
    }

    /**
     * Determine if the store was read and holds no records.
     */
    public function isEmpty(): bool
    {
        return $this->store instanceof StoreFacts && $this->store->records === 0;
    }

    /**
     * Get the facts the store was read with.
     */
    public function facts(): StoreFacts
    {
        return $this->store instanceof StoreFacts ? $this->store : throw new LogicException('The frame has not read the store.');
    }

    /**
     * Get why the store can't be read.
     */
    public function unusable(): Emptiness
    {
        return $this->store instanceof StoreUnusable
            ? Emptiness::of($this->store, $this->configuration->database)
            : throw new LogicException('The frame has not found the store unusable.');
    }

    /**
     * Get the emptiness of a store that holds no records, or null when it holds some or was not read.
     */
    public function storeEmpty(): ?Emptiness
    {
        return $this->isEmpty() ? Emptiness::storeEmpty($this->configuration->database) : null;
    }

    /**
     * Get why a store that holds records gave no result, or null when the tool has rows to answer with.
     *
     * The rungs run from the widest cause to the narrowest: a requested group the store never held, which no window or filter can change, then a window that holds no records, then filters that matched none of the rest.
     *
     * @param  bool  $groupHeld  true when no group was requested
     * @param  list<string>  $filters
     */
    public function vacancy(int $inWindow, bool $groupHeld, bool $hasRows, array $filters): ?Emptiness
    {
        return match (true) {
            ! $groupHeld => Emptiness::noMatch($inWindow, $filters),
            $inWindow === 0 => Emptiness::windowEmpty($this->facts()->records),
            ! $hasRows => Emptiness::noMatch($inWindow, $filters),
            default => null,
        };
    }

    /**
     * Get an answer that holds no result, by default because of the store itself.
     *
     * @param  list<string>  $notes
     */
    public function empty(?Emptiness $empty = null, array $notes = []): Answer
    {
        $empty ??= $this->store instanceof StoreUnusable ? $this->unusable() : $this->storeEmpty();

        if ($empty === null) {
            throw new LogicException('The store holds records, so an empty answer needs its own reason.');
        }

        return Answer::empty(tool: $this->tool, now: $this->now, timezone: $this->timezone, window: $this->window, empty: $empty, coverage: $this->coverage(), blindSpots: $this->blindSpots(), notes: $notes);
    }

    /**
     * Get the answer, stated in the frame's clock, window, coverage and blind spots.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $notes
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  list<array{tool: string, arguments: array<string, mixed>, why: string}>  $next
     * @param  list<string>|null  $cuttable
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $recount
     */
    public function answer(string $summary, array $result, ?Emptiness $empty = null, array $notes = [], array $truncated = [], array $next = [], ?array $cuttable = null, ?Closure $recount = null, ?string $capHow = null): Answer
    {
        return new Answer(
            tool: $this->tool,
            now: $this->now,
            timezone: $this->timezone,
            window: $this->window,
            summary: $summary,
            empty: $empty,
            result: $result,
            coverage: $this->coverage(),
            blindSpots: $this->blindSpots(),
            notes: $notes,
            truncated: $truncated,
            next: $next,
            cuttable: $cuttable,
            recount: $recount,
            capHow: $capHow,
        );
    }

    /**
     * Get the coverage of the record types in the store as it was read.
     */
    protected function coverage(): Coverage
    {
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];

        if (! $this->store instanceof StoreFacts) {
            return Coverage::of($this->store ?? throw new LogicException('The frame has not read the store.'), $this->types, History::unknown(...$retention));
        }

        $history = History::of($this->store->meta, $this->types, ...$retention);

        if ($this->store->records === 0) {
            return new Coverage(CoverageState::EMPTY, $this->types, $history, records: 0);
        }

        return new Coverage(CoverageState::OK, $this->types, $history, oldest: $this->store->oldest, newest: $this->store->newest, records: $this->store->records, straddling: $this->straddling);
    }

    /**
     * Get the blind spots of the record types: the structural ones first, then the conditions the store's facts state.
     *
     * @return list<array<string, mixed>>
     */
    protected function blindSpots(): array
    {
        $store = $this->store instanceof StoreFacts ? $this->store : null;

        return [
            ...BlindSpots::for($this->types, $this->facts),
            ...$this->conditions->for($store, $this->types, $this->window),
        ];
    }
}
