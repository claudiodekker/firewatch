<?php

namespace ClaudioDekker\Firewatch\Tests;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected string $storeDirectory;

    protected function setUp(): void
    {
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
        parent::tearDown();

        $this->setStorePath(null);

        unset($_SERVER['FIREWATCH_RETENTION_AGE'], $_ENV['FIREWATCH_RETENTION_AGE']);
        putenv('FIREWATCH_RETENTION_AGE');

        (new Filesystem)->deleteDirectory($this->storeDirectory);
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
