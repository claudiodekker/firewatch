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

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->storeDirectory);
    }

    protected function getPackageProviders($app): array
    {
        return [
            FirewatchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('firewatch.database', $this->storeDirectory.'/firewatch.sqlite');
    }
}
