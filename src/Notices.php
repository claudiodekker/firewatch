<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
interface Notices
{
    /**
     * Write a notice about how Firewatch is installed or configured, or about a failure of its own.
     */
    public function write(string $notice): void;
}
