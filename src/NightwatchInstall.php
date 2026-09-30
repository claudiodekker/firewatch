<?php

namespace ClaudioDekker\Firewatch;

use Laravel\Nightwatch\Events\IngestingEvents;

/**
 * @internal
 */
class NightwatchInstall
{
    /**
     * The Nightwatch release line the contract table was built and tested against.
     */
    protected const VERIFIED_LINE = '1.30';

    /**
     * Create a new Nightwatch install instance.
     */
    public function __construct(
        public readonly string $version,
        public readonly bool $registeredFirst,
    ) {
        //
    }

    /**
     * Determine if the installed release is on the verified line or below it.
     */
    public function isVerified(): bool
    {
        // A development build matches no release pattern, so it stays unverified.
        if (preg_match('/^v?(\d+)\.(\d+)\.\d+/', $this->version, $matches) !== 1) {
            return false;
        }

        return version_compare($matches[1].'.'.$matches[2], static::VERIFIED_LINE, '<=');
    }

    /**
     * Determine if the event the veto listens on exists.
     */
    public function hasVetoEvent(): bool
    {
        return class_exists($this->vetoEvent());
    }

    /**
     * Get the event the veto listens on.
     *
     * @return class-string
     */
    public function vetoEvent(): string
    {
        return IngestingEvents::class;
    }
}
