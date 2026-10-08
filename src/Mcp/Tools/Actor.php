<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\BlindSpots;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Identification;
use ClaudioDekker\Firewatch\Mcp\Instant;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Stored;
use ClaudioDekker\Firewatch\Mcp\StoreFacts;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @api
 */
#[Name('actor')]
#[Title('Actor')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Actor extends Tool
{
    use AnswersInEnvelope {
        schema as formatSchema;
    }

    /**
     * The most characters `who` has.
     */
    protected const MAXIMUM_WHO = 255;

    /**
     * The whitespace of any script at either end of a text.
     */
    protected const SURROUNDING_WHITESPACE = '/\A\s+|\s+\z/u';

    /**
     * A control character, or a line or paragraph separator: what could end a pattern early or start a line of its own in an answer.
     */
    protected const CONTROL_CHARACTERS = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    /**
     * The values `who` takes, as a refusal names them.
     */
    protected const ACCEPTED = 'a user id, a username or a name, of 1 to '.self::MAXIMUM_WHO.' characters';

    /**
     * A valid call, as a refusal shows it.
     */
    protected const EXAMPLE = 'actor(who: "taylor")';

    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
        protected Conditions $conditions,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.actor');
    }

    /**
     * Get the arguments of the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'who' => $schema->string()->description(__('firewatch::messages.actor_who_argument'))->required(),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the person who is meant, the people who could be, or the people who are known.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the argument, then the store, and put the identification, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $who = $this->who($request);

        $epoch = Instant::of($now);
        $timezone = config()->string('app.timezone');
        $window = Window::none(reason: __('firewatch::messages.actor_window_reason'), timezone: $timezone);
        $retention = [$this->configuration->retentionAgeSeconds, $this->configuration->retentionRecords];
        $types = RecordType::events();

        try {
            [$total, $oldest, $newest, $facts, $identification] = $this->reader->snapshot(fn (SQLite3 $connection) => $this->load($connection, $who));
        } catch (StoreUnusable $unusable) {
            $blindSpots = [...BlindSpots::for($types, actor: true), ...$this->conditions->for(null, $types, $window)];
            $empty = Emptiness::of($unusable, $this->configuration->database);
            $coverage = Coverage::of($unusable, $types, History::unknown(...$retention));

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        $blindSpots = [...BlindSpots::for($types, actor: true), ...$this->conditions->for($facts, $types, $window)];
        $history = History::of($facts->meta, $types, ...$retention);

        $coverage = $total === 0
            ? new Coverage(CoverageState::EMPTY, $types, $history, records: 0)
            : new Coverage(CoverageState::OK, $types, $history, oldest: $oldest, newest: $newest, records: $total);

        if ($total === 0 && $identification->knowsNoOne()) {
            $empty = Emptiness::storeEmpty($this->configuration->database);

            return Answer::empty(tool: $this->name(), now: $epoch, timezone: $timezone, window: $window, empty: $empty, coverage: $coverage, blindSpots: $blindSpots);
        }

        return new Answer(
            ...$this->unknown($who, $identification),
            tool: $this->name(),
            now: $epoch,
            timezone: $timezone,
            window: $window,
            coverage: $coverage,
            blindSpots: $blindSpots,
        );
    }

    /**
     * Read the store's span and facts, and resolve who is meant, in one snapshot.
     *
     * @return array{int, float|null, float|null, StoreFacts, Identification}
     */
    protected function load(SQLite3 $connection, string $who): array
    {
        $span = Stored::rows($connection, 'SELECT count(*) AS total, min(started_at) AS oldest, max(started_at) AS newest FROM records')[0];
        $total = is_int($span['total']) ? $span['total'] : 0;
        $facts = StoreFacts::read($connection);
        $identification = Identification::of($connection, $who);

        return [$total, $span['oldest'], $span['newest'], $facts, $identification];
    }

    /**
     * Get what an answer states when nothing found anyone: the people the user directory holds.
     *
     * @return array{summary: string, empty: Emptiness, result: array<string, mixed>, notes: list<string>, truncated: array{}}
     */
    protected function unknown(string $who, Identification $identification): array
    {
        return [
            'summary' => __('firewatch::messages.actor_unknown_summary', ['who' => $who]),
            'empty' => Emptiness::unknownActor($who, $identification->knownCount),
            'result' => [
                'known_actors' => array_map($this->listed(...), $identification->known->rows),
                'known_actor_count' => $identification->knownCount,
            ],
            'notes' => [__('firewatch::messages.actor_unknown_note')],
            'truncated' => [],
        ];
    }

    /**
     * Get a person as a row of a list.
     *
     * @param  array{id: string, name: mixed, username: mixed, first_seen: float|null, last_seen: float|null}  $person
     * @return array{id: string, name: mixed, username: mixed, last_seen_at: float|null}
     */
    protected function listed(array $person): array
    {
        return [
            'id' => $person['id'],
            'name' => Stored::blank($person['name']),
            'username' => Stored::blank($person['username']),
            'last_seen_at' => $person['last_seen'],
        ];
    }

    /**
     * Read who is meant: text of the accepted length once trimmed, which holds no control character.
     */
    protected function who(Request $request): string
    {
        $value = $request->get('who');

        if ($value === null) {
            throw Refusal::missing(argument: 'who', accepted: self::ACCEPTED, example: self::EXAMPLE);
        }

        if (! is_string($value)) {
            throw $this->invalidWho(expected: 'text', shown: json_encode($value, JSON_THROW_ON_ERROR));
        }

        $who = (string) preg_replace(self::SURROUNDING_WHITESPACE, '', $value);
        $length = mb_strlen($who);

        if ($length < 1 || $length > self::MAXIMUM_WHO) {
            throw $this->invalidWho(expected: '1 to '.self::MAXIMUM_WHO.' characters', shown: $this->shown($who));
        }

        if (preg_match(self::CONTROL_CHARACTERS, $who) === 1) {
            throw $this->invalidWho(expected: 'text without control characters', shown: $this->shown($who));
        }

        return $who;
    }

    /**
     * Get a refused `who` as the refusal shows it: the trimmed text as JSON, which escapes a control character, or only its length when it is over the limit, so that a long value is never printed back.
     */
    protected function shown(string $who): string
    {
        $length = mb_strlen($who);

        if ($length > self::MAXIMUM_WHO) {
            return "{$length} characters";
        }

        // JSON escapes every control character but the delete character.
        return str_replace("\x7F", '\u007f', json_encode($who, JSON_THROW_ON_ERROR));
    }

    /**
     * Get the refusal of a `who` that is not what is expected.
     */
    protected function invalidWho(string $expected, string $shown): Refusal
    {
        return Refusal::invalid(argument: 'who', expected: $expected, value: $shown, accepted: self::ACCEPTED, example: self::EXAMPLE);
    }
}
