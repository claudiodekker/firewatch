<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\StdioTransport;

/**
 * @api
 */
class ServerCommand extends Command
{
    use ReadsFlags;

    /**
     * The name of the command that starts the MCP server.
     */
    public const NAME = 'firewatch:server';

    /**
     * The length a tool's first sentence is cut at in the listing.
     */
    protected const LISTED_SENTENCE_LENGTH = 90;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = self::NAME.'
                            {--list : Print what tools/list returns, without a session}
                            {--json : Print the listing as JSON}';

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
        if ($this->flag('json') && ! $this->flag('list')) {
            $this->error(__('firewatch::messages.json_requires_list'));

            return self::FAILURE;
        }

        if ($this->flag('list')) {
            return $this->list();
        }

        return $this->serve();
    }

    /**
     * Run the server over stdio until its input ends.
     */
    protected function serve(): int
    {
        ini_set('display_errors', 'stderr');
        ini_set('html_errors', '0');
        ini_set('precision', '-1');
        ini_set('serialize_precision', '-1');

        // Laravel's MCP server rethrows a failed request while debugging, which ends the process.
        $this->laravel->make('config')->set('app.debug', false);

        $transport = new StdioTransport;
        $this->laravel->make(FirewatchServer::class, ['transport' => $transport])->start();
        $transport->run();

        return self::SUCCESS;
    }

    /**
     * Print what tools/list returns, as a listing or as JSON.
     */
    protected function list(): int
    {
        $listing = $this->laravel->make(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();

        if ($this->flag('json')) {
            $json = json_encode($listing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $this->line($json);

            return self::SUCCESS;
        }

        $header = __('firewatch::messages.listing', [
            'version' => $listing['server']['version'],
            'count' => count($listing['tools']),
        ]);
        $width = max([0, ...array_map(fn (array $tool) => Str::length($tool['name']), $listing['tools'])]);

        $this->line($header);

        foreach ($listing['tools'] as $tool) {
            $sentence = $this->firstSentence($tool['description']);

            $this->line('  '.Str::padRight($tool['name'], $width).'  '.$sentence);
        }

        return self::SUCCESS;
    }

    /**
     * Get the first sentence of a description, cut at the listed length.
     */
    protected function firstSentence(string $description): string
    {
        $sentence = preg_match('/^.*?[.!?](?=\s|$)/s', $description, $matches) === 1 ? $matches[0] : $description;

        if (Str::length($sentence) <= static::LISTED_SENTENCE_LENGTH) {
            return $sentence;
        }

        return Str::substr($sentence, 0, static::LISTED_SENTENCE_LENGTH - 1).'…';
    }
}
