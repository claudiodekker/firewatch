<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use Illuminate\Console\Command;

/**
 * @internal
 */
class ServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firewatch:server';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Firewatch MCP server over stdio';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
