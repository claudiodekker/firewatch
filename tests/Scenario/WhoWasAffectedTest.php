<?php

use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Providers\WorkbenchServiceProvider;

beforeEach(function () {
    $this->queue = sys_get_temp_dir().'/firewatch-tests/queue-'.bin2hex(random_bytes(8)).'.sqlite';

    @mkdir(dirname($this->queue), recursive: true);
    touch($this->queue);
});

afterEach(function () {
    @unlink($this->queue);
});

/**
 * Point the application's queue at a table in a file that every application of the test shares, as the processes of a real application share their queue.
 */
function whoWasAffectedQueue(string $queue): void
{
    config()->set('database.connections.queue', ['driver' => 'sqlite', 'database' => $queue, 'foreign_key_constraints' => false]);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'queue');
    config()->set('queue.failed.driver', 'null');

    if (! Schema::connection('queue')->hasTable('jobs')) {
        Schema::connection('queue')->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}

/**
 * Serve each request of the workbench in an application of its own, so that each is an execution of its own.
 *
 * @param  list<string>  $uris
 */
function whoWasAffectedRequests(string $queue, array $uris): void
{
    foreach ($uris as $uri) {
        forceRequests();
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        whoWasAffectedQueue($queue);

        test()->get($uri)->assertOk();
    }
}

/**
 * Run each console command in an application of its own, as a worker or a terminal would.
 *
 * @param  list<array<string, mixed>>  $commands
 */
function whoWasAffectedCommands(string $queue, array $commands): void
{
    // A forced request would turn the console into a request.
    unset($_SERVER['NIGHTWATCH_FORCE_REQUEST'], $_ENV['NIGHTWATCH_FORCE_REQUEST']);
    putenv('NIGHTWATCH_FORCE_REQUEST');

    foreach ($commands as $command) {
        test()->refreshApplication();
        app()->register(WorkbenchServiceProvider::class);
        whoWasAffectedQueue($queue);

        runArtisan($command);
    }
}

/**
 * Let a guest and two members use the workbench: one member signs in, orders after a dispatch that went out before they signed in, and is audited from the console beside a command that touches no one.
 */
function whoWasAffectedTraffic(string $queue): void
{
    whoWasAffectedRequests($queue, ['/', '/members/8', '/members/7', '/members/7/orders']);
    whoWasAffectedCommands($queue, [
        ['command' => 'queue:work', '--once' => true],
        ['command' => 'members:audit', 'member' => '7'],
        ['command' => 'env'],
    ]);
}

/**
 * Run a call an answer offers and get what it answers.
 *
 * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
 * @return array<string, mixed>
 */
function whoWasAffectedFollow(array $call): array
{
    $tool = ['actor' => Actor::class, 'execution' => Execution::class, 'occurrences' => Occurrences::class][$call['tool']];

    return Envelope::assert($tool, $call['arguments']);
}

it('says who was affected: the person meant, the work tied to them by each link, and what no link reaches', function () {
    whoWasAffectedTraffic($this->queue);

    $ambiguous = Envelope::assert(Actor::class, ['who' => 'taylor']);
    $envelope = Envelope::assert(Actor::class, ['who' => $ambiguous['result']['candidates'][0]['id']]);
    $result = $envelope['result'];
    $executions = $result['executions'];
    $activity = array_column($result['activity'], null, 'type');
    $followed = array_map(whoWasAffectedFollow(...), $envelope['next']);

    expect($ambiguous['result']['matched_by'])->toBe('contains')
        ->and(array_column($ambiguous['result']['candidates'], 'id'))->toBe(['7', '8'])
        ->and($ambiguous['notes'])->toBe([__('firewatch::messages.actor_ambiguous_note')])
        ->and($envelope['empty'])->toBeNull()
        ->and(array_keys($result))->toBe(['identity', 'attribution', 'activity', 'executions'])
        ->and($result['identity'])->toMatchArray(['id' => '7', 'name' => 'Taylor Otwell', 'username' => 'taylor@example.com', 'matched_by' => 'id'])
        ->and($result['attribution']['requests'])->toBe(['total' => 4, 'this_actor' => 2, 'other_actors' => 1, 'guest' => 1])
        ->and($result['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 1, 'other_actors' => 0, 'no_actor' => 0])
        ->and($result['attribution']['commands'])->toBe(['total' => 2, 'this_actor' => 1, 'unattributable' => 1])
        ->and($result['attribution']['scheduled_tasks'])->toBe(['total' => 0, 'this_actor' => 0, 'unattributable' => 0])
        ->and($result['attribution']['records']['this_actor'])->toBe(array_sum(array_column($result['activity'], 'direct')) + array_sum(array_column($result['activity'], 'dispatch')))
        ->and($result['attribution']['records']['in_window'])->toBe($envelope['coverage']['records'])
        ->and($activity['request'])->toBe(['type' => 'request', 'direct' => 2, 'dispatch' => 0, 'can_carry_actor' => true])
        ->and($activity['job-attempt'])->toBe(['type' => 'job-attempt', 'direct' => 0, 'dispatch' => 1, 'can_carry_actor' => true])
        ->and($activity['queued-job'])->toBe(['type' => 'queued-job', 'direct' => 1, 'dispatch' => 0, 'can_carry_actor' => true])
        ->and($activity['command'])->toBe(['type' => 'command', 'direct' => 0, 'dispatch' => 0, 'can_carry_actor' => false])
        ->and($activity['query']['direct'])->toBeGreaterThanOrEqual(1)
        ->and($activity['query']['dispatch'])->toBeGreaterThanOrEqual(1)
        ->and(array_values(Arr::sort(array_map(fn (array $execution) => "{$execution['link']} {$execution['type']} {$execution['label']}", $executions))))->toBe([
            'direct request /members/{member}',
            'direct request /members/{member}/orders',
            'dispatch job-attempt Workbench\\App\\Jobs\\ShipOrder',
            'inside command members:audit',
        ])
        ->and(array_column($executions, 'started_at'))->toBe(array_values(Arr::sortDesc(array_column($executions, 'started_at'))))
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => 'Taylor Otwell', 'attributed' => 4, 'total' => 7, 'direct' => 2, 'dispatch' => 1, 'inside' => 1, 'unattributed' => 2]))
        ->and($envelope['notes'])->toBe([
            __('firewatch::messages.actor_commands_note', ['commands' => 2, 'tasks' => 0, 'inside' => 1]),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_caveats_note'),
        ])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial')
        ->and(array_column($envelope['next'], 'tool'))->toBe(['execution', 'occurrences', 'occurrences'])
        ->and($followed[0]['result']['header'])->toMatchArray(['type' => $executions[0]['type'], 'execution_id' => $executions[0]['execution_id'], 'label' => $executions[0]['label']])
        ->and(array_unique(array_column($followed[1]['result']['rows'], 'group')))->toBe([$executions[0]['group_hash']])
        ->and(array_unique(array_column($followed[2]['result']['rows'], 'user_id')))->toBe(['7'])
        ->and($followed[2]['notes'])->toBe([__('firewatch::messages.occurrences_user_only')])
        ->and(array_column($followed[2]['blind_spots'], 'id'))->toContain('actor-partial');
});

