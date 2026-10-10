<?php

namespace ClaudioDekker\Firewatch\Tests;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\Notices;
use ClaudioDekker\Firewatch\NullIngest;
use ClaudioDekker\Firewatch\Tests\Support\FakeNotices;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected string $storeDirectory;

    /**
     * The notices Firewatch writes during the test, in every application the test creates.
     */
    protected FakeNotices $notices;

    protected function setUp(): void
    {
        $this->notices = new FakeNotices;

        $this->storeDirectory = sys_get_temp_dir().'/firewatch-tests/'.bin2hex(random_bytes(8));

        // Firewatch resolves its configuration while it registers, before defineEnvironment() runs.
        $this->setStorePath($this->storeDirectory.'/firewatch.sqlite');

        // Wire fixtures carry timestamps from the day they were recorded, so a test keeps them unless it sets the age itself.
        $_SERVER['FIREWATCH_RETENTION_AGE'] = $_ENV['FIREWATCH_RETENTION_AGE'] = '36500d';
        putenv('FIREWATCH_RETENTION_AGE=36500d');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->endCapture();

        parent::tearDown();

        $this->setStorePath(null);

        unset($_SERVER['FIREWATCH_RETENTION_AGE'], $_ENV['FIREWATCH_RETENTION_AGE']);
        putenv('FIREWATCH_RETENTION_AGE');

        // A reported exception holds the store's connections in its trace until the next test resolves the facade again.
        Facade::clearResolvedInstances();

        (new Filesystem)->deleteDirectory($this->storeDirectory);

        NightwatchServiceProvider::flushState();
    }

    /**
     * Give every application the test creates a trace of its own, as each process of a real application has.
     *
     * Nightwatch keeps one trace for a whole process, so the applications of a test process would share theirs, and its requests one execution id.
     */
    protected function refreshApplication()
    {
        $this->endCapture();

        NightwatchServiceProvider::flushState();

        parent::refreshApplication();
    }

    /**
     * End the capture of the application, closing the store connection its ingest kept, as the end of its process would.
     *
     * Laravel's static state keeps an application alive past its test, and Windows can't delete a store that is still open.
     */
    public function endCapture(): void
    {
        $core = $this->app?->resolved(Core::class) ? $this->app->make(Core::class) : null;

        if ($core instanceof Core && $core->ingest instanceof Ingest) {
            $core->ingest = new NullIngest;
        }
    }

    /**
     * Keep Firewatch's notices out of the error log, which PHPUnit prints after each test.
     */
    protected function overrideApplicationBindings($app): array
    {
        return [Notices::class => fn () => $this->notices];
    }

    protected function getPackageProviders($app): array
    {
        return [
            FirewatchServiceProvider::class,
            // An application discovers it, and it hands a tool's arguments to its request.
            McpServiceProvider::class,
        ];
    }

    protected function setStorePath(?string $path): void
    {
        if ($path === null) {
            unset($_SERVER['FIREWATCH_DATABASE'], $_ENV['FIREWATCH_DATABASE']);
            putenv('FIREWATCH_DATABASE');

            return;
        }

        $_SERVER['FIREWATCH_DATABASE'] = $_ENV['FIREWATCH_DATABASE'] = $path;
        putenv("FIREWATCH_DATABASE={$path}");
    }
}
