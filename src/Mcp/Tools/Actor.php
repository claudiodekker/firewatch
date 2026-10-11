<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Arguments;
use ClaudioDekker\Firewatch\Mcp\Attribution;
use ClaudioDekker\Firewatch\Mcp\Concerns\AnswersInEnvelope;
use ClaudioDekker\Firewatch\Mcp\Conditions;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\Identification;
use ClaudioDekker\Firewatch\Mcp\MatchedBy;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Cell;
use ClaudioDekker\Firewatch\Store\Reader;
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
     * The executions an answer lists when no limit is asked for.
     */
    protected const DEFAULT_LIMIT = 20;

    /**
     * The most executions an answer lists.
     */
    protected const MAXIMUM_LIMIT = 100;

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
            ...$this->windowSchema($schema),
            'limit' => $this->limitArgument($schema, maximum: self::MAXIMUM_LIMIT, default: self::DEFAULT_LIMIT),
            ...$this->formatSchema($schema),
        ];
    }

    /**
     * Answer with the person who is meant and the work of the window attributed to them, the people who could be meant, or the people who are known.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer($request, fn () => $this->read($request, Date::now()->toImmutable()));
    }

    /**
     * Read the arguments, then the store, and put the identification and the attribution, or why there is none, in the envelope.
     */
    protected function read(Request $request, CarbonImmutable $now): Answer
    {
        $arguments = new Arguments($request, $this->name());
        $who = $this->who($arguments);
        $frame = $this->frame($now);
        $window = Window::read($request, $now, timezone: $frame->timezone, tool: $this->name());
        $limit = $arguments->limit(self::MAXIMUM_LIMIT, self::DEFAULT_LIMIT, alongside: ['who' => 'taylor']);

        $frame->about(RecordType::events(), $window, actor: true);

        $read = $frame->read(fn (SQLite3 $connection) => $this->load($connection, $who, $window, $limit));

        if ($read === null) {
            return $frame->empty();
        }

        [$identification, $attribution] = $read;

        if ($frame->isEmpty() && $identification->knowsNoOne()) {
            return $frame->empty();
        }

        if ($attribution?->total() === 0) {
            return $frame->empty(Emptiness::noExecutions($frame->facts()->records));
        }

        return $frame->answer(...$this->stated($who, $identification, $attribution, $window));
    }

    /**
     * Resolve who is meant and, for one person, attribute the window's work to them.
     *
     * @return array{Identification, Attribution|null}
     */
    protected function load(SQLite3 $connection, string $who, Window $window, int $limit): array
    {
        $identification = Identification::of($connection, $who);

        $attribution = $identification->matchedBy === null || $identification->isAmbiguous()
            ? null
            : Attribution::of($connection, $window, $identification->found->rows[0]['id'], $limit);

        return [$identification, $attribution];
    }

    /**
     * Get what an answer states for the state of the identification.
     *
     * @return array{summary: string, empty: Emptiness|null, result: array<string, mixed>, notes: list<string>, truncated: list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>, next: list<array{tool: string, arguments: array<string, mixed>, why: string}>, cuttable: list<string>|null}
     */
    protected function stated(string $who, Identification $identification, ?Attribution $attribution, Window $window): array
    {
        $decidedBy = $identification->matchedBy;

        if ($decidedBy === null) {
            return $this->unknown($who, $identification);
        }

        return $attribution === null
            ? $this->ambiguous($who, $decidedBy, $identification)
            : $this->identified($decidedBy, $identification, $attribution, $window);
    }

    /**
     * Get what an answer states for the one person found: who they are and the work of the window attributed to them.
     *
     * @return array{summary: string, empty: Emptiness|null, result: array<string, mixed>, notes: list<string>, truncated: list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>, next: list<array{tool: string, arguments: array<string, mixed>, why: string}>, cuttable: list<string>}
     */
    protected function identified(MatchedBy $decidedBy, Identification $identification, Attribution $attribution, Window $window): array
    {
        $person = $identification->found->rows[0];
        $name = Cell::blank($person['name']);
        $named = is_string($name) ? $name : $person['id'];

        $empty = $attribution->attributed() === 0 ? Emptiness::nothingAttributed($named, $attribution->total()) : null;

        $listed = count($attribution->executions->rows);
        $how = trans_choice('firewatch::messages.actor_executions_how', $listed, ['listed' => $listed]);
        $cut = $attribution->executions->truncation(section: 'executions', how: $how);

        return [
            'summary' => $empty === null ? $this->summary($named, $person['id'], $attribution) : $this->nothingAttributed($named, $person['id']),
            'empty' => $empty,
            'result' => [
                'identity' => [
                    'id' => $person['id'],
                    'name' => $name,
                    'username' => Cell::blank($person['username']),
                    'first_seen_at' => $person['first_seen'],
                    'last_seen_at' => $person['last_seen'],
                    'matched_by' => $decidedBy->value,
                ],
                ...$attribution->result(),
            ],
            'notes' => $this->notes($decidedBy, $attribution),
            'truncated' => $cut === null ? [] : [$cut],
            'next' => $this->next($person['id'], $attribution, $window),
            'cuttable' => ['executions'],
        ];
    }

    /**
     * Get the summary of the attribution, which names the person, else gives their id, else leaves them out, so that the sentence is never cut.
     */
    protected function summary(string $named, string $id, Attribution $attribution): string
    {
        $counts = [
            'attributed' => $attribution->attributed(),
            'total' => $attribution->total(),
            ...$attribution->links,
            'unattributable' => $attribution->unattributable(),
        ];

        return $this->naming('actor_summary', $named, $id, $counts);
    }

    /**
     * Get the summary of a person to whom nothing in the window is attributed, named as the attribution summary names them.
     */
    protected function nothingAttributed(string $named, string $id): string
    {
        return $this->naming('actor_nothing_attributed_summary', $named, $id);
    }

    /**
     * Get the sentence of a key that names the person by name, else by id, else leaves them out through the key's sentence without a person, whichever first fits a summary whole.
     *
     * @param  array<string, int>  $replace
     */
    protected function naming(string $key, string $named, string $id, array $replace = []): string
    {
        foreach (array_unique([$named, $id]) as $person) {
            $summary = __("firewatch::messages.{$key}", [
                'person' => $person,
                ...$replace,
            ]);

            if (mb_strlen($summary) <= Answer::SUMMARY_CHARACTERS) {
                return $summary;
            }
        }

        return __("firewatch::messages.{$key}_without_person", $replace);
    }

    /**
     * Get the notes of an attribution, in the fixed order.
     *
     * @return list<string>
     */
    protected function notes(MatchedBy $decidedBy, Attribution $attribution): array
    {
        $commands = $attribution->commands();
        $tasks = $attribution->scheduledTasks();
        $attempts = $attribution->attemptsWithoutActor();
        $requests = $attribution->requestsWithoutUser();
        $notes = [];

        if ($decidedBy === MatchedBy::RECORDS) {
            $notes[] = __('firewatch::messages.actor_from_records_note');
        }

        $notes[] = $commands + $tasks === 0
            ? __('firewatch::messages.actor_no_commands_note')
            : __('firewatch::messages.actor_commands_note', [
                'commands' => trans_choice('firewatch::messages.actor_commands_count', $commands, ['count' => $commands]),
                'tasks' => trans_choice('firewatch::messages.actor_tasks_count', $tasks, ['count' => $tasks]),
                'inside' => trans_choice('firewatch::messages.actor_inside_count', $attribution->inside(), ['count' => $attribution->inside()]),
            ]);

        if ($attempts > 0) {
            $notes[] = trans_choice('firewatch::messages.actor_no_actor_note', $attempts, ['count' => $attempts]);
        }

        if ($requests > 0) {
            $notes[] = trans_choice('firewatch::messages.actor_guest_note', $requests, ['count' => $requests]);
        }

        $notes[] = __('firewatch::messages.actor_identity_note');

        return $notes;
    }

    /**
     * Get the calls that open the newest listed execution and its group, and list the records that carry the person's id, over the same window.
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, why: string}>
     */
    protected function next(string $id, Attribution $attribution, Window $window): array
    {
        $newest = $attribution->executions->rows[0] ?? null;
        $calls = [];

        if ($newest !== null) {
            $calls[] = [
                'tool' => 'execution',
                'arguments' => ['execution_id' => $newest['execution_id']],
                'why' => __('firewatch::messages.actor_next_execution'),
            ];
        }

        if ($newest !== null && $newest['group_hash'] !== null) {
            $calls[] = [
                'tool' => 'occurrences',
                'arguments' => [
                    'group' => $newest['group_hash'],
                    ...$window->arguments(),
                ],
                'why' => __('firewatch::messages.actor_next_group'),
            ];
        }

        if ($attribution->recorded) {
            $calls[] = [
                'tool' => 'occurrences',
                'arguments' => [
                    'user_id' => $id,
                    ...$window->arguments(),
                ],
                'why' => __('firewatch::messages.actor_next_user'),
            ];
        }

        return $calls;
    }

    /**
     * Get what an answer states for the several people the deciding stage found, none of whom is chosen.
     *
     * @return array{summary: string, empty: null, result: array<string, mixed>, notes: list<string>, truncated: list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>, next: array{}, cuttable: null}
     */
    protected function ambiguous(string $who, MatchedBy $decidedBy, Identification $identification): array
    {
        $candidates = $identification->found;
        $how = __('firewatch::messages.actor_candidates_how', ['listed' => count($candidates->rows)]);
        $cut = $candidates->truncation(section: 'candidates', how: $how, matched: $identification->foundCount);

        return [
            'summary' => $this->ambiguousSummary($who, $decidedBy, $identification->foundCount),
            'empty' => null,
            'result' => [
                'matched_by' => $decidedBy->value,
                'candidate_count' => $identification->foundCount,
                'candidates' => array_map($this->listed(...), $candidates->rows),
            ],
            'notes' => [__('firewatch::messages.actor_ambiguous_note')],
            'truncated' => $cut === null ? [] : [$cut],
            'next' => [],
            'cuttable' => null,
        ];
    }

    /**
     * Get the summary of several people, which names `who` unless the sentence would then be cut and lose how to go on.
     */
    protected function ambiguousSummary(string $who, MatchedBy $decidedBy, int $count): string
    {
        $found = [
            'count' => $count,
            'stage' => $decidedBy->value,
        ];

        $named = __('firewatch::messages.actor_ambiguous_summary', [
            ...$found,
            'who' => $who,
        ]);

        return mb_strlen($named) <= Answer::SUMMARY_CHARACTERS ? $named : __('firewatch::messages.actor_ambiguous_summary_without_who', $found);
    }

    /**
     * Get what an answer states when nothing found anyone: the people the user directory holds.
     *
     * @return array{summary: string, empty: Emptiness, result: array<string, mixed>, notes: list<string>, truncated: array{}, next: array{}, cuttable: null}
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
            'next' => [],
            'cuttable' => null,
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
            'name' => Cell::blank($person['name']),
            'username' => Cell::blank($person['username']),
            'last_seen_at' => $person['last_seen'],
        ];
    }

    /**
     * Read who is meant: text of the accepted length once trimmed, which holds no control character.
     */
    protected function who(Arguments $arguments): string
    {
        $value = $arguments->get('who');

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
