<?php

namespace Workbench\App\Providers;

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Illuminate\Support\ServiceProvider;
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
        if (getenv('WORKBENCH_ECHO') !== false) {
            echo getenv('WORKBENCH_ECHO')."\n";
        }
    }
}
