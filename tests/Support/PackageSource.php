<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use Closure;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;

class PackageSource
{
    public const SERVER_PATH = 'Mcp';

    public const SQL_CHILD_PATH = 'Sql/Child';

    public const SQL_CHILD_NAMESPACE = 'ClaudioDekker\\Firewatch\\Sql\\Child';

    protected const ROOT = __DIR__.'/../../src';

    protected const NAMESPACE = 'ClaudioDekker\\Firewatch\\';

    protected const CONSOLE_OUTPUT_METHODS = ['info', 'line', 'comment', 'question', 'warn', 'error', 'alert', 'newLine', 'table', 'write', 'writeln'];

    protected const STDOUT_NAMES = ['STDOUT', 'printf', 'vprintf', 'fpassthru', 'readfile', 'passthru'];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return json_decode(file_get_contents(static::ROOT.'/../composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function commandNames(): array
    {
        $commands = array_filter(
            static::classes(),
            fn (string $class) => is_subclass_of($class, Command::class) && ! (new ReflectionClass($class))->isAbstract(),
        );

        return array_values(array_map(static::commandName(...), $commands));
    }

    /**
     * The classes under the namespaces whose class docblock lacks the tag.
     *
     * @param  list<string>  $namespaces  relative to the package namespace
     * @return list<string>
     */
    public static function classesWithoutTag(array $namespaces, string $tag): array
    {
        $prefixes = array_map(fn (string $namespace) => static::NAMESPACE.$namespace.'\\', $namespaces);

        $classes = array_filter(
            static::classes(),
            fn (string $class) => array_filter($prefixes, fn (string $prefix) => str_starts_with($class, $prefix)) !== [],
        );

        $untagged = array_filter(
            $classes,
            fn (string $class) => ! str_contains((new ReflectionClass($class))->getDocComment() ?: '', "@{$tag}"),
        );

        return array_values($untagged);
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
     * The name of the function a token calls, or null when the token is not a function call.
     *
     * @param  list<PhpToken>  $tokens
     */
    public static function calledFunction(PhpToken $token, array $tokens, int $index): ?string
    {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || $next?->text !== '(') {
            return null;
        }

        if ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
            return null;
        }

        return strtolower(ltrim($token->text, '\\'));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    public static function writesToStdout(PhpToken $token, array $tokens, int $index): bool
    {
        return match (true) {
            $token->is([T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML]) => true,
            $token->is(T_CONSTANT_ENCAPSED_STRING) => preg_match('#php://(stdout|output)#i', $token->text) === 1,
            $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) => in_array(ltrim($token->text, '\\'), static::STDOUT_NAMES, true)
                || static::callsConsoleOutput($token, $tokens, $index),
            default => false,
        };
    }

    /**
     * A function defined by a loaded library rather than by PHP itself.
     */
    public static function isLibraryFunction(string $name): bool
    {
        return function_exists($name) && ! (new ReflectionFunction($name))->isInternal();
    }

    /**
     * Array literals with several elements and a key that are written on one line, or that share a line between two keyed elements.
     *
     * @return list<string>
     */
    public static function inlineKeyedArrays(): array
    {
        $offences = [];

        foreach (static::files() as $relativePath => $contents) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize($contents),
                fn (PhpToken $token) => ! $token->isIgnorable(),
            ));
            $exempt = static::rulesMethodRanges($tokens);

            foreach ($tokens as $index => $token) {
                if ($token->text !== '[' || ! static::isArrayLiteralStart($tokens, $index)) {
                    continue;
                }

                foreach ($exempt as [$from, $to]) {
                    if ($index > $from && $index < $to) {
                        continue 2;
                    }
                }

                $close = static::closingIndex($tokens, $index);

                if (($tokens[$close + 1] ?? null)?->text === '=') {
                    continue;
                }

                $keyedLines = static::keyedElementLines($tokens, $index, $close);

                if ($keyedLines === []) {
                    continue;
                }

                $sharesLine = count($keyedLines) !== count(array_unique($keyedLines));

                if (($token->line === $tokens[$close]->line && static::hasSeveralElements($tokens, $index, $close)) || $sharesLine) {
                    $offences[] = "src/{$relativePath}:{$token->line}";
                }
            }
        }

        return $offences;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{int, int}>
     */
    protected static function rulesMethodRanges(array $tokens): array
    {
        $ranges = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_FUNCTION) || ($tokens[$index + 1] ?? null)?->text !== 'rules') {
                continue;
            }

            $open = $index;

            while (($tokens[$open] ?? null) !== null && $tokens[$open]->text !== '{' && $tokens[$open]->text !== ';') {
                $open++;
            }

            if (($tokens[$open] ?? null)?->text === '{') {
                $ranges[] = [$open, static::closingIndex($tokens, $open)];
            }
        }

        return $ranges;
    }

    /**
     * A `[` after an expression is index access; anywhere else it opens an array.
     *
     * @param  list<PhpToken>  $tokens
     */
    protected static function isArrayLiteralStart(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null) {
            return true;
        }

        return ! ($previous->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING])
            || in_array($previous->text, [')', ']', '}'], true));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    protected static function closingIndex(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open; $i < count($tokens); $i++) {
            if ($tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || in_array($tokens[$i]->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tokens[$i]->text, [')', ']', '}'], true) && --$depth === 0) {
                return $i;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * The line of each keyed element of the array, one entry per element.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<int>
     */
    protected static function keyedElementLines(array $tokens, int $open, int $close): array
    {
        $depth = 0;
        $lines = [];
        $elementStart = $open + 1;
        $arrowFunctionArrowSeen = false;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $token->text === ',' && $i + 1 < $close) {
                $elementStart = $i + 1;
                $arrowFunctionArrowSeen = false;
            } elseif ($depth === 0 && $token->is(T_DOUBLE_ARROW)) {
                $start = $tokens[$elementStart];
                $startsArrowFunction = $start->is(T_FN) || ($start->is(T_STATIC) && $tokens[$elementStart + 1]->is(T_FN));

                if ($startsArrowFunction && ! $arrowFunctionArrowSeen) {
                    $arrowFunctionArrowSeen = true;
                } else {
                    $lines[] = $token->line;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    protected static function hasSeveralElements(array $tokens, int $open, int $close): bool
    {
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $token->text === ',' && $i + 1 < $close) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    protected static function callsConsoleOutput(PhpToken $token, array $tokens, int $index): bool
    {
        $operator = $tokens[$index - 1] ?? null;
        $receiver = $tokens[$index - 2] ?? null;
        $next = $tokens[$index + 1] ?? null;

        return in_array($token->text, static::CONSOLE_OUTPUT_METHODS, true)
            && $operator?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && $next?->text === '('
            && in_array($receiver?->text, ['$this', 'output', 'components'], true);
    }

    /**
     * @return list<class-string>
     */
    protected static function classes(): array
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
     * @param  class-string  $class
     */
    protected static function commandName(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $properties = $reflection->getDefaultProperties();

        if (is_string($properties['signature'] ?? null)) {
            return preg_split('/[\s{]/', trim($properties['signature']))[0];
        }

        if (is_string($properties['name'] ?? null)) {
            return $properties['name'];
        }

        $attribute = $reflection->getAttributes(AsCommand::class)[0] ?? null;

        return $attribute?->newInstance()->name ?? '';
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
