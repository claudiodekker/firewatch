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
    public const VERIFIED_LINE = '1.30';

    /**
     * Why Nightwatch's ingest is still in place, or null once Firewatch replaced it.
     */
    protected ?string $ingestKept = 'the swap did not finish';

    /**
     * Create a new Nightwatch install instance.
     */
    public function __construct(
        public readonly string $version,
        public readonly bool $registeredFirst,
        public readonly Mode $mode,
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

    /**
     * Record that Firewatch replaced Nightwatch's ingest.
     */
    public function replacedIngest(): void
    {
        $this->ingestKept = null;
    }

    /**
     * Record why Firewatch left Nightwatch's ingest in place.
     */
    public function keptIngest(string $reason): void
    {
        $this->ingestKept = $reason;
    }

    /**
     * Get why Nightwatch's ingest is still in place, or null when Firewatch replaced it.
     */
    public function ingestKept(): ?string
    {
        return $this->ingestKept;
    }
}
