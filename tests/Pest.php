<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Notices;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Tests\Support\Envelope;
use ClaudioDekker\Firewatch\Tests\Support\FakeNotices;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use ClaudioDekker\Firewatch\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
    })
    ->in('Scenario', 'Feature', 'Contract');

pest()->beforeEach(fn () => Envelope::followNext(true))
    ->afterEach(fn () => Envelope::followNext(false))
    ->in('Scenario');

pest()->group('scenario')->in('Scenario');
pest()->group('feature')->in('Feature');
pest()->group('contract')->in('Contract');
pest()->group('unit')->in('Unit');
pest()->group('arch')->in('Arch');

/**
 * Get the notices Firewatch wrote during the test.
 *
 * @return list<string>
 */
function notices(): array
{
    /** @var FakeNotices $notices */
    $notices = app(Notices::class);

    return $notices->written();
}

function registerFirewatch(): Configuration
{
    app()->register(FirewatchServiceProvider::class, force: true);

    return app(Configuration::class);
}

/**
 * Forget that a service provider was registered, so that registering it again boots it afresh.
 *
 * @param  class-string<ServiceProvider>  $provider
 */
function forgetProvider(string $provider): void
{
    (function () use ($provider) {
        unset($this->serviceProviders[$provider], $this->loadedProviders[$provider]);
    })->call(app());
}

function setEnvironmentVariable(string $name, string $value): void
{
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");

    test()->beforeApplicationDestroyed(function () use ($name) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    });
}

/**
 * Replace the process arguments for the test and restore them afterwards.
 *
 * @param  list<string>  $argv
 */
function setArgv(array $argv): void
{
    $original = $_SERVER['argv'];
    $outputBuffers = ob_get_level();
    $_SERVER['argv'] = $argv;

    // A firewatch:server process redirects stray output through a buffer of its own.
    test()->beforeApplicationDestroyed(function () use ($original, $outputBuffers) {
        $_SERVER['argv'] = $original;

        while (ob_get_level() > $outputBuffers) {
            ob_end_clean();
        }
    });
}

function forceRequests(): void
{
    setEnvironmentVariable(name: 'NIGHTWATCH_FORCE_REQUEST', value: '1');

    test()->refreshApplication();
}

/**
 * Run an Artisan command through the console kernel, as the real process would.
 *
 * @param  array<string, mixed>  $input
 */
function runArtisan(array $input): void
{
    $kernel = app(ConsoleKernel::class);
    $arguments = new ArrayInput($input);

    // Testbench only routes the console events Nightwatch listens to when asked.
    $kernel->rerouteSymfonyCommandEvents();

    $status = $kernel->handle($arguments, new BufferedOutput);

    $kernel->terminate($arguments, $status);
}

/**
 * Get a path relative to the package with the separators of the platform, as PHP reports a file.
 */
function nativePath(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function syntheticRecord(RecordType $type): RecordBuilder
{
    return new RecordBuilder($type);
}

/**
 * Send records through Firewatch's real ingest and digest them.
 *
 * @param  list<RecordBuilder|array<mixed>>  $records
 */
function ingest(array $records): void
{
    foreach ($records as $record) {
        app(Core::class)->ingest->write($record instanceof RecordBuilder ? $record->make() : $record);
    }

    Nightwatch::digest();
}

/**
 * Read the rows a query returns from the store, through its reader.
 *
 * @return list<array<string, mixed>>
 */
function storeRows(string $sql): array
{
    return app(Reader::class)->snapshot(function (SQLite3 $connection) use ($sql) {
        $result = $connection->query($sql);
        $rows = [];

        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    });
}

/**
 * Get the counts of the attribution block of an actor answer, zero where none is given.
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
 * Get the note of an actor answer on the commands and scheduled tasks of the window, each count in its grammatical number.
 */
function actorCommandsNote(int $commands, int $tasks, int $inside): string
{
    return __('firewatch::messages.actor_commands_note', [
        'commands' => trans_choice('firewatch::messages.actor_commands_count', $commands, ['count' => $commands]),
        'tasks' => trans_choice('firewatch::messages.actor_tasks_count', $tasks, ['count' => $tasks]),
        'inside' => trans_choice('firewatch::messages.actor_inside_count', $inside, ['count' => $inside]),
    ]);
}

/**
 * Get the activity of an actor answer, a row for each of the twelve types, from the counts of the types that have any.
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
 * Get the description of the since or until argument, for what an absent bound means for the kind of tool.
 */
function windowArgument(string $bound, string $kind = 'unbounded'): string
{
    return __("firewatch::messages.{$bound}_argument", ['absent' => __("firewatch::messages.window_absent.{$kind}.{$bound}")]);
}

/**
 * Get the description of the limit argument of a tool.
 */
function limitArgument(string $tool, int $maximum, int $default): string
{
    return __('firewatch::messages.limit_argument', ['items' => __("firewatch::messages.limit_items.{$tool}"), 'maximum' => $maximum, 'default' => $default]);
}

/**
 * Get what `tools/list` answers, without a session.
 *
 * @return array<string, mixed>
 */
function toolListing(): array
{
    return app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
}
