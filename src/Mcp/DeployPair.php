<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
class DeployPair
{
    /**
     * Create a new deploy pair instance.
     */
    public function __construct(
        public readonly string $before,
        public readonly string $after,
    ) {
        //
    }

    /**
     * Get the pair as an empty answer names it among the filters of the call.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return ["deploy_before: {$this->before}", "deploy_after: {$this->after}"];
    }
}
