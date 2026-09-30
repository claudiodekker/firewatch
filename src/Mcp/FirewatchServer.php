<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * @internal
 */
#[Name('firewatch')]
class FirewatchServer extends Server
{
    /**
     * The capabilities the server advertises: tools only.
     *
     * @var array<string, array<string, bool>>
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
    ];

    /**
     * The tools, in the order of the ladder.
     *
     * @var list<class-string<Server\Tool>>
     */
    protected array $tools = [
        Overview::class,
    ];

    /**
     * Read the version and the instructions for this server session.
     */
    protected function boot(): void
    {
        $this->version = InstalledVersions::getPrettyVersion('claudiodekker/firewatch') ?? 'dev';
        $this->instructions = __('firewatch::messages.instructions');
    }
}
