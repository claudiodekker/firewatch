<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Nightwatch\Core;
use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\WireFixture;
use Workbench\App\Fixtures\WireRecorder;

class GenerateWireFixtures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workbench:fixtures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write one wire fixture per record type from the real sensors';

    /**
     * Execute the console command.
     *
     * @param  Core<*>  $nightwatch
     */
    public function handle(WireFixture $fixtures, Core $nightwatch): int
    {
        // The generator's own run is not a fixture, and must not reach the workbench's store.
        $nightwatch->ingest = new WireRecorder;

        foreach (Producer::cases() as $producer) {
            $fixtures->write($producer);

            $this->components->info("Wrote the {$producer->value} fixture.");
        }

        return self::SUCCESS;
    }
}
