<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

/**
 * @internal
 */
trait ReadsFlags
{
    /**
     * Determine if a flag option was passed.
     */
    protected function flag(string $name): bool
    {
        return $this->option($name) === true;
    }
}
