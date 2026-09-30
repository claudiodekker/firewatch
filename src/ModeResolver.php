<?php

namespace ClaudioDekker\Firewatch;

use ClaudioDekker\Firewatch\Configuration\Configuration;

/**
 * @internal
 */
class ModeResolver
{
    /**
     * The oldest SQLite version the store can run on.
     */
    protected const MINIMUM_SQLITE_VERSION = '3.38.0';

    /**
     * Resolve the mode of this process, in the fixed order of the design.
     *
     * @param  list<string>  $argv
     * @param  string|null  $sqliteVersion  null when ext-sqlite3 is missing
     */
    public function resolve(Configuration $configuration, string $environment, array $argv, ?string $sqliteVersion): Mode
    {
        if (! in_array($environment, $configuration->environments, true)) {
            return Mode::STEPPED_ASIDE;
        }

        if ($this->isFirewatchProcess($argv) || ! $configuration->enabled || ! $this->isUsableSqlite($sqliteVersion)) {
            return Mode::OFF;
        }

        return Mode::ACTIVE;
    }

    /**
     * Determine if the process runs a firewatch: command, by its first argument that is not an option.
     *
     * @param  list<string>  $argv
     */
    protected function isFirewatchProcess(array $argv): bool
    {
        foreach (array_slice($argv, 1) as $argument) {
            if (str_starts_with($argument, '-')) {
                continue;
            }

            return str_starts_with($argument, 'firewatch:');
        }

        return false;
    }

    /**
     * Determine if the SQLite library is loaded and at least the supported floor.
     */
    protected function isUsableSqlite(?string $sqliteVersion): bool
    {
        return $sqliteVersion !== null && version_compare($sqliteVersion, static::MINIMUM_SQLITE_VERSION, '>=');
    }
}
