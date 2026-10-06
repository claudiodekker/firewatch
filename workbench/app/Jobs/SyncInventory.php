<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SyncInventory implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 0;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->attempts() === 1) {
            throw new RuntimeException('The warehouse timed out.');
        }
    }
}
