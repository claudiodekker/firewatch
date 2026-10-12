<?php

namespace ClaudioDekker\Firewatch\Capture;

use Illuminate\Contracts\Queue\Job;

/**
 * @internal
 */
class TestJob
{
    /**
     * Create a new test job instance.
     */
    public function __construct(public readonly Job $job)
    {
        //
    }
}
