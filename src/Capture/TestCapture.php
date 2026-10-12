<?php

namespace ClaudioDekker\Firewatch\Capture;

use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\Notices;
use Closure;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Laravel\Nightwatch\Compatibility;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\ExecutionStage;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Laravel\Nightwatch\SensorManager;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
class TestCapture
{
    /**
     * The variable that makes Nightwatch's provider register its request hooks in a console process.
     */
    protected const FORCE_REQUEST = 'NIGHTWATCH_FORCE_REQUEST';

    /**
     * The commands Nightwatch's console hooks treat as workers, schedulers or noise rather than as a command to record.
     */
    protected const UNRECORDED_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon:work',
        'vapor:work',
        'schedule:run',
        'schedule:work',
        'vapor:schedule',
        'help',
        'inspire',
        'schedule:finish',
    ];

    /**
     * The commands a test harness runs to migrate and seed the database, which are no work of the application's.
     */
    protected const HARNESS_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:seed',
        'db:wipe',
    ];

    /**
     * The capture of the last application a test created, whose trailing records wait for the next application or the end of the run.
     */
    protected static ?self $last = null;

    /**
     * Whether the end of the run stores the last application's trailing records.
     */
    protected static bool $storesAtShutdown = false;

    /**
     * Firewatch's ingest, which keeps the records a test writes after its last execution.
     */
    protected ?Ingest $ingest = null;

    /**
     * Whether the test is between executions, in a request, or in a command.
     */
    protected TestPhase|TestCommand $phase = TestPhase::BETWEEN;

    /**
     * The outermost request of the execution that is open.
     */
    protected ?object $request = null;

    /**
     * The console kernel whose events are rerouted, the only one whose commands become executions.
     */
    protected ?ConsoleKernel $console = null;

    /**
     * The state Nightwatch resets for each request, set aside while a command runs.
     */
    protected RequestState $requestState;

    /**
     * The sensors that record into the request state, set aside while a command runs.
     */
    protected SensorManager $requestSensors;

    /**
     * The sensor manager's hold on the execution state, which a command's sensors point at its own state.
     */
    protected ReflectionProperty $sensorState;

    /**
     * Create a new test capture instance.
     *
     * @param  Core<RequestState|CommandState>  $core
     */
    public function __construct(protected Application $app, protected Core $core, protected Notices $notices)
    {
        //
    }

    /**
     * Register Nightwatch's provider in request mode, with a trace of this application's own, without the application seeing the switch.
     *
     * @param  Closure(): void  $register
     */
    public static function registerInRequestMode(Closure $register): void
    {
        // Nightwatch keeps one trace for the process, which every test's application would otherwise share.
        NightwatchServiceProvider::flushState();

        $previous = $_SERVER[static::FORCE_REQUEST] ?? null;
        $_SERVER[static::FORCE_REQUEST] = '1';

        try {
            $register();
        } finally {
            if ($previous === null) {
                unset($_SERVER[static::FORCE_REQUEST]);
            } else {
                $_SERVER[static::FORCE_REQUEST] = $previous;
            }
        }
    }

    /**
     * Start an execution for each request and outermost command the test runs.
     *
     * @throws RuntimeException when Nightwatch's state is not what Firewatch was built against
     */
    public function listen(): void
    {
        $state = $this->core->executionState;

        if (! $state instanceof RequestState || ! property_exists(SensorManager::class, 'executionState')) {
            throw new RuntimeException('Firewatch cannot capture test executions: Nightwatch\'s execution state changed.');
        }

        $this->requestState = $state;
        $this->requestSensors = $this->core->sensor;
        $this->sensorState = new ReflectionProperty(SensorManager::class, 'executionState');

        $this->app->rebinding('request', fn () => $this->guarded($this->startRequest(...)));

        $this->whenResolved(HttpKernelContract::class, $this->endRequestsOn(...));
        $this->whenResolved(ConsoleKernelContract::class, $this->startCommandsOn(...));

        if ($this->core->ingest instanceof Ingest) {
            $this->ingest = $this->core->ingest;
            $this->ingest->recordTestRun($this->testRecord(...));
        }

        $this->followLastApplication();

        $events = $this->app->make('events');
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->guarded(fn () => $this->startCommand($event)));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $this->guarded(fn () => $this->finishCommand($event)));
    }

    /**
     * Call back with a service once the container resolves it, or now if it already has.
     *
     * @param  class-string  $abstract
     * @param  Closure(object): void  $callback
     */
    protected function whenResolved(string $abstract, Closure $callback): void
    {
        $this->app->afterResolving($abstract, $callback);

        if ($this->app->resolved($abstract)) {
            $callback($this->app->make($abstract));
        }
    }

    /**
     * End each request's execution when the HTTP kernel's lifecycle handlers run.
     */
    protected function endRequestsOn(object $kernel): void
    {
        // Registered after Nightwatch's, this handler runs once Nightwatch's has recorded the request.
        if ($kernel instanceof HttpKernel) {
            $kernel->whenRequestLifecycleIsLongerThan(-1, fn () => $this->guarded($this->endRequest(...)));
        }
    }

    /**
     * Raise the console events for the commands a test runs, before the kernel builds its console application.
     */
    protected function startCommandsOn(object $kernel): void
    {
        // Laravel raises them only outside unit tests unless they are rerouted.
        if ($kernel instanceof ConsoleKernel) {
            $this->console = $kernel->rerouteSymfonyCommandEvents();
        }
    }

    /**
     * Start a request's execution, unless a command is running or the request belongs to the one whose execution is open.
     */
    protected function startRequest(): void
    {
        if ($this->phase instanceof TestCommand) {
            return;
        }

        $outermost = $this->outermostRequest() ?? $this->app->make('request');

        // The kernel binds a request twice, and again for each request dispatched inside it.
        if ($this->phase === TestPhase::REQUEST && $outermost === $this->request) {
            return;
        }

        // Nightwatch's reset drops the buffer, which holds what the test ran before the request.
        $this->core->finishExecution();
        $this->core->prepareForRequest($this->app->make('request'));

        $this->request = $outermost;
        $this->phase = TestPhase::REQUEST;
    }

    /**
     * Get the request of the first HTTP kernel on the call stack, which every request dispatched inside it is part of.
     */
    protected function outermostRequest(): ?object
    {
        // Only the stack tells a request dispatched inside another from one that follows a request that threw.
        foreach (array_reverse(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT)) as $frame) {
            $request = $frame['args'][0] ?? null;

            if (($frame['object'] ?? null) instanceof HttpKernel && $frame['function'] === 'handle' && is_object($request)) {
                return $request;
            }
        }

        return null;
    }

    /**
     * Leave the request, after Nightwatch's own handler recorded it, so that the test's assertions run outside it.
     */
    protected function endRequest(): void
    {
        if ($this->phase !== TestPhase::REQUEST) {
            return;
        }

        $this->core->prepareForRequest($this->app->make('request'));

        $this->request = null;
        $this->phase = TestPhase::BETWEEN;
    }

    /**
     * Start an outermost command's execution in a state and sensors of its own, setting the request's aside.
     */
    protected function startCommand(CommandStarting $event): void
    {
        if ($this->phase instanceof TestCommand) {
            $this->phase->depth++;

            return;
        }

        $harness = in_array($event->command, static::HARNESS_COMMANDS, true);

        if (! $harness && ($this->phase === TestPhase::REQUEST || in_array($event->command, static::UNRECORDED_COMMANDS, true))) {
            return;
        }

        $this->core->finishExecution();

        $timestamp = $this->core->clock->microtime();
        $id = $this->core->uuid->make();
        $state = new CommandState(
            timestamp: $timestamp,
            trace: $id,
            id: $id,
            deploy: $this->requestState->deploy,
            server: $this->requestState->server,
            currentExecutionStageStartedAtMicrotime: $timestamp,
            user: $this->requestState->user,
            stage: ExecutionStage::Action,
            artisan: $this->artisan(),
        );

        $sensor = clone $this->requestSensors;
        $this->sensorState->setValue($sensor, $state);
        $sensor->flush();

        $this->core->executionState = $state;
        $this->core->sensor = $sensor;
        $this->phase = new TestCommand(recorded: ! $harness, resumes: $this->phase);

        Compatibility::addTraceIdToContext($id);
        $this->core->prepareForCommand($event->command);

        // Dropped at the end, a harness command's records keep the request it interrupts and the store free of the database's setup.
        if ($harness) {
            $this->core->dontSample();
        } else {
            $this->core->configureCommandSampling($event->command);
        }
    }

    /**
     * Record the outermost command when it finishes and give the request its state and sensors back.
     */
    protected function finishCommand(CommandFinished $event): void
    {
        if (! $this->phase instanceof TestCommand || --$this->phase->depth > 0) {
            return;
        }

        $command = $this->phase;

        try {
            if ($command->recorded) {
                $this->core->stage(ExecutionStage::End);
                $this->core->command($event->input, $event->exitCode);
            }

            $this->core->finishExecution();
        } finally {
            $this->core->executionState = $this->requestState;
            $this->core->sensor = $this->requestSensors;
            $this->phase = $command->resumes;

            $this->core->configureRequestSampling();

            if ($command->resumes === TestPhase::BETWEEN) {
                $this->core->prepareForRequest($this->app->make('request'));
            }
        }
    }

    /**
     * Store what the previous test application wrote after its last execution, and keep this one's for the next application or the end of the run.
     */
    protected function followLastApplication(): void
    {
        // A test's application is discarded without terminating, so nothing else stores what the test ran after its last execution.
        static::storeTrailingRecords();

        static::$last = $this;

        if (! static::$storesAtShutdown) {
            static::$storesAtShutdown = true;

            register_shutdown_function(static fn () => static::storeTrailingRecords());
        }
    }

    /**
     * Store the records the last test application wrote after its last execution.
     */
    protected static function storeTrailingRecords(): void
    {
        $last = static::$last;
        static::$last = null;

        $last?->ingest?->digest();
    }

    /**
     * Tell where a record the test writes now falls.
     */
    protected function testRecord(): TestRecord
    {
        return $this->phase === TestPhase::BETWEEN ? TestRecord::OUTSIDE_EXECUTIONS : TestRecord::IN_EXECUTION;
    }

    /**
     * Get the console application the kernel runs commands in.
     */
    protected function artisan(): ?Artisan
    {
        $artisan = $this->console === null ? null : (new ReflectionMethod($this->console, 'getArtisan'))->invoke($this->console);

        return $artisan instanceof Artisan ? $artisan : null;
    }

    /**
     * Write a notice about a failure to follow the test's executions instead of throwing it into the test.
     *
     * @param  Closure(): void  $step
     */
    protected function guarded(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            $this->notices->write("Firewatch could not follow a test's executions: {$exception->getMessage()}");
        }
    }
}
