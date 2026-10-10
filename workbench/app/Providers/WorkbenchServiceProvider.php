<?php

namespace Workbench\App\Providers;

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Workbench\App\Console\Commands\AuditMember;
use Workbench\App\Console\Commands\GenerateWireFixtures;
use Workbench\App\Fixtures\WireRecorder;
use Workbench\App\Mcp\NoticingOverview;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (getenv('WORKBENCH_NOTICE') !== false) {
            $this->app->bind(Overview::class, NoticingOverview::class);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateWireFixtures::class, AuditMember::class]);
        }

        if (getenv('WORKBENCH_BOOT_LOG') !== false) {
            $this->app->make(Core::class)->ingest = $this->app->make(WireRecorder::class);

            $this->app->make('log')->channel('nightwatch')->warning('The payment is slow.', ['order' => 7]);
        }

        if (getenv('WORKBENCH_ECHO') !== false) {
            echo getenv('WORKBENCH_ECHO')."\n";
        }
    }
}
