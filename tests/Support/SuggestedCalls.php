<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Assert;

class SuggestedCalls
{
    /**
     * Assert that every call an answer suggests, in its `next` list or written into its text, fits the schema `tools/list` publishes for that tool.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function assertFit(array $answer, string $markdown): void
    {
        $schemas = array_column(app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing()['tools'], 'inputSchema', 'name');

        $calls = $answer['next'];

        array_walk_recursive($answer, function (mixed $value) use (&$calls, $schemas) {
            if (is_string($value)) {
                array_push($calls, ...self::written($value, array_keys($schemas)));
            }
        });

        array_push($calls, ...self::written($markdown, array_keys($schemas)));

        foreach ($calls as $call) {
            foreach ($call['arguments'] as $argument => $value) {
                $schema = $schemas[$call['tool']]['properties'][$argument] ?? null;

                Assert::assertNotNull($schema, "{$call['tool']} publishes no argument {$argument}.");
                Assert::assertTrue(self::fits($value, $schema), "{$call['tool']}({$argument}: ".json_encode($value).') does not fit its published schema '.json_encode($schema).'.');
            }
        }
    }

    /**
     * Read the calls of the named tools written into a text, the way `Call::written` writes them.
     *
     * @param  list<string>  $tools
     * @return list<array{tool: string, arguments: array<string, mixed>}>
     */
    protected static function written(string $text, array $tools): array
    {
        preg_match_all('/(?<![\w-])('.implode('|', $tools).')\((?=[a-z_]+: |\))/', $text, $matches, PREG_OFFSET_CAPTURE);

        $calls = [];

        foreach ($matches[1] as [$tool, $offset]) {
            $position = $offset + strlen($tool) + 1;
            $arguments = [];

            while (preg_match('/\G([a-z_]+): /', $text, $name, 0, $position) === 1) {
                $position += strlen($name[0]);
                [$value, $length] = self::value($text, $position);
                $arguments[$name[1]] = $value;
                $position += $length;

                if (! str_starts_with(substr($text, $position), ', ')) {
                    break;
                }

                $position += 2;
            }

            Assert::assertSame(')', $text[$position] ?? null, "The written call at offset {$offset} does not close: {$text}");

            $calls[] = ['tool' => $tool, 'arguments' => $arguments];
        }

        return $calls;
    }

    /**
     * Read the shortest JSON value at a position that a separator or the closing parenthesis follows.
     *
     * @return array{mixed, int}
     */
    protected static function value(string $text, int $position): array
    {
        for ($end = $position + 1; $end <= strlen($text); $end++) {
            if (! in_array($text[$end] ?? ')', [',', ')'], true)) {
                continue;
            }

            $value = json_decode(substr($text, $position, $end - $position), associative: true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [$value, $end - $position];
            }
        }

        Assert::fail("No JSON value at offset {$position}: {$text}");
    }

    /**
     * Determine if a value fits an argument's schema: its JSON type and, when it declares one, its enum.
     *
     * @param  array<string, mixed>  $schema
     */
    protected static function fits(mixed $value, array $schema): bool
    {
        $type = match (true) {
            is_string($value) => ['string'],
            is_int($value) => ['integer', 'number'],
            is_float($value) => ['number'],
            is_bool($value) => ['boolean'],
            is_array($value) && array_is_list($value) => ['array'],
            is_array($value) => ['object'],
            default => ['null'],
        };

        return in_array($schema['type'] ?? null, $type, true)
            && (! isset($schema['enum']) || in_array($value, $schema['enum'], true));
    }
}
