<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A job the queue raises no failed event for, because it was deleted before it threw.
 */
class DeletesItselfAndThrows implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->delete();

        throw new RuntimeException('The order was already shipped.');
    }
}
