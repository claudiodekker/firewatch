<?php

namespace Workbench\App\Fixtures;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Orchestra\Testbench\Foundation\Application as Testbench;
use RuntimeException;
use Workbench\App\Providers\WorkbenchServiceProvider;

use function Orchestra\Testbench\workbench_path;

class Sensors
{
    /**
     * Get the first record of the producer's type the sensors write while it runs in an application of its own.
     *
     * @param  (Closure(Core<*>): void)|null  $prepare
     * @return array<mixed>
     */
    public function recordOf(Producer $producer, ?Closure $prepare = null): array
    {
        $records = $this->record($producer, $prepare);

        $record = Arr::first($records, fn (array $record) => ($record['t'] ?? null) === $producer->type()->value);

        if ($record === null) {
            throw new RuntimeException("The {$producer->value} producer wrote no {$producer->type()->value} record.");
        }

        return $record;
    }

    /**
     * Record what the sensors write while a producer runs in an application of its own.
     *
     * @param  (Closure(Core<*>): void)|null  $prepare
     * @return list<array<mixed>>
     */
    public function record(Producer $producer, ?Closure $prepare = null): array
    {
        $previous = Container::getInstance();

        $recorder = new WireRecorder;

        $app = $this->application($producer, $recorder);

        try {
            $core = $app->make(Core::class);
            $core->ingest = $recorder;

            if ($prepare !== null) {
                $prepare($core);
            }

            $producer->produce();

            Nightwatch::digest();

            return $recorder->records;
        } finally {
            $this->restore($previous);

            $app->flush();
        }
    }

    /**
     * Create a fresh workbench application for the producer and make it the current one.
     */
    protected function application(Producer $producer, WireRecorder $recorder): Application
    {
        $variable = 'NIGHTWATCH_FORCE_REQUEST';
        $forced = getenv($variable);

        $this->setEnvironmentVariable($variable, $producer->recordsRequests() ? '1' : null);
        $this->setEnvironmentVariable('WORKBENCH_BOOT_LOG', $producer->logsWhileBooting() ? '1' : null);

        try {
            $app = Testbench::create(basePath: null, resolvingCallback: function (Application $app) use ($recorder) {
                $app->instance(WireRecorder::class, $recorder);
            }, options: ['extra' => ['dont-discover' => ['laravel/nightwatch'], 'providers' => [
                FirewatchServiceProvider::class,
                WorkbenchServiceProvider::class,
            ]]]);
        } finally {
            $this->setEnvironmentVariable($variable, $forced);
            $this->setEnvironmentVariable('WORKBENCH_BOOT_LOG', null);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->make('config')->set([
            'cache.default' => 'array',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);

        $app->make(Router::class)->middleware('web')->group(workbench_path('routes/web.php'));

        return $app;
    }

    /**
     * Make the given application the current one again.
     */
    protected function restore(Container $previous): void
    {
        Container::setInstance($previous);

        Facade::clearResolvedInstances();

        if ($previous instanceof Application) {
            Facade::setFacadeApplication($previous);
        }
    }

    /**
     * Set or unset an environment variable for the application about to be created.
     */
    protected function setEnvironmentVariable(string $name, string|false|null $value): void
    {
        if ($value === null || $value === false) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}
