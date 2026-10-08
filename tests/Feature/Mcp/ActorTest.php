<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const ACTOR_AT = 1790776000.5;

const ACTOR_BLIND_SPOTS = [
    'console-requests', 'unanswered-outgoing-requests', 'payload-on-server-error-only', 'dead-counters', 'failed-flag-unpopulated', 'mail-by-notification', 'sync-jobs-unrecorded', 'vendor-defaults-unrecorded',
    'exceptions-unreported', 'named-log-channels', 'memory-is-process-peak', 'query-bindings-unpaired', 'uninstrumented-dispatcher', 'actor-partial', 'octane-bootstrap',
];

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(ACTOR_AT + 3600));
});

/**
 * Build the wire record of a user, seen a number of seconds after the others.
 *
 * @param  array<string, mixed>  $fields
 */
function actorUser(string $id, ?string $name = null, ?string $username = null, float $offset = 0, array $fields = []): RecordBuilder
{
    return syntheticRecord(RecordType::USER)->with(['id' => $id, 'name' => $name ?? '', 'username' => $username ?? '', 'timestamp' => ACTOR_AT + $offset, ...$fields]);
}

/**
 * Build the wire record of a request with no recorded user, so that the window holds an execution attributed to no one.
 */
function actorGuest(): RecordBuilder
{
    return syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '']);
}

/**
 * Call the tool in both formats and get the envelope.
 *
 * @return array<string, mixed>
 */
function actorAnswer(mixed $who): array
{
    return Envelope::assert(Actor::class, ['who' => $who]);
}

/**
 * Call the tool and get the text of its answer, which for a refused call is the refusal.
 *
 * @param  array<string, mixed>  $arguments
 */
function actorRefusal(array $arguments): string
{
    $response = FirewatchServer::tool(Actor::class, $arguments);

    return (fn () => $this->content())->call($response)[0];
}

/**
 * Get a person as a list of an answer shows them.
 *
 * @return array<string, mixed>
 */
function actorRow(string $id, ?string $name, ?string $username, float $offset): array
{
    return ['id' => $id, 'name' => $name, 'username' => $username, 'last_seen_at' => ACTOR_AT + $offset];
}

/**
 * Get the window of an answer asked without a bound.
 *
 * @return array<string, mixed>
 */
function actorWindow(): array
{
    return ['windowed' => true, 'basis' => 'started_at', 'since' => null, 'until' => null, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')];
}

/**
 * Get the summary of a person identified in a window with the given executions.
 */
function actorSummary(string $person, int $attributed = 0, int $total = 0, int $direct = 0, int $unattributable = 0): string
{
    return __('firewatch::messages.actor_summary', ['person' => $person, 'attributed' => $attributed, 'total' => $total, 'direct' => $direct, 'dispatch' => 0, 'inside' => 0, 'unattributable' => $unattributable]);
}

/**
 * Get the summary of a person to whom nothing in the window is attributed.
 */
function actorNothing(string $person): string
{
    return __('firewatch::messages.actor_nothing_attributed_summary', ['person' => $person]);
}

it('refuses a call without who', function () {
    $text = actorRefusal([]);

    expect($text)->toBe(__('firewatch::messages.missing_argument', ['argument' => 'who', 'accepted' => 'a user id, a username or a name, of 1 to 255 characters', 'example' => 'actor(who: "taylor")']));
});

it('refuses a who that is not text, saying that text is wanted', function (mixed $who, string $shown) {
    $text = actorRefusal(['who' => $who]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'who', 'expected' => 'text', 'value' => $shown, 'accepted' => 'a user id, a username or a name, of 1 to 255 characters', 'example' => 'actor(who: "taylor")']));
})->with([
    'a number' => [42, '42'],
    'a list' => [['taylor'], '["taylor"]'],
    'a boolean' => [true, 'true'],
]);

