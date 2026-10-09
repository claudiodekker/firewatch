<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum Unit: string
{
    case EPOCH_SECONDS = 'epoch_seconds';
    case MICROSECONDS = 'microseconds';
    case BYTES = 'bytes';
    case SECONDS = 'seconds';

    /**
     * Get the line that says what the unit is.
     */
    public function label(): string
    {
        return __("firewatch::messages.describe_units.{$this->value}");
    }
}
