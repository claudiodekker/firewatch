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
     * The tool fields the listing shows.
     */
    protected const LISTED_TOOL_FIELDS = ['name', 'description', 'inputSchema', 'annotations'];

    /**
     * Get what tools/list returns, with the server's name and version, without a session.
     *
     * @return array{server: array{name: string, version: string}, tools: list<array<string, mixed>>}
     */
    public function listing(): array
    {
        $this->boot();

        $context = $this->createContext();
        $tools = $context->tools()->map(fn (Server\Tool $tool) => array_intersect_key($tool->toArray(), array_flip(static::LISTED_TOOL_FIELDS)));

        return [
            'server' => [
                'name' => $context->implementation->name,
                'version' => $context->implementation->version,
            ],
            'tools' => array_values($tools->all()),
        ];
    }

    /**
     * Read the version and the instructions for this server session.
     */
    protected function boot(): void
    {
        $this->version = InstalledVersions::getPrettyVersion('claudiodekker/firewatch') ?? 'dev';
        $this->instructions = __('firewatch::messages.instructions');
    }
}
