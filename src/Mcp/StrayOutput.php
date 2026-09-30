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
        // The transport writes to the STDOUT stream, which output buffering never sees.
        ob_start(static function (string $buffer): string {
            fwrite(STDERR, $buffer);

            return '';
        }, chunk_size: 1);
    }
}