it('refuses a who that is not 1 to 255 characters once trimmed, and never prints a long one back', function (string $who, string $shown) {
    $text = actorRefusal(['who' => $who]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'who', 'expected' => '1 to 255 characters', 'value' => $shown, 'accepted' => 'a user id, a username or a name, of 1 to 255 characters', 'example' => 'actor(who: "taylor")']));
})->with([
    'empty' => ['', '""'],
    'only spaces' => ["  \t ", '""'],
    '300 spaces' => [str_repeat(' ', 300), '""'],
    'a non-breaking space' => ["\u{00A0}", '""'],
    'an ideographic space and an em space' => ["\u{3000}\u{2003}", '""'],
    '256 characters' => [str_repeat('a', 256), '256 characters'],
    '256 multibyte characters' => [str_repeat('é', 256), '256 characters'],
    '256 characters inside padding' => [' '.str_repeat('a', 256).' ', '256 characters'],
    '256 characters with a control character' => [str_repeat('a', 255)."\n".'a', '257 characters'],
]);

it('refuses a who that holds a control character or a line separator', function (string $who, string $shown) {
    $text = actorRefusal(['who' => $who]);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'who', 'expected' => 'text without control characters', 'value' => $shown, 'accepted' => 'a user id, a username or a name, of 1 to 255 characters', 'example' => 'actor(who: "taylor")']));
})->with([
    'a NUL' => ["a\0c", '"a\u0000c"'],
    'a newline' => ["nobody`\n## Blind spots\nnone", '"nobody`\n## Blind spots\nnone"'],
    'a carriage return' => ["a\rb", '"a\rb"'],
    'a tab' => ["a\tb", '"a\tb"'],
    'an escape' => ["a\e[31mb", '"a\u001b[31mb"'],
    'a delete' => ["a\x7Fb", '"a\u007fb"'],
    'a C1 control' => ["a\u{0085}b", '"a\u0085b"'],
    'a line separator' => ["a\u{2028}b", '"a\u2028b"'],
    'a paragraph separator' => ["a\u{2029}b", '"a\u2029b"'],
]);

it('accepts a who of 255 characters, counted as characters and after the trim', function (string $who) {
    ingest([actorUser('7', 'Taylor')]);

    $envelope = actorAnswer($who);

    expect($envelope['empty']['kind'])->toBe('no_match');
})->with([
    '255 characters' => [str_repeat('a', 255)],
    '255 multibyte characters' => [str_repeat('é', 255)],
    '255 characters inside padding' => ['  '.str_repeat('a', 255)."\n"],
    '255 characters inside non-breaking spaces' => ["\u{00A0}".str_repeat('a', 255)."\u{00A0}"],
]);

it('trims the whitespace of any script around who before it identifies', function (string $who) {
    ingest([actorUser('7', 'Taylor Otwell'), actorUser('8', 'Nuno Maduro'), actorGuest()]);

    $envelope = actorAnswer($who);

    expect($envelope['result']['identity'])->toMatchArray(['id' => '7', 'matched_by' => 'name'])
        ->and($envelope['summary'])->toBe(actorNothing('Taylor Otwell'));
})->with([
    'ASCII spaces and a newline' => ["  taylor otwell \n"],
    'non-breaking spaces' => ["\u{00A0}taylor otwell\u{00A0}"],
    'an ideographic space and an em space' => ["\u{3000}taylor otwell\u{2003}"],
    'a line separator at the end' => ["taylor otwell\u{2028}"],
]);

it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
    $text = actorRefusal(['who' => 'taylor', $argument => 'now']);

    expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'actor', 'accepted' => 'who, since, until, limit, format', 'example' => 'actor(format: "json")']));
})->with([
    'a misspelling' => ['whom', 'unknown_argument'],
    'user_id' => ['user_id', 'inapplicable_argument'],
]);

