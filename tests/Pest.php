<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\Notices;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Tests\Support\FakeNotices;
use ClaudioDekker\Firewatch\Tests\Support\RecordBuilder;
use ClaudioDekker\Firewatch\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
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
