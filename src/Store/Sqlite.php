<?php

namespace ClaudioDekker\Firewatch\Store;

use SQLite3;

/**
 * @internal
 */
class Sqlite
{
    /**
     * The oldest SQLite version the store can run on.
     */
    public const MINIMUM_VERSION = '3.41.0';

    /**
     * The SQLite releases with the WAL-reset bug, each from its first affected release up to the release that fixed it.
     */
    protected const WAL_RESET_BUG = [
        ['3.7.0', '3.44.6'],
        ['3.45.0', '3.50.7'],
        ['3.51.0', '3.51.3'],
    ];

    /**
     * Get the SQLite release ext-sqlite3 links, or null when the extension is missing.
     */
    public static function version(): ?string
    {
        return class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null;
    }

    /**
     * Determine if a SQLite release is older than the store can run on.
     */
    public static function isTooOld(string $version): bool
    {
        return version_compare($version, self::MINIMUM_VERSION, '<');
    }

    /**
     * Determine if a SQLite release can reset the write-ahead log under a concurrent writer.
     */
    public static function hasWalResetBug(string $version): bool
    {
        foreach (self::WAL_RESET_BUG as [$affected, $fixed]) {
            if (version_compare($version, $affected, '>=') && version_compare($version, $fixed, '<')) {
                return true;
            }
        }

        return false;
    }
}
