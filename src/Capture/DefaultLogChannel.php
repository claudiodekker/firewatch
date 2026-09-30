<?php

namespace ClaudioDekker\Firewatch\Capture;

use Illuminate\Contracts\Config\Repository;

/**
 * @internal
 */
class DefaultLogChannel
{
    /**
     * The name of the stack that wraps the default channel with Nightwatch's.
     */
    public const NAME = 'firewatch';

    /**
     * The name of Nightwatch's log channel.
     */
    protected const NIGHTWATCH = 'nightwatch';

    /**
     * Create a new default log channel instance.
     */
    public function __construct(protected Repository $config)
    {
        //
    }

    /**
     * Make the default channel a stack of Nightwatch's channel and the original default, unless it already logs to Nightwatch's or the name is taken.
     */
    public function wrap(): void
    {
        $default = $this->config->get('logging.default');

        if (! is_string($default) || $default === '' || $this->logsToNightwatch($default)) {
            return;
        }

        if ($this->isTakenByApplication() || ! is_array($this->config->get('logging.channels.'.static::NIGHTWATCH))) {
            return;
        }

        $this->config->set([
            'logging.channels.'.static::NAME => [
                'driver' => 'stack',
                // Nightwatch's handler always passes a record on; a default handler that doesn't would hide it from Nightwatch's.
                'channels' => [static::NIGHTWATCH, $default],
                'ignore_exceptions' => false,
            ],
            'logging.default' => static::NAME,
        ]);
    }

    /**
     * Determine if the application defines a channel of the stack's name itself, one that is not an earlier wrap.
     */
    protected function isTakenByApplication(): bool
    {
        return is_array($this->config->get('logging.channels.'.static::NAME)) && ! $this->logsToNightwatch(static::NAME);
    }

    /**
     * Get the channels a stack's configuration lists, as a list or a comma-separated string.
     *
     * @param  array<mixed>  $config
     * @return list<mixed>
     */
    protected function members(array $config): array
    {
        $channels = $config['channels'] ?? [];

        return match (true) {
            is_string($channels) => explode(',', $channels),
            is_array($channels) => array_values($channels),
            default => [],
        };
    }

    /**
     * Determine if a channel is Nightwatch's or a stack that includes it, following nested stacks.
     *
     * @param  list<string>  $seen  the stacks already followed, so a stack that refers back to itself ends
     */
    protected function logsToNightwatch(string $channel, array $seen = []): bool
    {
        if ($channel === static::NIGHTWATCH) {
            return true;
        }

        $config = $this->config->get("logging.channels.{$channel}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'stack' || in_array($channel, $seen, strict: true)) {
            return false;
        }

        foreach ($this->members($config) as $member) {
            if (is_string($member) && $this->logsToNightwatch($member, [...$seen, $channel])) {
                return true;
            }
        }

        return false;
    }
}
