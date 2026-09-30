<?php

namespace ClaudioDekker\Firewatch\Mcp\Tools;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

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
    public function __construct(protected Configuration $configuration)
    {
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
     * Answer that no store exists yet, as no writer creates one.
     */
    public function handle(): Response
    {
        $now = Carbon::now();

        $noStore = __('firewatch::messages.no_store', ['path' => $this->configuration->database]);
        $clock = __('firewatch::messages.store_clock', [
            'time' => $now->format('Y-m-d H:i:s.u'),
            'epoch' => (float) $now->format('U.u'),
        ]);

        $lines = ['## overview', $noStore, $clock];

        return Response::text(implode("\n", $lines));
    }
}
