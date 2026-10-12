<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Tests\Support\Answers;
use Laravel\Nightwatch\Facades\Nightwatch;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * Run the workbench command that signs a member in, as the worker of a parallel run with the token or as a serial run with none.
 *
 * Its query carries the member, and only a request puts a user in the user directory.
 */
function actorWorkerAudit(?string $token, string $member): void
{
    runAsWorker($token);
    captureTests();
    app()->register(WorkbenchServiceProvider::class);

    test()->artisan('members:audit', ['member' => $member])->assertSuccessful();

    Nightwatch::digest();
}

/**
 * Sign member 7 in on a request, as the worker of a parallel run with the token or as a serial run with none.
 */
function actorWorkerVisit(?string $token): void
{
    runAsWorker($token);
    captureTests();
    app()->register(WorkbenchServiceProvider::class);

    test()->get('/members/7')->assertOk();

    Nightwatch::digest();
}

it('reaches the person of no worker by id alone, and the person of a worker by id and worker', function () {
    actorWorkerVisit(null);
    actorWorkerVisit('1');

    $alone = Answers::assert(Actor::class, ['who' => '7']);
    $inWorker = Answers::assert(Actor::class, ['who' => '7', 'worker' => '1']);
    $elsewhere = Answers::assert(Actor::class, ['who' => '7', 'worker' => '2']);

    expect(array_keys($alone['result']['identity']))->toBe(['id', 'name', 'username', 'first_seen_at', 'last_seen_at', 'matched_by'])
        ->and($alone['result']['identity'])->toMatchArray(['id' => '7', 'name' => 'Taylor Otwell', 'matched_by' => 'id'])
        ->and($alone['result']['attribution']['requests'])->toBe(['total' => 2, 'this_actor' => 1, 'other_actors' => 1, 'guest' => 0])
        ->and(array_column($alone['next'], 'arguments'))->toContain(['user_id' => '7'])
        ->and($inWorker['result']['identity'])->toMatchArray(['id' => '7', 'worker' => '1', 'name' => 'Taylor Otwell', 'matched_by' => 'id'])
        ->and($inWorker['result']['attribution']['requests'])->toBe(['total' => 2, 'this_actor' => 1, 'other_actors' => 1, 'guest' => 0])
        ->and(array_column($inWorker['next'], 'arguments'))->toContain(['user_id' => '7', 'worker' => '1'])
        ->and($elsewhere['empty']['kind'])->toBe('no_match')
        ->and(array_column($elsewhere['result']['known_actors'], 'worker'))->toEqualCanonicalizing([null, '1']);
});

it('says in one note that an id only records hold is several actors', function () {
    actorWorkerAudit(null, member: '9');
    actorWorkerAudit('1', member: '9');

    $alone = Answers::assert(Actor::class, ['who' => '9']);

    expect($alone['notes'][0])->toBe(__('firewatch::messages.actor_from_records_note').' '.__('firewatch::messages.user_id_workers_note', ['count' => 2, 'user_id' => '9']))
        ->and(count($alone['notes']))->toBeLessThanOrEqual(5);
});

it('finds one person per worker for an id only records hold, and offers each by id and worker', function () {
    actorWorkerAudit('1', member: '9');
    actorWorkerAudit('2', member: '9');

    $ambiguous = Answers::assert(Actor::class, ['who' => '9']);
    $second = Answers::follow($ambiguous['next'][1]);

    expect($ambiguous['result'])->toBe([
        'matched_by' => 'records',
        'candidate_count' => 2,
        'candidates' => [
            ['id' => '9', 'worker' => '1', 'name' => null, 'username' => null, 'last_seen_at' => null],
            ['id' => '9', 'worker' => '2', 'name' => null, 'username' => null, 'last_seen_at' => null],
        ],
    ])->and(array_column($ambiguous['next'], 'arguments'))->toBe([['who' => '9', 'worker' => '1'], ['who' => '9', 'worker' => '2']])
        ->and($second['result']['identity'])->toBe(['id' => '9', 'worker' => '2', 'name' => null, 'username' => null, 'first_seen_at' => null, 'last_seen_at' => null, 'matched_by' => 'records'])
        ->and($second['result']['attribution']['commands'])->toBe(['total' => 2, 'this_actor' => 1, 'unattributable' => 1])
        ->and($second['notes'][0])->toBe(__('firewatch::messages.actor_from_records_note'));
});

it('decides on the records of no worker before it looks across workers', function () {
    actorWorkerAudit(null, member: '9');
    actorWorkerAudit('1', member: '9');

    $answer = Answers::assert(Actor::class, ['who' => '9']);

    expect($answer['result']['identity'])->toBe(['id' => '9', 'name' => null, 'username' => null, 'first_seen_at' => null, 'last_seen_at' => null, 'matched_by' => 'records'])
        ->and($answer['result']['attribution']['commands'])->toBe(['total' => 2, 'this_actor' => 1, 'unattributable' => 1]);
});

it('counts the workers that hold a user id up to a hundred, and says when there are more', function (int $workers, string $key) {
    foreach (range(1, $workers) as $token) {
        runAsWorker((string) $token);
        captureTests();

        $this->get('/members/7')->assertOk();

        Nightwatch::digest();
    }

    $answer = Answers::assert(Occurrences::class, ['user_id' => '7', 'limit' => 1]);

    expect($answer['notes'])->toBe([
        __('firewatch::messages.occurrences_user_only'),
        __("firewatch::messages.{$key}", ['user_id' => '7', 'count' => 100]),
    ]);
})->with([
    'a hundred workers' => [100, 'user_id_workers_note'],
    'one more' => [101, 'user_id_workers_more_note'],
]);
