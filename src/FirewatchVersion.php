<?php

namespace ClaudioDekker\Firewatch;

use Composer\InstalledVersions;

/**
 * @internal
 */
class FirewatchVersion
{
    /**
     * Get the installed version of Firewatch, or "dev" when Composer does not know it.
     */
    public static function installed(): string
    {
        return InstalledVersions::getPrettyVersion('claudiodekker/firewatch') ?? 'dev';
    }
}
