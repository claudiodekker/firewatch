<?php

use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use Illuminate\Support\Facades\Date;

const ATTRIBUTED_AT = 1790776000.5;

const ATTRIBUTED_GROUP = 'abcdef0123456789abcdef0123456789';

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(ATTRIBUTED_AT + 3600));
});

/**
 * Build the wire record of the user the tests ask about, or of another.
 */
function attributedUser(string $id = '7', string $name = 'Taylor'): RecordBuilder
{
    return syntheticRecord(RecordType::USER)->with(['id' => $id, 'name' => $name, 'username' => '', 'timestamp' => ATTRIBUTED_AT]);
}

/**
 * Build the wire record of an execution, with the user it carries where its type carries one.
 *
 * @param  array<string, mixed>  $fields
 */
function attributedExecution(RecordType $type, string $id, float $offset = 0, ?string $user = null, array $fields = []): RecordBuilder
{
    $record = syntheticRecord($type)->inExecution($id)->with(['timestamp' => ATTRIBUTED_AT + $offset, '_group' => ATTRIBUTED_GROUP, ...$fields]);

    return $user === null ? $record : $record->with(['user' => $user]);
}

/**
 * Build the wire record of a job attempt.
 */
function attributedAttempt(string $id, string $job, string $user = '', float $offset = 0): RecordBuilder
{
    return attributedExecution(RecordType::JOB_ATTEMPT, $id, $offset, $user, ['job_id' => $job]);
}

/**
 * Build the wire record of the dispatch of a job, stamped when it ended.
 */
function attributedDispatch(string $job, string $user = '', float $offset = 0): RecordBuilder
{
    return syntheticRecord(RecordType::QUEUED_JOB)->with(['job_id' => $job, 'user' => $user, 'execution_id' => "dispatcher-{$job}", 'execution_source' => 'request', 'timestamp' => ATTRIBUTED_AT + $offset]);
}

/**
 * Build the wire record of a child of an execution.
 */
function attributedChild(RecordType $type, string $execution, string $source, string $user = '', float $offset = 0): RecordBuilder
{
    return syntheticRecord($type)->with(['execution_id' => $execution, 'execution_source' => $source, 'user' => $user, 'timestamp' => ATTRIBUTED_AT + $offset]);
}

/**
 * Ask about a person in both formats and get the envelope.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function attributedAnswer(string $who = '7', array $arguments = []): array
{
    return Envelope::assert(Actor::class, ['who' => $who, ...$arguments]);
}

/**
 * Get the counts of the attribution block of an answer that says nothing else.
 *
 * @param  array<string, array<string, int>>  $counts
 * @return array<string, array<string, int>>
 */
function attributionCounts(array $counts = []): array
{
    return array_replace_recursive([
        'requests' => ['total' => 0, 'this_actor' => 0, 'other_actors' => 0, 'guest' => 0],
        'job_attempts' => ['total' => 0, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 0],
        'commands' => ['total' => 0, 'this_actor' => 0, 'unattributable' => 0],
        'scheduled_tasks' => ['total' => 0, 'this_actor' => 0, 'unattributable' => 0],
        'records' => ['in_window' => 0, 'this_actor' => 0, 'without_actor' => 0],
    ], $counts);
}

/**
 * Get the activity of an answer, a row for each of the twelve types, from the counts of the types that have any.
 *
 * @param  array<string, array{int, int}>  $counts
 * @return list<array{type: string, direct: int, dispatch: int, can_carry_actor: bool}>
 */
function attributedActivity(array $counts = []): array
{
    return array_map(fn (RecordType $type) => [
        'type' => $type->value,
        'direct' => $counts[$type->value][0] ?? 0,
        'dispatch' => $counts[$type->value][1] ?? 0,
        'can_carry_actor' => ! in_array($type, [RecordType::COMMAND, RecordType::SCHEDULED_TASK], true),
    ], RecordType::events());
}

/**
 * Get the links of the executions an answer lists, by execution id.
 *
 * @param  array<string, mixed>  $envelope
 * @return array<string, string>
 */
function attributedLinks(array $envelope): array
{
    return array_column($envelope['result']['executions'], 'link', 'execution_id');
}

