<?php

namespace ClaudioDekker\Firewatch\Store;

use SQLite3;

/**
 * @internal
 */
class StoreStamp
{
    /**
     * Create a new store stamp instance.
     */
    public function __construct(
        public readonly int $applicationId,
        public readonly int $userVersion,
        public readonly int $objects,
    ) {
        //
    }

    /**
     * Read the stamps of an open store and how many tables, views and indexes it holds.
     */
    public static function read(SQLite3 $connection): self
    {
        /** @var int $applicationId */
        $applicationId = $connection->querySingle('PRAGMA application_id');
        /** @var int $userVersion */
        $userVersion = $connection->querySingle('PRAGMA user_version');
        /** @var int $objects */
        $objects = $connection->querySingle('SELECT count(*) FROM sqlite_master');

        return new self($applicationId, $userVersion, $objects);
    }

    /**
     * Determine if the store was stamped by Firewatch, whatever its schema version.
     */
    public function isFirewatch(): bool
    {
        return $this->applicationId === Schema::APPLICATION_ID;
    }

    /**
     * Determine if the store has the schema version this release creates.
     */
    public function isCurrent(): bool
    {
        return $this->isFirewatch() && $this->userVersion === Schema::VERSION;
    }

    /**
     * Determine if a later release of Firewatch stamped the store, which this one neither writes nor rebuilds on its own.
     */
    public function isNewer(): bool
    {
        return $this->isFirewatch() && $this->userVersion > Schema::VERSION;
    }

    /**
     * Determine if nothing was ever written to the store: no stamps and no tables.
     */
    public function isFresh(): bool
    {
        return $this->applicationId === 0 && $this->userVersion === 0 && $this->objects === 0;
    }
}
