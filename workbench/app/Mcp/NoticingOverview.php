<?php

namespace Workbench\App\Mcp;

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Laravel\Mcp\Response;

class NoticingOverview extends Overview
{
    public function description(): string
    {
        if (getenv('WORKBENCH_NOTICE') === 'listing') {
            trigger_error('A notice while listing', E_USER_NOTICE);
        }

        return parent::description();
    }

    public function handle(): Response
    {
        if (getenv('WORKBENCH_NOTICE') === 'tool') {
            trigger_error('A notice inside a tool', E_USER_NOTICE);
        }

        return parent::handle();
    }
}