it('attributes the requests of the window to the person and counts all the others', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'mine', offset: 10, user: '7', fields: ['route_path' => '/orders']),
        attributedExecution(RecordType::REQUEST, 'theirs', offset: 20, user: '8'),
        attributedExecution(RecordType::REQUEST, 'guest', offset: 30, user: ''),
        attributedChild(RecordType::QUERY, 'mine', 'request', offset: 11),
    ]);

    $envelope = attributedAnswer('taylor');

    expect($envelope)->toBe([
        'tool' => 'actor',
        'now' => ATTRIBUTED_AT + 3600,
        'window' => ['windowed' => true, 'basis' => 'started_at', 'since' => null, 'until' => null, 'timezone' => 'UTC', 'description' => __('firewatch::messages.window_description')],
        'summary' => __('firewatch::messages.actor_summary', ['person' => 'Taylor', 'attributed' => 1, 'total' => 3, 'direct' => 1, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 1]),
        'empty' => null,
        'result' => [
            'identity' => ['id' => '7', 'name' => 'Taylor', 'username' => null, 'first_seen_at' => ATTRIBUTED_AT, 'last_seen_at' => ATTRIBUTED_AT, 'matched_by' => 'name'],
            'attribution' => attributionCounts([
                'requests' => ['total' => 3, 'this_actor' => 1, 'other_actors' => 1, 'guest' => 1],
                'records' => ['in_window' => 4, 'this_actor' => 2, 'without_actor' => 1],
            ]),
            'activity' => attributedActivity(['request' => [1, 0], 'query' => [1, 0]]),
            'executions' => [
                ['started_at' => ATTRIBUTED_AT + 10, 'type' => 'request', 'execution_id' => 'mine', 'group_hash' => ATTRIBUTED_GROUP, 'label' => '/orders', 'link' => 'direct'],
            ],
        ],
        'coverage' => $envelope['coverage'],
        'blind_spots' => $envelope['blind_spots'],
        'notes' => [
            __('firewatch::messages.actor_no_commands_note'),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_caveats_note'),
        ],
        'truncated' => [],
        'next' => [
            ['tool' => 'execution', 'arguments' => ['execution_id' => 'mine'], 'why' => __('firewatch::messages.actor_next_execution')],
            ['tool' => 'occurrences', 'arguments' => ['group' => ATTRIBUTED_GROUP], 'why' => __('firewatch::messages.actor_next_group')],
            ['tool' => 'occurrences', 'arguments' => ['user_id' => '7'], 'why' => __('firewatch::messages.actor_next_user')],
        ],
    ])
        ->and($envelope['coverage'])->toMatchArray(['state' => 'ok', 'records' => 4, 'types_read' => array_column(RecordType::events(), 'value')])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial');
});

