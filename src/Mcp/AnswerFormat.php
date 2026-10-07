<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum AnswerFormat: string
{
    case MARKDOWN = 'markdown';
    case JSON = 'json';

    /**
     * Read a tool's `format` argument, or null for a value that is no format.
     */
    public static function fromArgument(mixed $value): ?self
    {
        if ($value === null) {
            return self::MARKDOWN;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
