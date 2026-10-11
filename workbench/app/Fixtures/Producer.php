<?php

namespace Workbench\App\Fixtures;

use ClaudioDekker\Firewatch\RecordType;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Octane\Events\RequestReceived;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Workbench\App\Jobs\ShipOrder;
use Workbench\App\Notifications\OrderShipped;

enum Producer: string
{
    case REQUEST = 'request';
    case COMMAND = 'command';
    case JOB_ATTEMPT = 'job-attempt';
    case SCHEDULED_TASK = 'scheduled-task';
    case QUERY = 'query';
    case QUERY_LIST = 'query.list';
    case EXCEPTION = 'exception';
    case FATAL_ERROR = 'exception.fatal';
    case LOG = 'log';
    case CACHE_EVENT = 'cache-event';
    case MAIL = 'mail';
    case NOTIFICATION = 'notification';
    case OUTGOING_REQUEST = 'outgoing-request';
    case QUEUED_JOB = 'queued-job';
    case USER = 'user';
    case LOG_OUTSIDE_EXECUTION = 'log.outside-execution';
    case OCTANE_REQUEST = 'request.octane';
    case LOG_BETWEEN_OCTANE_REQUESTS = 'log.between-octane-requests';
    case UNROUTED_REQUEST = 'request.unrouted';

    /**
     * Get the wire type of the record the producer's fixture holds.
     */
    public function type(): RecordType
    {
        return match ($this) {
            self::FATAL_ERROR => RecordType::EXCEPTION,
            self::QUERY_LIST => RecordType::QUERY,
            self::LOG_OUTSIDE_EXECUTION, self::LOG_BETWEEN_OCTANE_REQUESTS => RecordType::LOG,
            self::OCTANE_REQUEST, self::UNROUTED_REQUEST => RecordType::REQUEST,
            default => RecordType::from($this->value),
        };
    }

    /**
     * Determine if the producer's application must record its execution as a request.
     */
    public function recordsRequests(): bool
    {
        return match ($this) {
            self::REQUEST, self::USER, self::LOG_OUTSIDE_EXECUTION, self::OCTANE_REQUEST, self::LOG_BETWEEN_OCTANE_REQUESTS, self::UNROUTED_REQUEST => true,
            default => false,
        };
    }

    /**
     * Determine if the producer's application must write a log while it boots.
     */
    public function logsWhileBooting(): bool
    {
        return $this === self::LOG_OUTSIDE_EXECUTION;
    }

    /**
     * Drive the sensors of the current application so they write the producer's record.
     */
    public function produce(): void
    {
        match ($this) {
            self::REQUEST => $this->request(),
            self::COMMAND => $this->artisan(['command' => 'env']),
            self::JOB_ATTEMPT => $this->jobAttempt(),
            self::SCHEDULED_TASK => $this->scheduledTask(),
            self::QUERY => DB::select('select 1'),
            self::QUERY_LIST => DB::select('select 1 where 1 in (?, ?)', [1, 2]),
            self::EXCEPTION => Nightwatch::report(new RuntimeException('The payment failed.')),
            self::FATAL_ERROR => Nightwatch::report($this->fatalError()),
            self::LOG => Log::channel('nightwatch')->warning('The payment is slow.', ['order' => 7]),
            self::CACHE_EVENT => Cache::get('orders'),
            self::MAIL => $this->mail(),
            self::NOTIFICATION => (new AnonymousNotifiable)->notifyNow(new OrderShipped),
            self::OUTGOING_REQUEST => $this->outgoingRequest(),
            self::QUEUED_JOB => $this->queuedJob(),
            self::USER => $this->signedInRequest(),
            self::LOG_OUTSIDE_EXECUTION => $this->logsAfterARequest(),
            self::OCTANE_REQUEST => $this->octaneRequest(),
            self::LOG_BETWEEN_OCTANE_REQUESTS => $this->logBetweenOctaneRequests(),
            self::UNROUTED_REQUEST => $this->unroutedRequests(),
        };
    }

    /**
     * Serve a request to the application.
     */
    protected function request(string $uri = '/', string $method = 'GET', ?Request $request = null): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $kernel = app(HttpKernel::class);
        $request ??= Request::create($uri, $method);

        $response = $kernel->handle($request);

        $kernel->terminate($request, $response);
    }

    /**
     * Serve a request, then write a log after it has finished.
     */
    protected function logsAfterARequest(): void
    {
        $this->request();

        Log::channel('nightwatch')->warning('The payment is late.');
    }

    /**
     * Serve a request after the event Octane dispatches when its worker receives one, so the bootstrap stage never starts.
     */
    protected function octaneRequest(): void
    {
        $request = Request::create('/');

        event(new RequestReceived(app(), app(), $request));

        $this->request(request: $request);
    }

    /**
     * Serve two requests in one Octane worker, with a log written between them.
     */
    protected function logBetweenOctaneRequests(): void
    {
        $this->octaneRequest();

        Log::channel('nightwatch')->warning('The payment is slow.', ['order' => 7]);

        $this->octaneRequest();
    }

    /**
     * Serve requests of two methods to two paths no route answers.
     */
    protected function unroutedRequests(): void
    {
        $this->request('/missing-page');
        $this->request('/another-missing-page', 'POST');
    }

    /**
     * Serve a request to the application's home route as a signed-in user.
     */
    protected function signedInRequest(): void
    {
        Auth::guard()->setUser(new GenericUser(['id' => 7, 'name' => 'Taylor', 'email' => 'taylor@example.com']));

        $this->request();
    }

    /**
     * Run an Artisan command as the process's own command.
     *
     * @param  array<string, mixed>  $input
     */
    protected function artisan(array $input): void
    {
        $kernel = app(ConsoleKernel::class);
        $arguments = new ArrayInput($input);

        // Nightwatch only hears the console events the kernel reroutes.
        $kernel->rerouteSymfonyCommandEvents();

        $status = $kernel->handle($arguments, new BufferedOutput);

        $kernel->terminate($arguments, $status);
    }

    /**
     * Work one queued job off the database queue.
     */
    protected function jobAttempt(): void
    {
        $this->queuedJob();

        $this->artisan(['command' => 'queue:work', '--once' => true]);
    }

    /**
     * Queue a job on the database queue.
     */
    protected function queuedJob(): void
    {
        config()->set('queue.default', 'database');

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        ShipOrder::dispatch();
    }

    /**
     * Run the schedule with one task due.
     */
    protected function scheduledTask(): void
    {
        app(Schedule::class)->call(fn () => null)->name('prune-orders')->everyMinute();

        $this->artisan(['command' => 'schedule:run']);
    }

    /**
     * Get the error PHP raises when a process dies, as the error handler reports it.
     */
    protected function fatalError(): FatalError
    {
        return new FatalError('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 0, [
            'type' => E_ERROR,
            'file' => app_path('Jobs/ShipOrder.php'),
            'line' => 12,
        ]);
    }

    /**
     * Send a mail.
     */
    protected function mail(): void
    {
        Mail::raw('Your order shipped.', fn ($message) => $message->to('taylor@example.com')->subject('Shipped'));
    }

    /**
     * Send a faked outgoing request.
     */
    protected function outgoingRequest(): void
    {
        Http::fake(['https://example.com/ping' => Http::response('pong')]);

        Http::get('https://example.com/ping');
    }
}