it('attributes a job attempt by its own user before its dispatch, and by the one dispatch the job has', function (array $records, ?string $link, array $counts) {
    ingest([attributedUser(), ...$records]);

    $envelope = attributedAnswer();

    expect($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, ...$counts])
        ->and(attributedLinks($envelope))->toBe($link === null ? [] : ['attempt' => $link]);
})->with([
    'its own user' => [fn () => [attributedAttempt('attempt', 'job', user: '7')], 'direct', ['this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0]],
    'its own user over a dispatch of someone else' => [fn () => [attributedDispatch('job', user: '8'), attributedAttempt('attempt', 'job', user: '7')], 'direct', ['this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0]],
    'the own user of someone else over the person\'s dispatch' => [fn () => [attributedDispatch('job', user: '7'), attributedAttempt('attempt', 'job', user: '8')], null, ['this_actor' => 0, 'other_actors' => 1, 'no_actor' => 0]],
    'no user of its own and the person\'s dispatch' => [fn () => [attributedDispatch('job', user: '7'), attributedAttempt('attempt', 'job')], 'dispatch', ['this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0]],
    'no user of its own and a dispatch of someone else' => [fn () => [attributedDispatch('job', user: '8'), attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 1, 'no_actor' => 0]],
    'no user of its own and a dispatch without a user' => [fn () => [attributedDispatch('job'), attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]],
    'no user of its own and no dispatch' => [fn () => [attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]],
    'no user of its own and the person\'s dispatch of another job' => [fn () => [attributedDispatch('other-job', user: '7'), attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]],
    'the later of two dispatches, the person\'s' => [fn () => [attributedDispatch('job', user: '8'), attributedDispatch('job', user: '7'), attributedAttempt('attempt', 'job')], 'dispatch', ['this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0]],
    'the later of two dispatches, someone else\'s' => [fn () => [attributedDispatch('job', user: '7'), attributedDispatch('job', user: '8'), attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 1, 'no_actor' => 0]],
    'the later of two dispatches, without a user' => [fn () => [attributedDispatch('job', user: '7'), attributedDispatch('job'), attributedAttempt('attempt', 'job')], null, ['this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]],
]);

it('follows one dispatch hop only, never the attempt that dispatched it', function () {
    ingest([
        attributedUser(),
        attributedDispatch('first-job', user: '7'),
        attributedAttempt('first', 'first-job', offset: 1),
        syntheticRecord(RecordType::QUEUED_JOB)->with(['job_id' => 'second-job', 'user' => '', 'execution_id' => 'first', 'execution_source' => 'job', 'timestamp' => ATTRIBUTED_AT + 2]),
        attributedAttempt('second', 'second-job', offset: 3),
    ]);

    $envelope = attributedAnswer();

    expect(attributedLinks($envelope))->toBe(['first' => 'dispatch'])
        ->and($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 2, 'this_actor' => 1, 'other_actors' => 0, 'no_actor' => 1]);
});

it('never attributes by a shared trace', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'trace', user: '7'),
        attributedDispatch('job')->with(['trace_id' => 'trace', 'execution_id' => 'trace']),
        attributedAttempt('attempt', 'job', offset: 1)->with(['trace_id' => 'trace']),
    ]);

    $envelope = attributedAnswer();

    expect(attributedLinks($envelope))->toBe(['trace' => 'direct'])
        ->and($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]);
});

it('attributes an attempt of the window by a dispatch from before the window', function () {
    ingest([attributedUser(), attributedDispatch('job', user: '7'), attributedAttempt('attempt', 'job', offset: 100)]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT + 50]);

    expect(attributedLinks($envelope))->toBe(['attempt' => 'dispatch'])
        ->and($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0])
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 1, 'this_actor' => 1, 'without_actor' => 0])
        ->and($envelope['result']['activity'])->toBe(attributedActivity(['job-attempt' => [0, 1]]));
});

it('attributes nothing by a dispatch that retention pruned, and counts the attempt as having no actor', function () {
    config()->set('firewatch.retention.age', '7d');
    registerFirewatch();
    ingest([attributedUser(), attributedDispatch('job', user: '7', offset: -8 * 86400)]);
    ingest([attributedAttempt('attempt', 'job', offset: 100)]);

    $envelope = attributedAnswer();

    expect(storeRows("SELECT count(*) AS dispatches FROM records WHERE type = 'queued-job'"))->toBe([['dispatches' => 0]])
        ->and(attributedLinks($envelope))->toBe([])
        ->and($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1])
        ->and($envelope['empty']['kind'])->toBe('no_match');
});

it('attributes nothing by a dispatch that was cleared', function () {
    ingest([attributedUser(), attributedDispatch('job', user: '7'), attributedAttempt('attempt', 'job', offset: 1)]);
    $this->artisan('firewatch:clear', ['--type' => 'queued-job', '--force' => true])->assertSuccessful();

    $envelope = attributedAnswer();

    expect($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1]);
});

