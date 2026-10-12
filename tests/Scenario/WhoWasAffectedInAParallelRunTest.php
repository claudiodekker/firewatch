<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Tests\Support\Answers;

/**
 * Run an application's two feature tests as workers 1 and 2 of a parallel run: both sign in id 7, which is Taylor Otwell in the database of worker 1 and Taylor Swift in that of worker 2.
 */
function parallelRun(): void
{
    runFixtureSuite('MembersTest', worker: '1');
    runFixtureSuite('MembersTest', worker: '2');
}

/**
 * Let a guest and two members browse the workbench outside any test run.
 */
function parallelRunBrowsing(): void
{
    foreach (['/', '/members/8', '/members/7'] as $uri) {
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        test()->get($uri)->assertOk();
    }
}

/**
 * Get the execution ids a run lists for each worker.
 *
 * @return array<string, list<string>>
 */
function parallelRunExecutions(): array
{
    $rows = Answers::assert(Occurrences::class, ['order' => 'run'])['result']['rows'];
    $executions = [];

    foreach ($rows as $row) {
        $executions[$row['worker']][] = $row['execution_id'];
    }

    return $executions;
}

it('counts the same id under two workers as two signed-in actors, on the overview and in what a finding reaches', function () {
    parallelRun();

    $overview = Answers::assert(Overview::class);
    $detected = Answers::assert(Detect::class, ['shape' => 'failing-routes']);

    expect($overview['result']['user_directory'])->toBe(2)
        ->and($overview['result']['actors'])->toBe(['executions' => 4, 'signed_in_actors' => 2, 'without_actor' => 0])
        ->and($detected['result']['findings'])->toHaveCount(1)
        ->and($detected['result']['findings'][0])->toMatchArray([
            'name' => '/members/{member}/invoice',
            'count' => 2,
            'reaches' => ['signed_in_actors' => 2, 'without_actor' => 0],
        ]);
})->group('process');

