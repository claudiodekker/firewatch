<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use Closure;
use PhpToken;
use Symfony\Component\Finder\Finder;

/**
 * Reads the package source for the invariants Pest's arch expectations
 * cannot express: plain scripts, language constructs and name prefixes.
 */
class PackageSource
{
    protected const ROOT = __DIR__.'/../../src';

    protected const NAMESPACE = 'ClaudioDekker\\Firewatch\\';

    /**
     * @return list<class-string>
     */
    public static function classes(): array
    {
        $classes = [];

        foreach (static::files() as $relativePath => $contents) {
            // Autoloading a plain script would run it, so only class files are loaded.
            if (preg_match('/^\s*((abstract|final|readonly)\s+)*class\s/m', $contents) !== 1) {
                continue;
            }

            $class = static::NAMESPACE.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param  Closure(PhpToken, list<PhpToken>, int): bool  $offends
     * @return list<string>
     */
    public static function offendingTokens(string $directory, Closure $offends): array
    {
        $offences = [];

        foreach (static::files($directory) as $relativePath => $contents) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize($contents),
                fn (PhpToken $token) => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $index => $token) {
                if ($offends($token, $tokens, $index)) {
                    $offences[] = "src/{$relativePath}:{$token->line} {$token->text}";
                }
            }
        }

        return $offences;
    }

    /**
     * @return iterable<string, string>
     */
    protected static function files(string $directory = ''): iterable
    {
        $path = static::ROOT.($directory === '' ? '' : "/{$directory}");

        if (! is_dir($path)) {
            return;
        }

        foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
            $relativePath = ltrim(($directory === '' ? '' : "{$directory}/").$file->getRelativePathname(), '/');

            yield $relativePath => $file->getContents();
        }
    }
}