it('attributes a command or a scheduled task the person acted inside of, by any child carrying them', function (RecordType $type, string $source, string $key, RecordType $child) {
    ingest([
        attributedUser(),
        attributedExecution($type, 'inside', offset: 10),
        attributedChild($child, 'inside', $source, user: '7', offset: 11),
        attributedExecution($type, 'others', offset: 20),
        attributedChild($child, 'others', $source, user: '8', offset: 21),
        attributedChild($child, 'others', $source, offset: 22),
        attributedExecution($type, 'alone', offset: 30),
    ]);

    $envelope = attributedAnswer();

    expect(attributedLinks($envelope))->toBe(['inside' => 'inside'])
        ->and($envelope['result']['attribution'][$key])->toBe(['total' => 3, 'this_actor' => 1, 'unattributable' => 2])
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 6, 'this_actor' => 1, 'without_actor' => 4])
        ->and($envelope['result']['activity'])->toBe(attributedActivity([$child->value => [1, 0]]));
})->with([
    'a command and a query' => [RecordType::COMMAND, 'command', 'commands', RecordType::QUERY],
    'a scheduled task and a log' => [RecordType::SCHEDULED_TASK, 'schedule', 'scheduled_tasks', RecordType::LOG],
]);

it('attributes a command of the window by a child that started after the window', function () {
    ingest([attributedUser(), attributedExecution(RecordType::COMMAND, 'command', offset: 10), attributedChild(RecordType::QUERY, 'command', 'command', user: '7', offset: 30)]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT, 'until' => ATTRIBUTED_AT + 20]);

    expect(attributedLinks($envelope))->toBe(['command' => 'inside'])
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 1, 'this_actor' => 0, 'without_actor' => 1]);
});

it('never puts a command inside a person by a child of another source that shares its id', function () {
    ingest([attributedUser(), attributedExecution(RecordType::COMMAND, 'shared', offset: 10), attributedChild(RecordType::QUERY, 'shared', 'request', user: '7', offset: 11)]);

    $envelope = attributedAnswer();

    expect(attributedLinks($envelope))->toBe([])
        ->and($envelope['result']['attribution']['commands'])->toBe(['total' => 1, 'this_actor' => 0, 'unattributable' => 1]);
});

it('reads an empty user and an absent one alike as no recorded user', function (RecordBuilder $request) {
    ingest([attributedUser(), $request]);

    $envelope = attributedAnswer();

    expect($envelope['result']['attribution']['requests'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 0, 'guest' => 1])
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 1, 'this_actor' => 0, 'without_actor' => 1]);
})->with([
    'empty on the wire' => [fn () => attributedExecution(RecordType::REQUEST, 'request', user: '')],
    'absent from the wire' => [fn () => attributedExecution(RecordType::REQUEST, 'request')->without('user')],
]);

it('counts the records of the window as the person\'s, someone else\'s or no one\'s, and the counts add up', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'early', offset: -10, user: '7'),
        attributedChild(RecordType::QUERY, 'early', 'request', offset: 5),
        attributedExecution(RecordType::REQUEST, 'mine', offset: 10, user: '7', fields: ['route_path' => '/orders']),
        attributedChild(RecordType::QUERY, 'mine', 'request', offset: 11),
        attributedChild(RecordType::QUERY, 'mine', 'request', user: '8', offset: 12),
        attributedExecution(RecordType::REQUEST, 'theirs', offset: 20, user: '8'),
        attributedChild(RecordType::QUERY, 'theirs', 'request', offset: 21),
        attributedExecution(RecordType::REQUEST, 'guest', offset: 30, user: ''),
        attributedChild(RecordType::QUERY, 'guest', 'request', offset: 31),
        attributedDispatch('job', user: '7', offset: 40),
        attributedAttempt('attempt', 'job', offset: 41),
        attributedChild(RecordType::LOG, 'attempt', 'job', offset: 42),
        attributedExecution(RecordType::COMMAND, 'command', offset: 50, fields: ['name' => 'orders:sync']),
        attributedChild(RecordType::QUERY, 'command', 'command', user: '7', offset: 51),
        attributedChild(RecordType::QUERY, 'command', 'command', offset: 52),
        attributedChild(RecordType::QUERY, 'nowhere', 'request', offset: 60),
    ]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT]);
    $attribution = $envelope['result']['attribution'];

    expect($attribution)->toBe(attributionCounts([
        'requests' => ['total' => 3, 'this_actor' => 1, 'other_actors' => 1, 'guest' => 1],
        'job_attempts' => ['total' => 1, 'this_actor' => 1],
        'commands' => ['total' => 1, 'this_actor' => 1],
        'records' => ['in_window' => 15, 'this_actor' => 7, 'without_actor' => 5],
    ]))
        ->and($attribution['records']['in_window'] - $attribution['records']['this_actor'] - $attribution['records']['without_actor'])->toBe(3)
        ->and($envelope['result']['activity'])->toBe(attributedActivity(['request' => [1, 0], 'job-attempt' => [0, 1], 'query' => [3, 0], 'log' => [0, 1], 'queued-job' => [1, 0]]))
        ->and($envelope['result']['executions'])->toBe([
            ['started_at' => ATTRIBUTED_AT + 50, 'type' => 'command', 'execution_id' => 'command', 'group_hash' => ATTRIBUTED_GROUP, 'label' => 'orders:sync', 'link' => 'inside'],
            ['started_at' => ATTRIBUTED_AT + 41, 'type' => 'job-attempt', 'execution_id' => 'attempt', 'group_hash' => ATTRIBUTED_GROUP, 'label' => 'Workbench\\App\\Jobs\\ShipOrder', 'link' => 'dispatch'],
            ['started_at' => ATTRIBUTED_AT + 10, 'type' => 'request', 'execution_id' => 'mine', 'group_hash' => ATTRIBUTED_GROUP, 'label' => '/orders', 'link' => 'direct'],
        ])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => 'Taylor', 'attributed' => 3, 'total' => 5, 'direct' => 1, 'dispatch' => 1, 'inside' => 1, 'unattributed' => 1]))
        ->and($envelope['notes'])->toBe([
            __('firewatch::messages.actor_commands_note', ['commands' => 1, 'tasks' => 0, 'inside' => 1]),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_caveats_note'),
        ]);
});