it('lists the people an id belongs to with their workers, and names one person per worker with only that worker\'s work', function () {
    parallelRun();

    $executions = parallelRunExecutions();
    $ambiguous = Answers::assert(Actor::class, ['who' => '7']);
    // PHP 8.6.0RC3 compiles array_map(Answers::follow(...), ...) without the file's imports and looks for a global Answers.
    $followed = array_map(fn (array $call) => Answers::follow($call), $ambiguous['next']);
    $people = array_combine(array_map(fn (array $answer) => $answer['result']['identity']['worker'], $followed), $followed);

    expect($ambiguous['result'])->toBe([
        'matched_by' => 'id',
        'candidate_count' => 2,
        'candidates' => [
            ['id' => '7', 'worker' => '2', 'name' => 'Taylor Swift', 'username' => 'swift@example.com', 'last_seen_at' => $ambiguous['result']['candidates'][0]['last_seen_at']],
            ['id' => '7', 'worker' => '1', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'last_seen_at' => $ambiguous['result']['candidates'][1]['last_seen_at']],
        ],
    ])->and($ambiguous['notes'])->toBe([__('firewatch::messages.actor_ambiguous_note'), __('firewatch::messages.actor_ambiguous_workers_note')])
        ->and($ambiguous['next'])->toBe([
            ['tool' => 'actor', 'arguments' => ['who' => '7', 'worker' => '2'], 'why' => __('firewatch::messages.actor_next_candidate')],
            ['tool' => 'actor', 'arguments' => ['who' => '7', 'worker' => '1'], 'why' => __('firewatch::messages.actor_next_candidate')],
        ]);

    foreach (['1' => 'Taylor Otwell', '2' => 'Taylor Swift'] as $worker => $name) {
        $answer = $people[$worker];
        $listed = Answers::follow($answer['next'][2]);

        expect(array_keys($answer['result']['identity']))->toBe(['id', 'worker', 'name', 'username', 'first_seen_at', 'last_seen_at', 'matched_by'])
            ->and($answer['result']['identity'])->toMatchArray(['id' => '7', 'worker' => (string) $worker, 'name' => $name, 'matched_by' => 'id'])
            ->and($answer['result']['attribution']['requests'])->toBe(['total' => 4, 'this_actor' => 2, 'other_actors' => 2, 'guest' => 0])
            ->and($answer['result']['attribution']['records']['this_actor'])->toBe(intdiv($answer['result']['attribution']['records']['in_window'], 2))
            ->and(array_column($answer['result']['executions'], 'execution_id'))->toEqualCanonicalizing($executions[$worker])
            ->and(array_column($answer['result']['executions'], 'link'))->toBe(['direct', 'direct'])
            ->and($answer['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => $name, 'attributed' => 2, 'total' => 4, 'direct' => 2, 'dispatch' => 0, 'inside' => 0, 'unattributable' => 0]))
            ->and($answer['next'][2])->toBe(['tool' => 'occurrences', 'arguments' => ['user_id' => '7', 'worker' => (string) $worker], 'why' => __('firewatch::messages.actor_next_user')])
            ->and(array_values(array_unique(array_column($listed['result']['rows'], 'execution_id'))))->toEqualCanonicalizing($executions[$worker])
            ->and($listed['result']['rows'])->toHaveCount($answer['result']['attribution']['records']['this_actor']);
    }
})->group('process');

it('finds the one person a username or a name belongs to, whatever their worker', function () {
    parallelRun();

    $swift = Answers::assert(Actor::class, ['who' => 'swift@example.com']);
    $taylors = Answers::assert(Actor::class, ['who' => 'taylor']);
    $otwell = Answers::assert(Actor::class, ['who' => 'Taylor Otwell', 'worker' => '1']);

    expect($swift['result']['identity'])->toMatchArray(['id' => '7', 'worker' => '2', 'name' => 'Taylor Swift', 'matched_by' => 'username'])
        ->and($swift['result']['attribution']['requests'])->toBe(['total' => 4, 'this_actor' => 2, 'other_actors' => 2, 'guest' => 0])
        ->and($taylors['result']['matched_by'])->toBe('contains')
        ->and(array_column($taylors['result']['candidates'], 'worker'))->toBe(['2', '1'])
        ->and($otwell['result']['identity'])->toMatchArray(['id' => '7', 'worker' => '1', 'matched_by' => 'name']);
})->group('process');

it('lists only the records of the actor a user id and a worker name', function () {
    parallelRun();

    $executions = parallelRunExecutions();
    $everyone = Answers::assert(Occurrences::class, ['user_id' => '7']);
    $second = Answers::assert(Occurrences::class, ['user_id' => '7', 'worker' => '2']);
    $requests = Answers::assert(Occurrences::class, ['user_id' => '7', 'worker' => '2', 'type' => 'request', 'limit' => 1]);
    $nobody = Answers::assert(Occurrences::class, ['user_id' => '7', 'worker' => '3']);

    preg_match('/cursor: "([^"]+)"/', $requests['truncated'][0]['how'], $cursor);

    $next = Answers::assert(Occurrences::class, ['user_id' => '7', 'worker' => '2', 'type' => 'request', 'limit' => 1, 'cursor' => $cursor[1]]);

    expect(count($everyone['result']['rows']))->toBe(2 * count($second['result']['rows']))
        ->and(array_values(array_unique(array_column($second['result']['rows'], 'execution_id'))))->toEqualCanonicalizing($executions['2'])
        ->and($second['notes'])->toBe([__('firewatch::messages.occurrences_user_only')])
        ->and($requests['truncated'][0]['how'])->toContain('user_id: "7"', 'worker: "2"')
        ->and([$requests['result']['rows'][0]['execution_id'], $next['result']['rows'][0]['execution_id']])->toEqualCanonicalizing($executions['2'])
        ->and($next['truncated'])->toBe([])
        ->and($nobody['empty'])->toBe([
            'kind' => 'no_match',
            'population' => $everyone['coverage']['records'],
            'message' => __('firewatch::messages.no_match', ['population' => $everyone['coverage']['records'], 'filters' => 'user_id: 7, worker: 3']),
        ]);
})->group('process');

it('says how many workers hold a user id that a call reads without a worker, and only then', function () {
    parallelRun();

    $acrossWorkers = Answers::assert(Occurrences::class, ['user_id' => '7']);
    $ofOneWorker = Answers::assert(Occurrences::class, ['user_id' => '7', 'worker' => '2']);
    $byUsername = Answers::assert(Actor::class, ['who' => 'swift@example.com']);

    parallelRunBrowsing();

    $alone = Answers::assert(Actor::class, ['who' => '7']);
    $listed = Answers::assert(Occurrences::class, ['user_id' => '7']);
    $inWorker = Answers::assert(Actor::class, ['who' => '7', 'worker' => '1']);
    $other = Answers::assert(Actor::class, ['who' => '8']);
    $otherListed = Answers::assert(Occurrences::class, ['user_id' => '8']);

    $twoWorkers = __('firewatch::messages.user_id_workers_note', ['user_id' => '7', 'count' => 2]);
    $threeWorkers = __('firewatch::messages.user_id_workers_note', ['user_id' => '7', 'count' => 3]);

    expect($acrossWorkers['notes'])->toBe([__('firewatch::messages.occurrences_user_only'), $twoWorkers])
        ->and($ofOneWorker['notes'])->toBe([__('firewatch::messages.occurrences_user_only')])
        ->and($byUsername['result']['identity'])->toMatchArray(['id' => '7', 'worker' => '2', 'matched_by' => 'username'])
        ->and($byUsername['notes'])->toBe([$twoWorkers, __('firewatch::messages.actor_no_commands_note'), __('firewatch::messages.actor_identity_note')])
        ->and($alone['result']['identity'])->toBe(['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'first_seen_at' => $alone['result']['identity']['first_seen_at'], 'last_seen_at' => $alone['result']['identity']['last_seen_at'], 'matched_by' => 'id'])
        ->and($alone['result']['attribution']['requests'])->toBe(['total' => 7, 'this_actor' => 1, 'other_actors' => 5, 'guest' => 1])
        ->and($alone['notes'])->toBe([
            $threeWorkers,
            __('firewatch::messages.actor_no_commands_note'),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_identity_note'),
        ])
        ->and($listed['notes'])->toBe([__('firewatch::messages.occurrences_user_only'), $threeWorkers])
        ->and(array_keys($listed['result']['rows'][0]))->not->toContain('worker')
        ->and($listed['result']['rows'])->toHaveCount(count($acrossWorkers['result']['rows']) + 1)
        ->and($inWorker['result']['identity'])->toMatchArray(['id' => '7', 'worker' => '1', 'matched_by' => 'id'])
        ->and($inWorker['notes'])->not->toContain($threeWorkers)
        ->and($other['notes'])->toBe([
            __('firewatch::messages.actor_no_commands_note'),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_identity_note'),
        ])
        ->and($otherListed['notes'])->toBe([__('firewatch::messages.occurrences_user_only')]);
})->group('process');

it('finds no one under a worker that holds nobody, and still states what it cannot see', function () {
    parallelRun();

    $answer = Answers::assert(Actor::class, ['who' => '7', 'worker' => '3']);

    expect($answer['summary'])->toBe(__('firewatch::messages.actor_unknown_summary', ['who' => '7']))
        ->and($answer['empty'])->toBe(['kind' => 'no_match', 'population' => 2, 'message' => __('firewatch::messages.actor_unknown', ['who' => '7', 'population' => 2])])
        ->and(array_keys($answer['result']))->toBe(['known_actors', 'known_actor_count'])
        ->and($answer['result']['known_actor_count'])->toBe(2)
        ->and(array_map(fn (array $person) => [$person['id'], $person['worker'], $person['name']], $answer['result']['known_actors']))->toBe([
            ['7', '2', 'Taylor Swift'],
            ['7', '1', 'Taylor Otwell'],
        ])
        ->and($answer['notes'])->toBe([__('firewatch::messages.actor_unknown_note')])
        ->and($answer['next'])->toBe([])
        ->and(array_column($answer['blind_spots'], 'message', 'id'))->toMatchArray(['actor-partial' => __('firewatch::messages.blind_spots.actor-partial')]);
})->group('process');

it('answers as before in a store that holds no test run', function () {
    parallelRunBrowsing();

    $overview = Answers::assert(Overview::class);
    $ambiguous = Answers::assert(Actor::class, ['who' => 'taylor']);
    $answer = Answers::assert(Actor::class, ['who' => '7']);
    $nobody = Answers::assert(Actor::class, ['who' => 'nuno']);
    $listed = Answers::assert(Occurrences::class, ['user_id' => '7']);
    $identity = $answer['result']['identity'];
    $execution = $answer['result']['executions'][0];

    expect($overview['result']['user_directory'])->toBe(2)
        ->and($overview['result']['actors'])->toBe(['executions' => 3, 'signed_in_actors' => 2, 'without_actor' => 1])
        ->and($ambiguous['result'])->toBe([
            'matched_by' => 'contains',
            'candidate_count' => 2,
            'candidates' => [
                ['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'last_seen_at' => $identity['last_seen_at']],
                ['id' => '8', 'name' => 'Taylor Swift', 'username' => 'swift@example.com', 'last_seen_at' => $ambiguous['result']['candidates'][1]['last_seen_at']],
            ],
        ])
        ->and($ambiguous['notes'])->toBe([__('firewatch::messages.actor_ambiguous_note')])
        ->and($ambiguous['next'])->toBe([])
        ->and($answer['result'])->toBe([
            'identity' => ['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'first_seen_at' => $identity['first_seen_at'], 'last_seen_at' => $identity['last_seen_at'], 'matched_by' => 'id'],
            'attribution' => [
                'requests' => ['total' => 3, 'this_actor' => 1, 'other_actors' => 1, 'guest' => 1],
                'job_attempts' => ['total' => 0, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 0],
                'commands' => ['total' => 0, 'this_actor' => 0, 'unattributable' => 0],
                'scheduled_tasks' => ['total' => 0, 'this_actor' => 0, 'unattributable' => 0],
                'records' => ['in_window' => 3, 'this_actor' => 1, 'without_actor' => 1],
            ],
            'activity' => $answer['result']['activity'],
            'executions' => [
                ['started_at' => $execution['started_at'], 'type' => 'request', 'execution_id' => $execution['execution_id'], 'group_hash' => $execution['group_hash'], 'label' => '/members/{member}', 'link' => 'direct'],
            ],
        ])
        ->and($answer['next'])->toBe([
            ['tool' => 'execution', 'arguments' => ['execution_id' => $execution['execution_id']], 'why' => __('firewatch::messages.actor_next_execution')],
            ['tool' => 'occurrences', 'arguments' => ['group' => $execution['group_hash']], 'why' => __('firewatch::messages.actor_next_group')],
            ['tool' => 'occurrences', 'arguments' => ['user_id' => '7'], 'why' => __('firewatch::messages.actor_next_user')],
        ])
        ->and($nobody['result'])->toBe([
            'known_actors' => $ambiguous['result']['candidates'],
            'known_actor_count' => 2,
        ])
        ->and(array_map(fn (array $row) => [$row['type'], $row['execution_id'], $row['user_id']], $listed['result']['rows']))->toBe([
            ['request', $execution['execution_id'], '7'],
        ])
        ->and(array_keys($listed['result']['rows'][0]))->toBe(['started_at', 'type', 'source', 'stage', 'duration_ms', 'execution_id', 'trace_id', 'group', 'name', 'location', 'user_id', 'deploy', 'detail'])
        ->and($listed['notes'])->toBe([__('firewatch::messages.occurrences_user_only')]);
});

it('refuses a worker that names no actor', function (array $arguments, string $refusal) {
    FirewatchServer::tool(Occurrences::class, $arguments)->assertHasErrors([$refusal]);
})->with([
    'without a user id' => fn () => [
        ['type' => 'request', 'worker' => '2'],
        __('firewatch::messages.missing_argument', ['argument' => 'user_id', 'accepted' => __('firewatch::messages.argument_values.worker_actor'), 'example' => 'occurrences(user_id: "7", worker: "2")']),
    ],
    'when it is empty' => fn () => [
        ['user_id' => '7', 'worker' => ''],
        __('firewatch::messages.invalid_argument', ['argument' => 'worker', 'expected' => __('firewatch::messages.argument_values.worker'), 'value' => '""', 'accepted' => __('firewatch::messages.argument_values.worker'), 'example' => 'occurrences(user_id: "7", worker: "2")']),
    ],
    'beside a run' => fn () => [
        ['order' => 'run', 'worker' => '2'],
        __('firewatch::messages.conflicting_arguments', ['argument' => 'worker', 'with' => 'order', 'accepted' => __('firewatch::messages.occurrences_run_accepted'), 'example' => 'occurrences(order: "run")']),
    ],
]);

it('refuses a worker that is empty or no string on the actor', function (mixed $worker) {
    $name = __('firewatch::messages.argument_values.worker');

    FirewatchServer::tool(Actor::class, ['who' => '7', 'worker' => $worker])->assertHasErrors([__('firewatch::messages.invalid_argument', [
        'argument' => 'worker',
        'expected' => $name,
        'value' => json_encode($worker),
        'accepted' => $name,
        'example' => 'actor(who: "7", worker: "2")',
    ])]);
})->with([
    'an empty one' => [''],
    'a number' => [2],
]);
