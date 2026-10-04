<?php

namespace ClaudioDekker\Firewatch;

/**
 * @internal
 */
interface Notices
{
    /**
     * Write a notice about how Firewatch is installed or configured.
     */
    public function write(string $notice): void;
}
