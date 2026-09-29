<?php

namespace ClaudioDekker\Firewatch;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;

/**
 * @internal
 */
class FirewatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerNightwatch();
    }

    protected function registerNightwatch(): void
    {
        // Nightwatch is in dont-discover, so this is its only registration.
        $this->app->register(NightwatchServiceProvider::class);

        AliasLoader::getInstance()->alias('Nightwatch', Nightwatch::class);
    }
}
