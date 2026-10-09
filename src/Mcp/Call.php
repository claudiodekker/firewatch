<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
class Call
{
    /**
     * Get a call of a tool as it is written.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function written(string $tool, array $arguments): string
    {
        $written = array_map(fn (string $name, mixed $value) => $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), array_keys($arguments), $arguments);

        return $tool.'('.implode(', ', $written).')';
    }
}
