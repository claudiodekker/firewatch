<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Capture\DefaultLogChannel;
use ClaudioDekker\Firewatch\Capture\HeaderRedactor;
use ClaudioDekker\Firewatch\Capture\QueryBindings;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Configuration\ConfigurationIssue;
use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Console\Commands\ClearCommand;
use ClaudioDekker\Firewatch\Console\Commands\DoctorCommand;
use ClaudioDekker\Firewatch\Console\Commands\ServerCommand;
use ClaudioDekker\Firewatch\Mcp\StrayOutput;
use ClaudioDekker\Firewatch\Sql\ChildRunner;
use ClaudioDekker\Firewatch\Sql\SqlRunner;
use ClaudioDekker\Firewatch\Store\FailureLog;
use ClaudioDekker\Firewatch\Store\Sqlite;
use ClaudioDekker\Firewatch\Store\Writer;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;
use RuntimeException;
use Throwable;

/**
 * @api
 */
class FirewatchServiceProvider extends ServiceProvider
{
    /**
     * The path of the package's configuration file.
     */
    protected const CONFIG_PATH = __DIR__.'/../config/firewatch.php';

    /**
     * The configuration groups whose keys are merged one by one.
     */
    protected const NESTED_GROUPS = ['retention', 'capture'];

    /**
     * The Nightwatch token that no hosted service accepts.
     */
    protected const DEAD_TOKEN = 'firewatch';

    /**
     * The ingest settings: a loopback address no agent listens on, with Nightwatch's own defaults.
     */
    protected const DEAD_INGEST = [
        'uri' => '127.0.0.1:1',
        'timeout' => 0.5,
        'connection_timeout' => 0.5,
        'event_buffer' => 500,
    ];

    /**
     * The sample rates that capture every execution.
     */
    protected const FULL_SAMPLING = [
        'requests' => 1.0,
        'commands' => 1.0,
        'exceptions' => 1.0,
        'scheduled_tasks' => 1.0,
    ];

    /**
     * The filtering that ignores no event and no log level.
     */
    protected const NO_FILTERING = [
        'ignore_cache_events' => false,
        'ignore_mail' => false,
        'ignore_notifications' => false,
        'ignore_outgoing_requests' => false,
        'ignore_queries' => false,
        'log_level' => 'debug',
    ];

    /**
     * The notice a console process reports when Firewatch steps aside.
     */
    protected const STEPPED_ASIDE_NOTICE = 'Firewatch is installed but stepped aside in environment `%s`; install with `composer install --no-dev` in production.';

    /**
     * The start of the notice when Nightwatch's ingest could not be swapped.
     */
    protected const INGEST_NOT_REPLACED = 'Firewatch left Nightwatch\'s ingest in place: ';

    /**
     * The notice when Nightwatch's provider was registered before Firewatch's.
     */
    protected const REGISTERED_FIRST = 'Nightwatch\'s provider was registered before Firewatch\'s, so Nightwatch read its configuration before Firewatch set it. Remove `Laravel\\Nightwatch\\NightwatchServiceProvider` from your providers and run `php artisan package:discover`.';

    /**
     * The notice when the event the veto listens on is missing.
     */
    protected const VETO_EVENT_MISSING = 'Firewatch cannot veto Nightwatch\'s transmit: `%s` is missing from Nightwatch %s.';

    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->registerNotices();
        $this->registerConfiguration();
        $this->registerGitHead();
        $this->registerStore();
        $this->registerSqlRunner();
        $this->registerNightwatchInstall();
        $this->redirectStrayServerOutput();
        $this->configureNightwatch();
        $this->vetoNightwatchTransmit();
        $this->captureQueryBindings();
        $this->registerNightwatch();
        $this->releaseQueryBindings();
        $this->guarded($this->captureLogs(...));
        $this->guarded($this->replaceNightwatchIngest(...));
        $this->guarded($this->redactHeaders(...));
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if ($this->mode() === Mode::STEPPED_ASIDE) {
            $this->notify(sprintf(static::STEPPED_ASIDE_NOTICE, $this->app->environment()));

            return;
        }

