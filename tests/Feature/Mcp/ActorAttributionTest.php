<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
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