it('gives a record with no user of its own the class of the latest execution of its id and source', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'twice', offset: 1, user: '8'),
        attributedExecution(RecordType::REQUEST, 'twice', offset: 2, user: '7'),
        attributedChild(RecordType::QUERY, 'twice', 'request', offset: 3),
        attributedExecution(RecordType::COMMAND, 'shared', offset: 4),
        attributedExecution(RecordType::REQUEST, 'shared', offset: 5, user: '7'),
        attributedChild(RecordType::LOG, 'shared', 'command', offset: 6),
    ]);

    $envelope = attributedAnswer();

    expect($envelope['result']['activity'])->toBe(attributedActivity(['request' => [2, 0], 'query' => [1, 0]]))
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 6, 'this_actor' => 3, 'without_actor' => 2]);
});

it('lists the attributed executions newest first, the later stored first at one instant', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'first', offset: 5, user: '7'),
        attributedExecution(RecordType::REQUEST, 'second', offset: 5, user: '7'),
        attributedExecution(RecordType::REQUEST, 'oldest', offset: 1, user: '7'),
        attributedExecution(RecordType::REQUEST, 'newest', offset: 9, user: '7'),
    ]);

    $envelope = attributedAnswer();

    expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['newest', 'second', 'first', 'oldest']);
});

it('lists at most the limit of executions, and says how to see the rest when there are more', function (?int $limit, int $executions, int $listed) {
    ingest([attributedUser(), ...array_map(fn (int $number) => attributedExecution(RecordType::REQUEST, "request-{$number}", offset: $number, user: '7'), range(1, $executions))]);

    $envelope = attributedAnswer(arguments: $limit === null ? [] : ['limit' => $limit]);

    expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(array_map(fn (int $number) => "request-{$number}", range($executions, $executions - $listed + 1)))
        ->and($envelope['result']['attribution']['requests']['this_actor'])->toBe($executions)
        ->and($envelope['truncated'])->toBe($executions > $listed ? [[
            'section' => 'executions',
            'shown' => $listed,
            'matched' => null,
            'reason' => 'limit',
            'how' => trans_choice('firewatch::messages.actor_executions_how', $listed, ['listed' => $listed]),
        ]] : []);
})->with([
    'one of one' => [1, 1, 1],
    'one of two' => [1, 2, 1],
    'twenty of twenty by default' => [null, 20, 20],
    'twenty of twenty-one by default' => [null, 21, 20],
    'a hundred of a hundred' => [100, 100, 100],
    'a hundred of a hundred and one' => [100, 101, 100],
]);