it('attributes nothing to a person who did nothing in the window, and finds no one who never acted', function () {
    whoWasAffectedTraffic($this->queue);

    $taylor = Envelope::assert(Actor::class, ['who' => '7']);
    $attempt = array_values(array_filter($taylor['result']['executions'], fn (array $execution) => $execution['link'] === 'dispatch'))[0];
    $envelope = Envelope::assert(Actor::class, ['who' => 'swift@example.com', 'since' => $attempt['started_at']]);
    $nobody = Envelope::assert(Actor::class, ['who' => 'nuno']);
    $attribution = $envelope['result']['attribution'];
    $total = $attribution['requests']['total'] + $attribution['job_attempts']['total'] + $attribution['commands']['total'] + $attribution['scheduled_tasks']['total'];

    expect($envelope['empty'])->toBe(['kind' => 'no_match', 'population' => $total, 'message' => __('firewatch::messages.actor_nothing_attributed', ['person' => 'Taylor Swift', 'population' => $total])])
        ->and($envelope['result']['identity'])->toMatchArray(['id' => '8', 'matched_by' => 'username'])
        ->and(array_column($attribution, 'this_actor'))->toBe([0, 0, 0, 0, 0])
        ->and($attribution['job_attempts'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 1, 'no_actor' => 0])
        ->and(array_sum(array_column($envelope['result']['activity'], 'direct')) + array_sum(array_column($envelope['result']['activity'], 'dispatch')))->toBe(0)
        ->and($envelope['result']['executions'])->toBe([])
        ->and($envelope['next'])->toBe([])
        ->and($nobody['empty']['kind'])->toBe('no_match')
        ->and($nobody['result']['known_actor_count'])->toBe(2)
        ->and(array_column($nobody['result']['known_actors'], 'id'))->toBe(['7', '8']);
});

it('states what it cannot attribute once the dispatch that tied a job to the person is gone', function () {
    whoWasAffectedTraffic($this->queue);
    $this->artisan('firewatch:clear', ['--type' => 'queued-job', '--force' => true])->assertSuccessful();

    $envelope = Envelope::assert(Actor::class, ['who' => '7']);

    expect($envelope['result']['attribution']['job_attempts'])->toBe(['total' => 1, 'this_actor' => 0, 'other_actors' => 0, 'no_actor' => 1])
        ->and(array_values(Arr::sort(array_column($envelope['result']['executions'], 'link'))))->toBe(['direct', 'direct', 'inside'])
        ->and($envelope['summary'])->toBe(__('firewatch::messages.actor_summary', ['person' => 'Taylor Otwell', 'attributed' => 3, 'total' => 7, 'direct' => 2, 'dispatch' => 0, 'inside' => 1, 'unattributed' => 3]))
        ->and($envelope['notes'])->toBe([
            __('firewatch::messages.actor_commands_note', ['commands' => 2, 'tasks' => 0, 'inside' => 1]),
            trans_choice('firewatch::messages.actor_no_actor_note', 1, ['count' => 1]),
            trans_choice('firewatch::messages.actor_guest_note', 1, ['count' => 1]),
            __('firewatch::messages.actor_caveats_note'),
        ])
        ->and(array_column($envelope['blind_spots'], 'id'))->toContain('actor-partial', 'history-cleared');
});
