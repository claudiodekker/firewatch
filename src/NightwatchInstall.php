<?php

namespace ClaudioDekker\Firewatch;

use Laravel\Nightwatch\Events\IngestingEvents;
use RuntimeException;

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
     * Get the report when the event the veto listens on is missing.
     */
    public function missingVetoEvent(): ?RuntimeException
    {
        if (class_exists($this->vetoEvent())) {
            return null;
        }

        return new RuntimeException("Firewatch cannot veto Nightwatch's transmit: `{$this->vetoEvent()}` is missing from Nightwatch {$this->version}.");
    }

    /**
     * Get the event the veto listens on.
     *
     * @return class-string
     */
    protected function vetoEvent(): string
    {
        return IngestingEvents::class;
    }
}
