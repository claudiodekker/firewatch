<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use ClaudioDekker\Firewatch\Console\Concerns\ReadsFlags;
use ClaudioDekker\Firewatch\Console\Doctor\Doctor;
use ClaudioDekker\Firewatch\Console\Doctor\DoctorReport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 */
class DoctorCommand extends Command
{
    use ReadsFlags;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firewatch:doctor {--json : Print the report as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the Firewatch installation, configuration and store';

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor): int
    {
        $report = $doctor->run();

        if ($this->flag('json')) {
            $this->raw(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->print($report);
        }

        return $report->failed() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print one line per result, with its fix indented beneath a warning or a failure.
     */
    protected function print(DoctorReport $report): void
    {
        foreach ($report->lines() as $line) {
            $result = $line['result'];

            $this->raw("[{$result->status->value}] {$line['check']->value} {$result->message}");

            if ($result->fix !== null) {
                $this->raw("  fix: {$result->fix}");
            }
        }
    }

    /**
     * Write a line as it is, so that a message holding angle brackets is not read as console formatting.
     */
    protected function raw(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
