<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
class MessageShape
{
    /**
     * What each pass replaces, in the order the passes run.
     */
    protected const REPLACEMENTS = [
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '<id>',
        '/[0-9a-f]{8,}/i' => '<id>',
        '/[0-9]+/' => '<n>',
    ];

    /**
     * What splits a shape into its literal runs.
     */
    protected const PLACEHOLDER = '/<id>|<n>/';

    /**
     * The most characters of a fragment.
     */
    protected const FRAGMENT_CHARACTERS = 200;

    /**
     * Create a new message shape instance.
     */
    protected function __construct(
        public readonly string $text,
    ) {
        //
    }

    /**
     * Get the shape of a message.
     */
    public static function of(string $message): self
    {
        foreach (self::REPLACEMENTS as $pattern => $placeholder) {
            $message = preg_replace($pattern, $placeholder, $message) ?? $message;
        }

        return new self($message);
    }

    /**
     * Get the longest literal run between the placeholders, the earliest of several, or null for a shape without one.
     */
    public function fragment(): ?string
    {
        $longest = '';

        foreach (preg_split(self::PLACEHOLDER, $this->text) ?: [] as $run) {
            $run = trim($run);

            if (mb_strlen($run) > mb_strlen($longest)) {
                $longest = $run;
            }
        }

        if ($longest === '') {
            return null;
        }

        return rtrim(mb_substr($longest, 0, self::FRAGMENT_CHARACTERS));
    }
}
