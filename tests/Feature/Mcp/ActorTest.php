<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Server\Transport\FakeTransporter;

const ACTOR_AT = 1790776000.5;

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

it('refuses an argument that is not the tool\'s, naming what it accepts', function (string $argument, string $key) {
    $text = actorRefusal(['who' => 'taylor', $argument => 'now']);

    expect($text)->toBe(__("firewatch::messages.{$key}", ['argument' => $argument, 'tool' => 'actor', 'accepted' => 'who, format', 'example' => 'actor(format: "json")']));
})->with([
    'a misspelling' => ['whom', 'unknown_argument'],
    'since' => ['since', 'inapplicable_argument'],
    'until' => ['until', 'inapplicable_argument'],
    'limit' => ['limit', 'inapplicable_argument'],
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

    $texts = [actorRefusal([]), actorRefusal(['who' => '']), actorRefusal(['who' => "a\0c"]), actorRefusal(['who' => 'taylor', 'since' => '-1d']), actorRefusal(['who' => 'taylor', 'until' => 'now']), actorRefusal(['who' => 'taylor', 'limit' => 5]), actorRefusal(['who' => 'taylor', 'whom' => 'x'])];

    expect(array_map(fn (string $text) => strtok($text, "\n"), $texts))->toBe([
        'error: missing_argument',
        'error: invalid_argument',
        'error: invalid_argument',
        'error: conflicting_arguments',
        'error: conflicting_arguments',
        'error: conflicting_arguments',
        'error: invalid_argument',
    ]);
    Exceptions::assertNothingReported();
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
        'window' => ['windowed' => false, 'reason' => __('firewatch::messages.actor_window_reason')],
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
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
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
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
});

it('answers that the store is empty when it holds no record and no one', function () {
    app(Writer::class)->transaction(fn () => null);

    $envelope = actorAnswer('taylor');

    expect($envelope['empty']['kind'])->toBe('store_empty')
        ->and($envelope['summary'])->toBe(__('firewatch::messages.empty_summary.store_empty'))
        ->and($envelope['result'])->toBe([])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'empty', 'records' => 0])
        ->and($envelope['next'])->toBe([])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
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
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
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
                'format' => ['description' => __('firewatch::messages.format_argument'), 'enum' => ['markdown', 'json'], 'type' => 'string'],
            ],
            'type' => 'object',
            'required' => ['who'],
        ])
        ->and($tool['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]);
});
