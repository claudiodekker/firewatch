<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use Closure;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;

/**
 * Reads the package source for the invariants Pest's arch expectations
 * cannot express: plain scripts, language constructs and name prefixes.
 */
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