it('refuses a format that is none', function () {
    $text = actorRefusal(['who' => 'taylor', 'format' => 'xml']);

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'format', 'expected' => 'markdown or json', 'value' => '"xml"', 'accepted' => 'markdown or json', 'example' => 'actor(format: "json")']));
});

it('refuses the arguments before it reads the store', function () {
    app()->instance(Reader::class, new class(app(Configuration::class)) extends Reader
    {
        public function snapshot(Closure $callback): mixed
        {
            throw new RuntimeException('the store was read');
        }
    });

    $texts = [actorRefusal([]), actorRefusal(['who' => '']), actorRefusal(['who' => "a\0c"]), actorRefusal(['who' => 'taylor', 'since' => 'soon']), actorRefusal(['who' => 'taylor', 'until' => 'later']), actorRefusal(['who' => 'taylor', 'since' => 'now', 'until' => '-1d']), actorRefusal(['who' => 'taylor', 'limit' => 0]), actorRefusal(['who' => 'taylor', 'whom' => 'x'])];

    expect(array_map(fn (string $text) => strtok($text, "\n"), $texts))->toBe([
        'error: missing_argument',
        'error: invalid_argument',
        'error: invalid_argument',
        'error: unreadable_time',
        'error: unreadable_time',
        'error: empty_window',
        'error: invalid_argument',
        'error: invalid_argument',
    ]);
    Exceptions::assertNothingReported();
});

