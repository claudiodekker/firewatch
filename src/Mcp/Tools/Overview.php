<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SQLite3;

/**
 * @internal
 */
#[Name('overview')]
#[Title('Overview')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Overview extends Tool
{
    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected Configuration $configuration,
        protected Reader $reader,
    ) {
        //
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return __('firewatch::messages.tools.overview');
    }

    /**
     * Answer with the store clock and how many requests the store holds.
     */
    public function handle(): Response
    {
        $now = Carbon::now();

        $clock = __('firewatch::messages.store_clock', [
            'time' => $now->format('Y-m-d H:i:s.u'),
            'epoch' => (float) $now->format('U.u'),
        ]);

        $lines = ['## overview', $clock, $this->counts()];

        return Response::text(implode("\n", $lines));
    }

    /**
     * Get the empty-kind line, or the count of requests read in one snapshot.
     */
    protected function counts(): string
    {
        if (! $this->reader->exists()) {
            return __('firewatch::messages.no_store', ['path' => $this->configuration->database]);
        }

        /** @var array{int, int} $counts */
        $counts = $this->reader->snapshot(fn (SQLite3 $connection) => [
            $connection->querySingle('SELECT count(*) FROM records'),
            $connection->querySingle("SELECT count(*) FROM records WHERE type = 'request'"),
        ]);

        [$records, $requests] = $counts;

        if ($records === 0) {
            return __('firewatch::messages.store_empty', ['path' => $this->configuration->database]);
        }

        return "- **request**: {$requests}";
    }
}
