<?php

namespace ClaudioDekker\Firewatch\Tests;

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use Illuminate\Filesystem\Filesystem;
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

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->setStorePath(null);

        (new Filesystem)->deleteDirectory($this->storeDirectory);
    }

    protected function getPackageProviders($app): array
    {
        return [
            FirewatchServiceProvider::class,
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