it('identifies a person, with when they were first and last seen and the stage that decided', function () {
    ingest([actorUser('7', 'Taylor Otwell', 'taylor@example.com', offset: 10), actorUser('8', 'Nuno', 'nuno@example.com', offset: 20)]);
    ingest([actorUser('7', 'Taylor Otwell', 'taylor@example.com', offset: 40), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '7'])]);

    $envelope = actorAnswer('taylor@example.com');

    expect($envelope)->toBe([
        'tool' => 'actor',
        'now' => ACTOR_AT + 3600,
        'window' => actorWindow(),
        'summary' => actorSummary('Taylor Otwell', attributed: 1, total: 1, direct: 1),
        'empty' => null,
        'result' => [
            'identity' => ['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'first_seen_at' => ACTOR_AT + 10, 'last_seen_at' => ACTOR_AT + 40, 'matched_by' => 'username'],
            'attribution' => attributionCounts([
                'requests' => ['total' => 1, 'this_actor' => 1],
                'records' => ['in_window' => 1, 'this_actor' => 1],
            ]),
            'activity' => [
                ['type' => 'request', 'direct' => 1, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'command', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => false],
                ['type' => 'job-attempt', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'scheduled-task', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => false],
                ['type' => 'query', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'exception', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'log', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'cache-event', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'mail', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'notification', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'outgoing-request', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
                ['type' => 'queued-job', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => true],
            ],
            'executions' => [
                ['started_at' => ACTOR_AT, 'type' => 'request', 'execution_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f', 'group_hash' => str_repeat('a', 32), 'label' => '/', 'link' => 'direct'],
            ],
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.actor_no_commands_note'), __('firewatch::messages.actor_caveats_note')],
        'truncated' => [],
        'next' => [
            ['tool' => 'execution', 'arguments' => ['execution_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f'], 'why' => __('firewatch::messages.actor_next_execution')],
            ['tool' => 'occurrences', 'arguments' => ['group' => str_repeat('a', 32)], 'why' => __('firewatch::messages.actor_next_group')],
            ['tool' => 'occurrences', 'arguments' => ['user_id' => '7'], 'why' => __('firewatch::messages.actor_next_user')],
        ],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 1, 'types_read' => array_column(RecordType::events(), 'value')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('identifies by each stage', function (string $who, string $stage) {
    ingest([actorUser('7', 'Taylor Otwell', 'taylor@example.com'), actorUser('8', 'Nuno Maduro', 'nuno@example.com'), actorGuest()]);

    $envelope = actorAnswer($who);

    expect($envelope['result']['identity'])->toMatchArray(['id' => '7', 'matched_by' => $stage])
        ->and($envelope['summary'])->toBe(actorNothing('Taylor Otwell'));
})->with([
    'the id' => ['7', 'id'],
    'the id inside padding' => ["  7\n", 'id'],
    'the username' => ['taylor@example.com', 'username'],
    'the username in another case' => ['Taylor@EXAMPLE.com', 'username'],
    'the name' => ['Taylor Otwell', 'name'],
    'the name in another case' => ['tAYLOR oTWELL', 'name'],
    'a part of the name' => ['otw', 'contains'],
    'a part of the name in another case' => ['OTW', 'contains'],
    'a part of the username' => ['lor@exam', 'contains'],
    'a part of the username in another case' => ['LOR@EXAM', 'contains'],
]);

it('lets the first stage that finds anyone decide, and tries no later one', function (array $first, array $later, string $who, string $stage) {
    ingest([actorUser('first', ...$first, offset: 10), actorUser('later', ...$later, offset: 20), actorGuest()]);

    $envelope = actorAnswer($who);

    expect($envelope['result'])->toHaveKeys(['identity'])
        ->and($envelope['result']['identity'])->toMatchArray(['id' => 'first', 'matched_by' => $stage]);
})->with([
    'the id over a username' => [['name' => 'One'], ['name' => 'Two', 'username' => 'first'], 'first', 'id'],
    'the username over a name' => [['name' => 'One', 'username' => 'sam'], ['name' => 'Sam'], 'sam', 'username'],
    'the name over a part of a name' => [['name' => 'Sam'], ['name' => 'Samuel'], 'sam', 'name'],
    'the name over a part of a username' => [['name' => 'Sam'], ['name' => 'Two', 'username' => 'sam@example.com'], 'sam', 'name'],
]);

it('matches an id exactly, and a number that is no id as a username', function (string $who, string $id, string $stage) {
    ingest([actorUser('ABC', 'One', 'one'), actorUser('9', 'Two', 'abc'), actorUser('10', 'Three', '42'), actorGuest()]);

    $envelope = actorAnswer($who);

    expect($envelope['result']['identity'])->toMatchArray(['id' => $id, 'matched_by' => $stage]);
})->with([
    'the id as stored' => ['ABC', 'ABC', 'id'],
    'the id in another case' => ['abc', '9', 'username'],
    'a number' => ['42', '10', 'username'],
]);

it('folds the case of ASCII letters only', function (string $who, ?string $stage) {
    ingest([actorUser('7', 'Émile Zola', 'Émile@example.com'), actorGuest()]);

    $envelope = actorAnswer($who);

    expect($envelope['empty']['message'])->toBe($stage === null ? __('firewatch::messages.actor_unknown', ['who' => $who, 'population' => 1]) : __('firewatch::messages.actor_nothing_attributed', ['person' => 'Émile Zola', 'population' => 1]))
        ->and($envelope['result']['identity']['matched_by'] ?? null)->toBe($stage);
})->with([
    'the name as stored' => ['Émile Zola', 'name'],
    'the name with its ASCII letters in another case' => ['ÉMILE zOLA', 'name'],
    'the name with the accented letter in another case' => ['émile Zola', null],
    'the username as stored' => ['Émile@example.com', 'username'],
    'the username with the accented letter in another case' => ['émile@example.com', null],
    'a part as stored' => ['Émi', 'contains'],
    'a part with the accented letter in another case' => ['émi', null],
]);

it('reads the wildcards and the escape character of who as plain characters', function (string $who, string $literal, string $wild) {
    ingest([actorUser('literal', $literal, offset: 10), actorUser('wild', $wild, offset: 20), actorUser('by-username', 'Three', $literal, offset: 5)]);

    $envelope = actorAnswer($who);

    expect($envelope['result'])->toBe([
        'matched_by' => 'contains',
        'candidate_count' => 2,
        'candidates' => [actorRow('literal', $literal, null, 10), actorRow('by-username', 'Three', $literal, 5)],
    ]);
})->with([
    'a percent sign' => ['0%', '100% Real', '100 Real'],
    'a percent sign alone' => ['%', '100% Real', 'Plain'],
    'an underscore' => ['a_b', 'xa_bx', 'xacbx'],
    'an underscore alone' => ['_', 'xa_bx', 'Plain'],
    'a backslash' => ['k\\s', 'back\\slash', 'backslash'],
    'a backslash alone' => ['\\', 'back\\slash', 'Plain'],
    'a backslash before a wildcard' => ['\\%', 'a\\%b', 'a\\xb'],
]);

it('identifies a person stored without a name or a username by the id only', function (RecordBuilder $user) {
    ingest([$user, actorUser('8', 'null', 'null'), actorGuest()]);

    $envelope = actorAnswer('42');

    expect($envelope['result']['identity'])->toBe(['id' => '42', 'name' => null, 'username' => null, 'first_seen_at' => ACTOR_AT, 'last_seen_at' => ACTOR_AT, 'matched_by' => 'id'])
        ->and($envelope['summary'])->toBe(actorNothing('42'));
})->with([
    'blank on the wire' => [fn () => actorUser('42')],
    'absent from the wire' => [fn () => actorUser('42')->without('name', 'username')],
]);

it('answers that the window holds no execution for a person whose records are all gone', function () {
    ingest([actorUser('7', 'Taylor', 'taylor@example.com')]);

    $envelope = actorAnswer('taylor');

    expect($envelope['empty'])->toBe(['kind' => 'window_empty', 'population' => 0, 'message' => __('firewatch::messages.actor_no_executions', ['population' => 0])])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.window_empty'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0]);
});

it('lists everyone the deciding stage found when it found several, and guesses no one', function () {
    ingest([
        actorUser('7', 'Sam', 'sam@example.com', offset: 10),
        actorUser('8', 'sam', 'sam@example.org', offset: 30),
        actorUser('9', 'Samuel', offset: 50),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '7']),
    ]);

    $envelope = actorAnswer('SAM');

    expect($envelope)->toBe([
        'tool' => 'actor',
        'now' => ACTOR_AT + 3600,
        'window' => actorWindow(),
        'summary' => __('firewatch::messages.actor_ambiguous_summary', ['who' => 'SAM', 'count' => 2, 'stage' => 'name']),
        'empty' => null,
        'result' => [
            'matched_by' => 'name',
            'candidate_count' => 2,
            'candidates' => [actorRow('8', 'sam', 'sam@example.org', 30), actorRow('7', 'Sam', 'sam@example.com', 10)],
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.actor_ambiguous_note')],
        'truncated' => [],
        'next' => [],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 1])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('finds several people at the username stage when their usernames differ only in case', function () {
    ingest([actorUser('7', 'One', 'Sam'), actorUser('8', 'Two', 'sam'), actorUser('9', 'sam')]);

    $envelope = actorAnswer('sAM');

    expect($envelope['result'])->toBe([
        'matched_by' => 'username',
        'candidate_count' => 2,
        'candidates' => [actorRow('7', 'One', 'Sam', 0), actorRow('8', 'Two', 'sam', 0)],
    ]);
});

it('lists ten candidates, newest sighting first, and says how many there are when there are more', function (int $people, array $listed, bool $cut) {
    ingest(array_map(fn (int $number) => actorUser("user-{$number}", "Sam {$number}", offset: $number), range(1, $people)));

    $envelope = actorAnswer('sam');

    expect(array_column($envelope['result']['candidates'], 'id'))->toBe($listed)
        ->and($envelope['result']['matched_by'])->toBe('contains')
        ->and($envelope['result']['candidate_count'])->toBe($people)
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_ambiguous_summary', ['who' => 'sam', 'count' => $people, 'stage' => 'contains']))
        ->and($envelope['truncated'])->toBe($cut ? [['section' => 'candidates', 'shown' => 10, 'matched' => $people, 'reason' => 'limit', 'how' => __('firewatch::messages.actor_candidates_how', ['listed' => 10])]] : []);
})->with([
    'ten' => [10, ['user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3', 'user-2', 'user-1'], false],
    'eleven' => [11, ['user-11', 'user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3', 'user-2'], true],
    'twelve' => [12, ['user-12', 'user-11', 'user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3'], true],
]);

it('says in markdown how to see past the ten candidates', function () {
    ingest(array_map(fn (int $number) => actorUser("user-{$number}", "Sam {$number}", offset: $number), range(1, 11)));

    $response = FirewatchServer::tool(Actor::class, ['who' => 'sam']);
    $markdown = (fn () => $this->content())->call($response)[0];

    expect(explode("\n", $markdown))->toContain(__('firewatch::messages.truncated', ['section' => 'candidates', 'shown' => 10, 'matched' => 11, 'reason' => 'limit', 'how' => __('firewatch::messages.actor_candidates_how', ['listed' => 10])]));
});

it('leaves who out of the summary of several people when the sentence with it would be cut', function (int $over, bool $named) {
    $room = Answer::SUMMARY_CHARACTERS - mb_strlen(__('firewatch::messages.actor_ambiguous_summary', ['who' => '', 'count' => 2, 'stage' => 'name']));
    $who = str_repeat('a', $room + $over);
    ingest([actorUser('7', $who), actorUser('8', $who)]);

    $envelope = actorAnswer($who);

    expect($envelope['summary'])->toBe($named
        ? __('firewatch::messages.actor_ambiguous_summary', ['who' => $who, 'count' => 2, 'stage' => 'name'])
        : __('firewatch::messages.actor_ambiguous_summary_without_who', ['count' => 2, 'stage' => 'name']))
        ->and(mb_strlen($envelope['summary']))->toBe($named ? Answer::SUMMARY_CHARACTERS : mb_strlen(__('firewatch::messages.actor_ambiguous_summary_without_who', ['count' => 2, 'stage' => 'name'])))
        ->and($envelope['result']['candidate_count'])->toBe(2);
})->with([
    'a sentence of exactly the most characters' => [0, true],
    'one character more' => [1, false],
]);

it('identifies an id that no directory row holds from a record that carries it', function () {
    ingest([actorUser('7', 'Taylor'), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '99'])]);

    $envelope = actorAnswer('99');

    expect($envelope)->toBe([
        'tool' => 'actor',
        'now' => ACTOR_AT + 3600,
        'window' => actorWindow(),
        'summary' => actorSummary('99', attributed: 1, total: 1, direct: 1),
        'empty' => null,
        'result' => [
            'identity' => ['id' => '99', 'name' => null, 'username' => null, 'first_seen_at' => null, 'last_seen_at' => null, 'matched_by' => 'records'],
            'attribution' => attributionCounts([
                'requests' => ['total' => 1, 'this_actor' => 1],
                'records' => ['in_window' => 1, 'this_actor' => 1],
            ]),
            'activity' => attributedActivity(['request' => [1, 0]]),
            'executions' => [
                ['started_at' => ACTOR_AT, 'type' => 'request', 'execution_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f', 'group_hash' => str_repeat('a', 32), 'label' => '/', 'link' => 'direct'],
            ],
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.actor_from_records_note'), __('firewatch::messages.actor_no_commands_note'), __('firewatch::messages.actor_caveats_note')],
        'truncated' => [],
        'next' => [
            ['tool' => 'execution', 'arguments' => ['execution_id' => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f'], 'why' => __('firewatch::messages.actor_next_execution')],
            ['tool' => 'occurrences', 'arguments' => ['group' => str_repeat('a', 32)], 'why' => __('firewatch::messages.actor_next_group')],
            ['tool' => 'occurrences', 'arguments' => ['user_id' => '99'], 'why' => __('firewatch::messages.actor_next_user')],
        ],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 1])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('identifies from a record of any type that carries the id', function (RecordType $type) {
    ingest([syntheticRecord($type)->with(['timestamp' => ACTOR_AT, 'user' => '99']), actorGuest()]);

    $envelope = actorAnswer('99');

    expect($envelope['result']['identity'])->toMatchArray(['id' => '99', 'matched_by' => 'records']);
})->with([
    'a request' => [RecordType::REQUEST],
    'a job attempt' => [RecordType::JOB_ATTEMPT],
    'a query' => [RecordType::QUERY],
]);

it('identifies from records only by the whole id as recorded', function (string $who) {
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => 'ABC-99'])]);

    $envelope = actorAnswer($who);

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => 0, 'message' => __('firewatch::messages.actor_unknown', ['who' => $who, 'population' => 0])])
        ->and($envelope['result'])->toBe(['known_actors' => [], 'known_actor_count' => 0]);
})->with([
    'another case' => ['abc-99'],
    'a part of it' => ['ABC'],
    'a wildcard' => ['ABC-%'],
]);

it('lets a directory stage decide before the records are read', function () {
    ingest([actorUser('5', 'Sam', '99'), syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '99'])]);

    $envelope = actorAnswer('99');

    expect($envelope['result']['identity'])->toMatchArray(['id' => '5', 'matched_by' => 'username'])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.actor_no_commands_note'), __('firewatch::messages.actor_caveats_note')]);
});

it('identifies the signed-in user of a real request by name', function () {
    forceRequests();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Taylor Otwell', 'email' => 'taylor@example.com']));

    $this->get('/');

    $envelope = actorAnswer('taylor otwell');

    expect($envelope['empty'])->toBeNull()
        ->and($envelope['result']['identity'])->toMatchArray(['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'matched_by' => 'name'])
        ->and($envelope['result']['identity']['first_seen_at'])->toBeFloat()
        ->and($envelope['result']['identity']['last_seen_at'])->toBe($envelope['result']['identity']['first_seen_at'])
        ->and($envelope['coverage']['records'])->toBeGreaterThanOrEqual(1);
});

it('answers that nobody was identified, with the people the directory holds, newest sighting first', function () {
    ingest([
        actorUser('7', 'Taylor', 'taylor@example.com', offset: 10),
        actorUser('9', 'Jess', 'jess@example.com', offset: 30),
        actorUser('8', 'Nuno', offset: 20),
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT, 'user' => '7']),
    ]);

    $envelope = actorAnswer('mohamed');

    expect($envelope)->toBe([
        'tool' => 'actor',
        'now' => ACTOR_AT + 3600,
        'window' => actorWindow(),
        'summary' => __('firewatch::messages.actor_unknown_summary', ['who' => 'mohamed']),
        'empty' => ['kind' => 'no_match', 'population' => 3, 'message' => __('firewatch::messages.actor_unknown', ['who' => 'mohamed', 'population' => 3])],
        'result' => [
            'known_actors' => [
                actorRow('9', 'Jess', 'jess@example.com', 30),
                actorRow('8', 'Nuno', null, 20),
                actorRow('7', 'Taylor', 'taylor@example.com', 10),
            ],
            'known_actor_count' => 3,
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [__('firewatch::messages.actor_unknown_note')],
        'truncated' => [],
        'next' => [],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 1, 'types_read' => array_column(RecordType::events(), 'value')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('names the longest who there is in the summary when nobody was identified', function () {
    $who = str_repeat('a', 255);
    ingest([actorUser('7', 'Taylor')]);

    $envelope = actorAnswer($who);

    expect($envelope['summary'])->toBe(__('firewatch::messages.actor_unknown_summary', ['who' => $who]));
});

it('lists the ten people seen most recently and counts them all, without a truncated entry', function (int $people, array $listed) {
    ingest(array_map(fn (int $number) => actorUser("user-{$number}", "User {$number}", offset: $number), range(1, $people)));

    $envelope = actorAnswer('mohamed');

    expect(array_column($envelope['result']['known_actors'], 'id'))->toBe($listed)
        ->and($envelope['result']['known_actor_count'])->toBe($people)
        ->and($envelope['empty']['population'])->toBe($people)
        ->and($envelope['truncated'])->toBe([]);
})->with([
    'ten' => [10, ['user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3', 'user-2', 'user-1']],
    'eleven' => [11, ['user-11', 'user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3', 'user-2']],
    'twelve' => [12, ['user-12', 'user-11', 'user-10', 'user-9', 'user-8', 'user-7', 'user-6', 'user-5', 'user-4', 'user-3']],
]);

it('orders people seen at the same instant by id', function () {
    ingest([actorUser('b', 'Second'), actorUser('c', 'Third'), actorUser('a', 'First')]);

    $envelope = actorAnswer('mohamed');

    expect(array_column($envelope['result']['known_actors'], 'id'))->toBe(['a', 'b', 'c']);
});

it('answers that nobody was identified among no one when the store holds records and an empty directory', function () {
    ingest([syntheticRecord(RecordType::REQUEST)->with(['timestamp' => ACTOR_AT])]);

    $envelope = actorAnswer('mohamed');

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => 0, 'message' => __('firewatch::messages.actor_unknown', ['who' => 'mohamed', 'population' => 0])])
        ->and($envelope['result'])->toBe(['known_actors' => [], 'known_actor_count' => 0])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.actor_unknown_note')]);
});

it('answers that no store exists', function () {
    $envelope = actorAnswer('taylor');

    expect($envelope['empty']['kind'])->toBe('no_store')
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.no_store'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['notes'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'absent', 'types_read' => array_column(RecordType::events(), 'value')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('answers that the store is empty when it holds no record and no one', function () {
    app(Writer::class)->transaction(fn () => null);

    $envelope = actorAnswer('taylor');

    expect($envelope['empty']['kind'])->toBe('store_empty')
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.store_empty'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('answers that the store is unusable', function () {
    $path = app(Configuration::class)->database;
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, str_repeat('not a database ', 100));

    $envelope = actorAnswer('taylor');

    expect($envelope['empty']['kind'])->toBe('store_unusable')
        ->and($envelope['coverage'])->toMatchArray(['state' => 'unusable', 'reason' => 'foreign_file'])
        ->and($envelope['result'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toBe(ACTOR_BLIND_SPOTS);
});

it('lists the people of a directory whose records are all gone, and states that no record is left', function () {
    ingest([actorUser('7', 'Taylor', 'taylor@example.com')]);

    $envelope = actorAnswer('mohamed');

    expect($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['result'])->toBe(['known_actors' => [actorRow('7', 'Taylor', 'taylor@example.com', 0)], 'known_actor_count' => 1])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0, 'oldest_at' => null, 'newest_at' => null]);
});

test('the tool is listed last, with its description, arguments and annotations', function () {
    $listing = app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
    $tool = $listing['tools'][6];

    expect(count($listing['tools']))->toBe(7)
        ->and($tool['name'])->toBe('actor')
        ->and($tool['description'])->toBe(__('firewatch::messages.tools.actor'))
        ->and($tool['inputSchema'])->toBe([
            'properties' => [
                'who' => ['description' => __('firewatch::messages.actor_who_argument'), 'type' => 'string'],
                'since' => ['description' => __('firewatch::messages.since_argument'), 'type' => 'string'],
                'until' => ['description' => __('firewatch::messages.until_argument'), 'type' => 'string'],
                'limit' => ['description' => __('firewatch::messages.actor_limit_argument'), 'type' => 'integer'],
                'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
            ],
            'type' => 'object',
            'required' => ['who'],
        ])
        ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]);
});