it('refuses a limit that is not a whole number from 1 to 100', function (mixed $limit, string $shown) {
    $response = FirewatchServer::tool(Actor::class, ['who' => '7', 'limit' => $limit]);
    $text = (fn () => $this->content())->call($response)[0];

    expect($text)->toBe(__('firewatch::messages.invalid_argument', ['argument' => 'limit', 'expected' => '1 to 100', 'value' => $shown, 'accepted' => 'a whole number from 1 to 100', 'example' => 'actor(who: "taylor", limit: 20)']));
})->with([
    'zero' => [0, '0'],
    'a hundred and one' => [101, '101'],
    'text' => ['5', '"5"'],
    'a fraction' => [1.5, '1.5'],
    'a boolean' => [true, 'true'],
]);

it('states the commands and tasks of the window, the attempts with no actor and the requests with no recorded user, each only when there are any', function (array $records, array $notes) {
    ingest([attributedUser(), attributedExecution(RecordType::REQUEST, 'mine', user: '7'), ...$records]);

    $envelope = attributedAnswer();

    expect($envelope['notes'])->toBe([...array_map(fn (Closure $note) => $note(), $notes), __('firewatch::messages.actor_caveats_note')]);
})->with([
    'none' => [[], [fn () => __('firewatch::messages.actor_no_commands_note')]],
    'two commands and a task, one of them inside' => [
        fn () => [
            attributedExecution(RecordType::COMMAND, 'first', offset: 1),
            attributedExecution(RecordType::COMMAND, 'second', offset: 2),
            attributedExecution(RecordType::SCHEDULED_TASK, 'task', offset: 3),
            attributedChild(RecordType::LOG, 'task', 'schedule', user: '7', offset: 4),
        ],
        [fn () => __('firewatch::messages.actor_commands_note', ['commands' => 2, 'tasks' => 1, 'inside' => 1])],
    ],
    'one attempt with no actor' => [
        fn () => [attributedAttempt('attempt', 'job', offset: 1)],
        [fn () => __('firewatch::messages.actor_no_commands_note'), fn () => trans_choice('firewatch::messages.actor_no_actor_note', 1, ['count' => 1])],
    ],
    'two attempts with no actor beside one of someone else' => [
        fn () => [attributedAttempt('first', 'job', offset: 1), attributedAttempt('second', 'job', offset: 2), attributedAttempt('theirs', 'other-job', user: '8', offset: 3)],
        [fn () => __('firewatch::messages.actor_no_commands_note'), fn () => trans_choice('firewatch::messages.actor_no_actor_note', 2, ['count' => 2])],
    ],
    'one request with no recorded user' => [
        fn () => [attributedExecution(RecordType::REQUEST, 'guest', offset: 1, user: '')],
        [fn () => __('firewatch::messages.actor_no_commands_note'), fn () => trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1])],
    ],
    'two requests with no recorded user beside one of someone else' => [
        fn () => [attributedExecution(RecordType::REQUEST, 'guest', offset: 1, user: ''), attributedExecution(RecordType::REQUEST, 'another', offset: 2, user: ''), attributedExecution(RecordType::REQUEST, 'theirs', offset: 3, user: '8')],
        [fn () => __('firewatch::messages.actor_no_commands_note'), fn () => trans_choice('firewatch::messages.actor_guest_note', 2, ['count' => 2])],
    ],
]);

it('carries all five notes for a person identified from records in a window with every kind of unattributed work', function () {
    ingest([
        attributedExecution(RecordType::REQUEST, 'mine', user: '99'),
        attributedExecution(RecordType::REQUEST, 'guest', offset: 1, user: ''),
        attributedAttempt('attempt', 'job', offset: 2),
        attributedExecution(RecordType::COMMAND, 'command', offset: 3),
    ]);

    $envelope = attributedAnswer('99');

    expect($envelope['notes'])->toBe([
        __('firewatch::messages.actor_from_records_note'),
        __('firewatch::messages.actor_commands_note', ['commands' => 1, 'tasks' => 0, 'inside' => 0]),
        trans_choice('firewatch::messages.actor_no_actor_note', 1, ['count' => 1]),
        trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
        __('firewatch::messages.actor_caveats_note'),
    ])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => '99', 'attributed' => 1, 'total' => 4, 'direct' => 1, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 3]));
});

