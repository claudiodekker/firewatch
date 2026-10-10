<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\FirewatchVersion;
use ClaudioDekker\Firewatch\Mcp\Tools\Actor;
use ClaudioDekker\Firewatch\Mcp\Tools\Compare;
use ClaudioDekker\Firewatch\Mcp\Tools\Describe;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Execution;
use ClaudioDekker\Firewatch\Mcp\Tools\Fingerprint;
use ClaudioDekker\Firewatch\Mcp\Tools\Occurrences;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\Mcp\Tools\Query;
use ClaudioDekker\Firewatch\Mcp\Tools\Rank;
use ClaudioDekker\Firewatch\Mcp\Tools\Trace;
use ClaudioDekker\Firewatch\Mcp\Tools\Trend;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * @internal
 */
#[Name('firewatch')]
class FirewatchServer extends Server
{
    /**
     * The capabilities the server advertises.
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
        Rank::class,
        Detect::class,
        Occurrences::class,
        Execution::class,
        Trace::class,
        Actor::class,
        Compare::class,
        Trend::class,
        Query::class,
        Describe::class,
        Fingerprint::class,
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
     * Get the arguments any of the tools takes.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        $arguments = [];

        foreach ($this->listing()['tools'] as $tool) {
            array_push($arguments, ...array_keys($tool['inputSchema']['properties'] ?? []));
        }

        /** @var list<string> */
        return array_values(array_unique($arguments));
    }

    /**
     * Read the version and the instructions for this server session.
     */
    protected function boot(): void
    {
        $this->version = FirewatchVersion::installed();
        $this->instructions = __('firewatch::messages.instructions');
    }
}
