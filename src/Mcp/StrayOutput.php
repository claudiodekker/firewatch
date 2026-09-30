<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
class StrayOutput
{
    /**
     * Send everything the process prints outside the protocol to standard error.
     */
    public function redirect(): void
    {
        // The transport writes to the STDOUT stream, which bypasses output buffering;
        // an echo, print or inline HTML from a provider or a tool does not.
        ob_start(static function (string $buffer): string {
            fwrite(STDERR, $buffer);

            return '';
        }, chunk_size: 1);
    }
}