        $this->notifyConfigurationIssues();
        $this->notifyNightwatchInstall();

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'firewatch');

        $this->publishes([static::CONFIG_PATH => $this->app->configPath('firewatch.php')], 'firewatch-config');

        $this->commands([
            ServerCommand::class,
            DoctorCommand::class,
            ClearCommand::class,
        ]);
    }

    /**
     * Write notices to the PHP error log, unless they are already bound.
     */
    protected function registerNotices(): void
    {
        $this->app->bindIf(Notices::class, ErrorLogNotices::class);
    }

    /**
     * Keep how Nightwatch is installed and the mode this process resolved to, before Firewatch registers Nightwatch's provider.
     */
    protected function registerNightwatchInstall(): void
    {
        // A second registration of Firewatch finds the Nightwatch provider its first one registered.
        $registeredFirst = $this->app->getProvider(NightwatchServiceProvider::class) !== null
            && $this->app->getProvider($this) === null;

        $install = $this->app->make(NightwatchInstall::class, [
            'version' => InstalledVersions::getPrettyVersion('laravel/nightwatch') ?? '',
            'registeredFirst' => $registeredFirst,
            'mode' => (new ModeResolver)->resolve(
                $this->app->make(Configuration::class),
                environment: $this->app->environment(),
                argv: $this->argv(),
                sqliteVersion: Sqlite::version(),
            ),
        ]);

        $this->app->instance(NightwatchInstall::class, $install);
    }

    /**
     * Get the mode this process resolved to.
     */
    protected function mode(): Mode
    {
        return $this->app->make(NightwatchInstall::class)->mode;
    }

    /**
     * Resolve the configuration once for this process.
     */
    protected function registerConfiguration(): void
    {
        $this->mergeConfiguration();

        $raw = $this->app->make('config')->get('firewatch');
        $normaliser = new ConfigurationNormaliser(
            basePath: $this->app->basePath(),
            publicPath: $this->app->publicPath(),
            storagePath: $this->app->storagePath(),
        );

        $configuration = $normaliser->resolve(is_array($raw) ? $raw : []);

        $this->app->instance(Configuration::class, $configuration);
    }

    /**
     * Look for the git repository from the application's base path, unless a reader is already bound.
     */
    protected function registerGitHead(): void
    {
        $this->app->bindIf(GitHead::class, fn () => new GitHead($this->app->basePath()));
    }

    /**
     * Keep one store writer and one failure log for this process, unless they are already bound.
     */
    protected function registerStore(): void
    {
        $this->app->singletonIf(FailureLog::class);
        $this->app->singletonIf(Writer::class);
    }

    /**
     * Run the assistant's SQL in the SQL child, unless a runner is already bound.
     */
    protected function registerSqlRunner(): void
    {
        $this->app->bindIf(SqlRunner::class, ChildRunner::class);
    }

    /**
     * Report a failure of the Nightwatch wiring instead of throwing it into the host application.
     *
     * @param  Closure(): void  $wiring
     */
    protected function guarded(Closure $wiring): void
    {
        try {
            $wiring();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Keep stdout to protocol messages in a server process, before other providers can print.
     */
    protected function redirectStrayServerOutput(): void
    {
        if ($this->mode() === Mode::STEPPED_ASIDE || (new ModeResolver)->command($this->argv()) !== ServerCommand::NAME) {
            return;
        }

        (new StrayOutput)->redirect();
    }

    /**
     * Get the process's command-line arguments.
     *
     * @return list<string>
     */
    protected function argv(): array
    {
        if (! $this->app->runningInConsole()) {
            return [];
        }

        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [];
    }

    /**
     * Write the mode's Nightwatch keys before Nightwatch's provider snapshots them.
     */
    protected function configureNightwatch(): void
    {
        $config = $this->app->make('config');

        match ($this->mode()) {
            Mode::ACTIVE => $config->set([
                'nightwatch.enabled' => true,
                'nightwatch.token' => static::DEAD_TOKEN,
                'nightwatch.ingest' => static::DEAD_INGEST,
                'nightwatch.sampling' => static::FULL_SAMPLING,
                'nightwatch.filtering' => static::NO_FILTERING,
                'nightwatch.capture_exception_source_code' => true,
                ...$this->capture(),
                ...$this->deployment(),
            ]),
            Mode::OFF => $config->set('nightwatch.enabled', false),
            Mode::STEPPED_ASIDE => null,
        };
    }

    /**
     * Get the capture settings to write to Nightwatch.
     *
     * @return array<string, mixed>
     */
    protected function capture(): array
    {
        $configuration = $this->app->make(Configuration::class);

        return [
            'nightwatch.capture_request_payload' => $configuration->captureRequestPayload,
            'nightwatch.redact_payload_fields' => $configuration->redactPayloadFields,
            'nightwatch.redact_headers' => [],
        ];
    }

    /**
     * Get the deploy identity to write to Nightwatch, or nothing so Nightwatch resolves its own.
     *
     * When neither Firewatch nor Nightwatch has one, the deploy is the commit git HEAD points to.
     *
     * @return array<string, string>
     */
    protected function deployment(): array
    {
        $deploy = $this->app->make(Configuration::class)->deploy;

        if ($deploy === null && ! $this->nightwatchHasDeployment()) {
            $deploy = $this->app->make(GitHead::class)->commit();
        }

        return $deploy === null ? [] : ['nightwatch.deployment' => $deploy];
    }

    /**
     * Determine if Nightwatch's published config or its default platform variables hold a deploy.
     */
    protected function nightwatchHasDeployment(): bool
    {
        $config = $this->app->make('config');

        if ($config->has('nightwatch.deployment')) {
            $deployment = $config->get('nightwatch.deployment');
        } else {
            // Nightwatch merges its defaults under the published file in its own register(), after this one.
            $path = InstalledVersions::getInstallPath('laravel/nightwatch').'/config/nightwatch.php';
            $defaults = is_file($path) ? require $path : [];
            $deployment = is_array($defaults) ? ($defaults['deployment'] ?? null) : null;
        }

        return is_string($deployment) && $deployment !== '';
    }

    /**
     * Veto every batch of Nightwatch's own ingest when Active, in case it stays in place.
     */
    protected function vetoNightwatchTransmit(): void
    {
        if ($this->mode() !== Mode::ACTIVE) {
            return;
        }

        // until() stops at the first non-null answer, so listen before Nightwatch's provider registers.
        $this->app->make('events')->listen($this->app->make(NightwatchInstall::class)->vetoEvent(), static fn () => false);
    }

    /**
     * Merge the package defaults under the published configuration file.
     */
    protected function mergeConfiguration(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $defaults = require static::CONFIG_PATH;
        $published = $config->get('firewatch');
        $published = is_array($published) ? $published : [];

        // mergeConfigFrom merges the top level only; keep the package's env() for nested keys a published group leaves out.
        foreach (static::NESTED_GROUPS as $group) {
            if (is_array($published[$group] ?? null)) {
                $published[$group] += $defaults[$group];
            }
        }

        $config->set('firewatch', $published + $defaults);
    }

    /**
     * Hold each query's bindings when Active, from a listener that runs before Nightwatch's query listener.
     */
    protected function captureQueryBindings(): void
    {
        if ($this->mode() !== Mode::ACTIVE) {
            return;
        }

        $this->app->singleton(QueryBindings::class);

        $this->app->make('events')->listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->app->make(QueryBindings::class)->capture($event));
    }

    /**
     * Forget each query's bindings when Active, from a listener that runs after Nightwatch's query listener.
     */
    protected function releaseQueryBindings(): void
    {
        if ($this->mode() !== Mode::ACTIVE) {
            return;
        }

        $this->app->make('events')->listen(QueryExecuted::class, fn () => $this->app->make(QueryBindings::class)->release());
    }

    /**
     * Register Nightwatch's provider and facade alias.
     */
    protected function registerNightwatch(): void
    {
        // Nightwatch is in dont-discover, so this is its only registration.
        $this->app->register(NightwatchServiceProvider::class);

        AliasLoader::getInstance()->alias('Nightwatch', Nightwatch::class);
    }

    /**
     * Wrap the default log channel with Nightwatch's when Active and capturing logs.
     */
    protected function captureLogs(): void
    {
        if ($this->mode() !== Mode::ACTIVE || ! $this->app->make(Configuration::class)->captureLogs) {
            return;
        }

        $channel = new DefaultLogChannel($this->app->make('config'));

        $channel->wrap();
    }

    /**
     * Redact the configured request headers when Active, ahead of the application's own redactRequests callbacks.
     */
    protected function redactHeaders(): void
    {
        if ($this->mode() !== Mode::ACTIVE) {
            return;
        }

        $core = $this->app->bound(Core::class) ? $this->app->make(Core::class) : null;

        // A missing core, or one Firewatch can't recognise, is already reported by the ingest swap.
        if (! $core instanceof Core) {
            return;
        }

        $redactor = new HeaderRedactor($this->app->make(Configuration::class)->redactHeaders);

        $core->redactRequests($redactor);
    }

    /**
     * Swap Nightwatch's ingest for Firewatch's.
     */
    protected function replaceNightwatchIngest(): void
    {
        $install = $this->app->make(NightwatchInstall::class);

        if ($install->mode === Mode::STEPPED_ASIDE) {
            $install->keptIngest('Firewatch stepped aside');

            return;
        }

        if (! $this->app->bound(Core::class)) {
            $install->keptIngest('its core is not registered');

            return;
        }

        try {
            (new IngestReplacer)->replace($this->app->make(Core::class), fn () => match ($install->mode) {
                Mode::ACTIVE => $this->app->make(Ingest::class),
                Mode::OFF => new NullIngest,
            });
        } catch (Throwable $exception) {
            $install->keptIngest($exception->getMessage());

            if (! $exception instanceof RuntimeException) {
                throw $exception;
            }

            return;
        }

        $install->replacedIngest();
    }

    /**
     * Write a notice once about the configuration issues.
     */
    protected function notifyConfigurationIssues(): void
    {
        $issues = $this->app->make(Configuration::class)->issues;
        if ($issues === []) {
            return;
        }

        $lines = array_map(fn (ConfigurationIssue $issue) => $issue->line(), $issues);

        $this->notify('Firewatch configuration: '.implode("\n", $lines));
    }

    /**
     * Write a notice once about what the boot guards found wrong with how Nightwatch is installed.
     */
    protected function notifyNightwatchInstall(): void
    {
        $install = $this->app->make(NightwatchInstall::class);
        if ($install->registeredFirst) {
            $this->notify(static::REGISTERED_FIRST);
        }

        // Only Active registers the veto.
        if ($install->mode === Mode::ACTIVE && ! $install->hasVetoEvent()) {
            $this->notify(sprintf(static::VETO_EVENT_MISSING, $install->vetoEvent(), $install->version));
        }

        if ($install->ingestKept() !== null) {
            $this->notify(static::INGEST_NOT_REPLACED.$install->ingestKept().'.');
        }
    }

    /**
     * Write a notice.
     */
    protected function notify(string $message): void
    {
        $this->app->make(Notices::class)->write($message);
    }
}