it('names the person in the summary by name, else by id, else not at all, so that the sentence is never cut', function (int $nameOver, int $idOver, string $named) {
    $room = Answer::SUMMARY_CHARACTERS - mb_strlen(__('firewatch::messages.actor_summary', ['person' => '', 'attributed' => 1, 'total' => 1, 'direct' => 1, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 0]));
    $name = 'x'.str_repeat('n', $room + $nameOver - 1);
    $id = 'i'.str_repeat('d', $room + $idOver - 1);
    ingest([attributedUser($id, $name), attributedExecution(RecordType::REQUEST, 'mine', user: $id)]);

    $envelope = attributedAnswer('x');
    $counts = ['attributed' => 1, 'total' => 1, 'direct' => 1, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 0];

    expect($envelope['summary'])->toBe(match ($named) {
        'name' => __('firewatch::messages.actor_summary', ['person' => $name, ...$counts]),
        'id' => __('firewatch::messages.actor_summary', ['person' => $id, ...$counts]),
        'nobody' => __('firewatch::messages.actor_summary_without_person', $counts),
    })
        ->and(mb_strlen($envelope['summary']))->toBeLessThanOrEqual(Answer::SUMMARY_CHARACTERS);
})->with([
    'a name of exactly the room' => [0, 0, 'name'],
    'a name one over and an id of exactly the room' => [1, 0, 'id'],
    'a name and an id one over' => [1, 1, 'nobody'],
]);

it('answers that nothing in the window is attributed to the person, with the counts that say so', function () {
    ingest([attributedUser(), attributedExecution(RecordType::REQUEST, 'theirs', user: '8'), attributedExecution(RecordType::COMMAND, 'command', offset: 1)]);

    $envelope = attributedAnswer('taylor');

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => 2, 'message' => __('firewatch::messages.actor_nothing_attributed', ['person' => 'Taylor', 'population' => 2])])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => 'Taylor', 'attributed' => 0, 'total' => 2, 'direct' => 0, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 1]))
        ->and($envelope['result'])->toBe([
            'identity' => ['id' => '7', 'name' => 'Taylor', 'username' => null, 'first_seen_at' => ATTRIBUTED_AT, 'last_seen_at' => ATTRIBUTED_AT, 'matched_by' => 'name'],
            'attribution' => attributionCounts([
                'requests' => ['total' => 1, 'other_actors' => 1],
                'commands' => ['total' => 1, 'unattributable' => 1],
                'records' => ['in_window' => 2, 'without_actor' => 1],
            ]),
            'activity' => attributedActivity(),
            'executions' => [],
        ])
        ->and($envelope['truncated'])->toBe([])
        ->and($envelope['next'])->toBe([]);
});

it('offers the person\'s own records when no execution is attributed to them', function () {
    ingest([attributedUser(), attributedExecution(RecordType::REQUEST, 'guest', user: ''), attributedDispatch('job', user: '7', offset: 1)->with(['execution_id' => 'guest'])]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT - 1]);
    $listed = Envelope::assert(Occurrences::class, $envelope['next'][0]['arguments']);

    expect($envelope['empty']['kind'])->toBe('no_match')
        ->and($envelope['result']['activity'])->toBe(attributedActivity(['queued-job' => [1, 0]]))
        ->and($envelope['next'])->toBe([['tool' => 'occurrences', 'arguments' => ['user_id' => '7', 'since' => ATTRIBUTED_AT - 1], 'why' => __('firewatch::messages.actor_next_user')]])
        ->and(array_column($listed['result']['rows'], 'type'))->toBe(['queued-job']);
});

