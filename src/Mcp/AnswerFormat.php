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
     * Read a tool's `format` argument, trimmed and lowercased; an absent argument is markdown, and anything else that is not a format is null.
     */
    public static function fromArgument(mixed $value): ?self
    {
        if ($value === null) {
            return self::MARKDOWN;
        }

        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
