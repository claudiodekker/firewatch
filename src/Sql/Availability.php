<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Store\Sqlite;
use Closure;
use SQLite3;

/**
 * What the child runner checks about this machine before it spawns the SQL child.
 *
 * @internal
 */
class Availability
{
    /**
     * The SQLite release ext-sqlite3 links.
     *
     * @var Closure(): ?string
     */
    protected Closure $sqliteVersion;

    /**
     * Create a new availability instance.
     *
     * @param  (Closure(): ?string)|null  $sqliteVersion  answers null when ext-sqlite3 is missing
     */
    public function __construct(
        public readonly string $phpBinary = PHP_BINARY,
        ?Closure $sqliteVersion = null,
    ) {
        $this->sqliteVersion = $sqliteVersion ?? static fn (): ?string => Sqlite::version();
    }

    /**
     * Get why the SQL tool can't run, or null when it can.
     */
    public function reason(): ?Unavailable
    {
        clearstatcache(true, $this->phpBinary);
        $version = ($this->sqliteVersion)();

        return match (true) {
            // disable_functions removes the function itself since PHP 8.
            ! function_exists('proc_open') => Unavailable::PROC_OPEN_MISSING,
            $version === null => Unavailable::SQLITE3_MISSING,
            ! is_file($this->phpBinary) || ! is_executable($this->phpBinary) => Unavailable::PHP_BINARY,
            Sqlite::isTooOld($version) => Unavailable::SQLITE_TOO_OLD,
            default => null,
        };
    }
}
