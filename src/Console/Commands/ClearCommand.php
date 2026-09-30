<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use Illuminate\Console\Command;

/**
 * @internal
 */
class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firewatch:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the records in the Firewatch store';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
