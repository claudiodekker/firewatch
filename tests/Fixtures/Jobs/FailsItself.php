<?php

namespace ClaudioDekker\Firewatch\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * A job that marks itself as failed and then carries on.
 */
class FailsItself implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->fail('The order was cancelled.');

        DB::select('select 1 as after_failing');
    }
}
