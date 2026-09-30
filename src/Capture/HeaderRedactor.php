<?php

namespace ClaudioDekker\Firewatch\Capture;

use Laravel\Nightwatch\Records\Request;

/**
 * @internal
 */
class HeaderRedactor
{
    /**
     * Create a new header redactor instance.
     *
     * @param  list<string>  $headers  the names of the headers to redact, in any case
     */
    public function __construct(protected array $headers)
    {
        //
    }

    /**
     * Redact the listed headers of a request record, keeping Authorization's scheme and Cookie's names.
     *
     * Nightwatch's redactRequests signature asks for a bool, which it ignores.
     */
    public function __invoke(Request $record): bool
    {
        foreach ($this->headers as $header) {
            if (! $record->headers->has($header)) {
                continue;
            }

            $values = $record->headers->all($header);
            $redacted = array_map(fn (?string $value) => $this->redact($header, $value ?? ''), $values);

            $record->headers->set($header, $redacted);
        }

        return true;
    }

    /**
     * Redact one value of a header, shaped by the header's name.
     */
    protected function redact(string $header, string $value): string
    {
        return match (strtolower($header)) {
            'authorization' => $this->redactAuthorization($value),
            'cookie' => $this->redactCookie($value),
            default => $this->replace($value),
        };
    }

    /**
     * Redact an Authorization value, keeping the scheme in front of its credentials.
     */
    protected function redactAuthorization(string $value): string
    {
        if (! str_contains($value, ' ')) {
            return $this->replace($value);
        }

        [$scheme, $credentials] = explode(' ', $value, 2);

        return $scheme.' '.$this->replace($credentials);
    }

    /**
     * Redact a Cookie value, keeping each cookie's name, or all of it when a cookie has no name.
     */
    protected function redactCookie(string $value): string
    {
        $cookies = explode(';', $value);

        foreach ($cookies as $cookie) {
            if (! str_contains($cookie, '=')) {
                return $this->replace($value);
            }
        }

        return implode('; ', array_map(function (string $cookie) {
            [$name, $contents] = explode('=', $cookie, 2);

            return trim($name).'='.$this->replace($contents);
        }, $cookies));
    }

    /**
     * Replace a value with the count of bytes it held.
     */
    protected function replace(string $value): string
    {
        return '['.strlen($value).' bytes redacted]';
    }
}
