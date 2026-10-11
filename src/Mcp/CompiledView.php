<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
class CompiledView
{
    /**
     * The footer Laravel's Blade compiler ends every compiled view with, naming the template it was compiled from.
     */
    protected const FOOTER = '/.*\/\*\*PATH\s([^\n]+?)\sENDPATH\*\*\//s';

    /**
     * Get the field a stored file adds beside its location: nothing outside the compiled view path, else the template its compiled file names now, or null when it can't be resolved.
     *
     * @param  mixed  $file  a stored file, which a stored trace frame suffixes with its line
     * @return array{}|array{template: string|null}
     */
    public static function of(mixed $file): array
    {
        $compiled = config('view.compiled');

        if (! is_string($file) || $file === '' || ! is_string($compiled) || $compiled === '') {
            return [];
        }

        $prefix = rtrim(self::comparable(realpath($compiled) ?: $compiled), '/').'/';
        $path = (string) preg_replace('/:\d+$/', '', $file);

        // Nightwatch stores a file under the base path relative to it, and any other file as it is.
        foreach ([$path, app()->basePath($path)] as $candidate) {
            if (str_starts_with(self::comparable($candidate), $prefix)) {
                return ['template' => self::template($candidate)];
            }
        }

        return [];
    }

    /**
     * Determine if an answer's result names the template of a compiled view anywhere.
     *
     * @param  array<array-key, mixed>  $result
     */
    public static function appearsIn(array $result): bool
    {
        if (array_key_exists('template', $result) && (array_key_exists('location', $result) || array_key_exists('file', $result))) {
            return true;
        }

        foreach ($result as $value) {
            if (is_array($value) && self::appearsIn($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the template a compiled file names, as Nightwatch prints a file, or null when the file is gone or names none.
     */
    protected static function template(string $compiled): ?string
    {
        $contents = is_file($compiled) && is_readable($compiled) ? file_get_contents($compiled) : false;

        if (! is_string($contents) || preg_match(self::FOOTER, $contents, $matches) !== 1) {
            return null;
        }

        return self::printed($matches[1], app()->basePath());
    }

    /**
     * Get a template's path as Nightwatch prints a file on a host with the given separator: in that separator, since Blade's footer joins the view path and the template with `/`, and relative to the base path when under it.
     */
    public static function printed(string $template, string $basePath, string $separator = DIRECTORY_SEPARATOR): string
    {
        $file = Recipe::hostFile($template, $separator);
        $base = rtrim(Recipe::hostFile($basePath, $separator), $separator).$separator;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Get a path as two spellings of one file compare: with forward slashes, since Nightwatch writes a file with the separator of the platform it ran on, and in lower case on Windows, whose paths ignore case.
     */
    protected static function comparable(string $path): string
    {
        $slashed = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($slashed) : $slashed;
    }
}
