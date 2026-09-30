<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use Illuminate\Console\Command;

/**
 * @internal
 */
class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firewatch:doctor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the Firewatch installation, configuration and store';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
