<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use Closure;
use SQLite3;

/**
 * Whether the SQL tool's isolation can be established on this machine, with the closed reason when it can't.
 * Every fact is read on every call, so fixing the cause needs no restart.
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
        $this->sqliteVersion = $sqliteVersion ?? static fn (): ?string => class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null;
    }

    /**
     * Get why the SQL tool can't run, or null when it can: the static facts in check order, then, given a runner, its child probed.
     */
    public function reason(?ChildRunner $probe = null): ?Unavailable
    {
        clearstatcache(true, $this->phpBinary);
        $version = ($this->sqliteVersion)();

        return match (true) {
            // disable_functions removes the function itself since PHP 8.
            ! function_exists('proc_open') => Unavailable::PROC_OPEN_MISSING,
            $version === null => Unavailable::SQLITE3_MISSING,
            ! is_file($this->phpBinary) || ! is_executable($this->phpBinary) => Unavailable::PHP_BINARY,
            version_compare($version, ModeResolver::MINIMUM_SQLITE_VERSION, '<') => Unavailable::SQLITE_TOO_OLD,
            default => $probe?->probe(),
        };
    }
}