it('answers that the window holds no execution, with the whole identity and the counts of nothing', function () {
    ingest([attributedUser(), attributedExecution(RecordType::REQUEST, 'mine', user: '7'), attributedChild(RecordType::QUERY, 'mine', 'request', offset: 30)]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT + 10]);

    expect($envelope['empty'])->toBe(['kind' => 'window_empty', 'population' => 2, 'message' => __('firewatch::messages.actor_no_executions', ['population' => 2])])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => 'Taylor', 'attributed' => 0, 'total' => 0, 'direct' => 0, 'dispatch' => 0, 'inside' => 0, 'unattributed' => 0]))
        ->and($envelope['result'])->toBe([
            'identity' => ['id' => '7', 'name' => 'Taylor', 'username' => null, 'first_seen_at' => ATTRIBUTED_AT, 'last_seen_at' => ATTRIBUTED_AT, 'matched_by' => 'id'],
            'attribution' => attributionCounts(['records' => ['in_window' => 1, 'this_actor' => 1]]),
            'activity' => attributedActivity(['query' => [1, 0]]),
            'executions' => [],
        ])
        ->and($envelope['notes'])->toBe([__('firewatch::messages.actor_no_commands_note'), __('firewatch::messages.actor_caveats_note')])
        ->and($envelope['next'])->toBe([]);
});

it('selects the executions and records of the window by their own start, the start included and the end excluded', function () {
    ingest([
        attributedUser(),
        attributedExecution(RecordType::REQUEST, 'before', offset: 9.999, user: '7'),
        attributedExecution(RecordType::REQUEST, 'at-since', offset: 10, user: '7'),
        attributedExecution(RecordType::REQUEST, 'inside', offset: 19.999, user: '7'),
        attributedExecution(RecordType::REQUEST, 'at-until', offset: 20, user: '7'),
    ]);

    $envelope = attributedAnswer(arguments: ['since' => ATTRIBUTED_AT + 10, 'until' => ATTRIBUTED_AT + 20]);

    expect(array_column($envelope['result']['executions'], 'execution_id'))->toBe(['inside', 'at-since'])
        ->and($envelope['result']['attribution']['requests'])->toBe(['total' => 2, 'this_actor' => 2, 'other_actors' => 0, 'guest' => 0])
        ->and($envelope['result']['attribution']['records'])->toBe(['in_window' => 2, 'this_actor' => 2, 'without_actor' => 0])
        ->and($envelope['window'])->toMatchArray(['since' => ATTRIBUTED_AT + 10, 'until' => ATTRIBUTED_AT + 20]);
});

it('offers calls that read the same window after the clock moves on', function () {
    ingest([attributedUser(), attributedExecution(RecordType::REQUEST, 'mine', offset: 10, user: '7'), attributedExecution(RecordType::REQUEST, 'earlier', offset: -10, user: '7')]);

    $envelope = attributedAnswer(arguments: ['since' => '-1h']);
    $this->travel(2)->hours();
    $tools = ['execution' => Execution::class, 'occurrences' => Occurrences::class];
    $answers = array_map(fn (array $call) => Envelope::assert($tools[$call['tool']], $call['arguments']), $envelope['next']);

    expect($envelope['next'])->toBe([
        ['tool' => 'execution', 'arguments' => ['execution_id' => 'mine'], 'why' => __('firewatch::messages.actor_next_execution')],
        ['tool' => 'occurrences', 'arguments' => ['group' => ATTRIBUTED_GROUP, 'since' => ATTRIBUTED_AT], 'why' => __('firewatch::messages.actor_next_group')],
        ['tool' => 'occurrences', 'arguments' => ['user_id' => '7', 'since' => ATTRIBUTED_AT], 'why' => __('firewatch::messages.actor_next_user')],
    ])
        ->and(array_column($answers, 'empty'))->toBe([null, null, null])
        ->and($answers[0]['result']['header'])->toMatchArray(['execution_id' => 'mine', 'user_id' => '7'])
        ->and(array_column($answers[1]['result']['rows'], 'execution_id'))->toBe(['mine'])
        ->and(array_column($answers[2]['result']['rows'], 'execution_id'))->toBe(['mine'])
        ->and($answers[2]['notes'])->toBe([__('firewatch::messages.occurrences_user_only')]);
});
