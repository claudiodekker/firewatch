<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Store\Instant;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Assert;

class Answers
{
    /**
     * The keys of every answer, in order.
     */
    public const KEYS = ['tool', 'now', 'window', 'summary', 'empty', 'result', 'coverage', 'blind_spots', 'notes', 'truncated', 'next'];

    /**
     * Whether every call an answer offers is run right after the answer is asserted.
     */
    protected static bool $following = false;

    /**
     * Turn the following of offered calls on or off.
     */
    public static function followNext(bool $following): void
    {
        self::$following = $following;
    }

    /**
     * Run a call an answer offers and get what it answers.
     *
     * @param  array{tool: string, arguments: array<string, mixed>}  $call
     * @return array<string, mixed>
     */
    public static function follow(array $call): array
    {
        $following = self::$following;
        self::$following = false;

        try {
            return self::assert(self::registered($call['tool']), $call['arguments']);
        } finally {
            self::$following = $following;
        }
    }

    /**
     * Get the class the server registers under a tool name.
     *
     * @return class-string<Tool>
     */
    protected static function registered(string $name): string
    {
        $tools = (fn () => $this->tools)->call(app(FirewatchServer::class, ['transport' => new FakeTransporter]));

        foreach ($tools as $class) {
            if (app($class)->name() === $name) {
                return $class;
            }
        }

        Assert::fail("The server registers no tool named {$name}.");
    }

    /**
     * Call the tool in JSON and in markdown, assert that both carry the same answer in the fixed shape, and get the answer.
     *
     * While following is on, every call the answer offers is run and asserted the same way.
     *
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function assert(string $tool, array $arguments = []): array
    {
        // Both calls read one store clock.
        $mocked = Date::hasTestNow();
        Date::setTestNow($mocked ? Date::getTestNow() : Date::now());

        try {
            $answer = self::calls($tool, $arguments);

            if (self::$following) {
                foreach ($answer['next'] as $call) {
                    self::follow($call);
                }
            }

            return $answer;
        } finally {
            Date::setTestNow($mocked ? Date::getTestNow() : null);
        }
    }

    /**
     * Call the tool in both formats and assert the answers against each other.
     *
     * @param  class-string<Tool>  $tool
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected static function calls(string $tool, array $arguments): array
    {
        $json = FirewatchServer::tool($tool, [...$arguments, 'format' => 'json']);
        $answer = (fn () => $this->structuredContent())->call($json);
        $blocks = (fn () => $this->content())->call($json);

        Assert::assertSame(self::KEYS, array_keys($answer));
        Assert::assertCount(1, $blocks);
        Assert::assertEquals($answer, json_decode($blocks[0], associative: true, flags: JSON_THROW_ON_ERROR));

        $markdown = (fn () => $this->content())->call(FirewatchServer::tool($tool, $arguments));

        Assert::assertCount(1, $markdown);
        self::assertLayout($answer, explode("\n", $markdown[0]));
        SuggestedCalls::assertFit($answer, $markdown[0]);

        return $answer;
    }

    /**
     * Assert that the markdown has the lines of the answer, in the fixed order.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $lines
     */
    protected static function assertLayout(array $answer, array $lines): void
    {
        $position = 0;
        $expect = function (string $line) use (&$position, $lines) {
            Assert::assertSame($line, $lines[$position] ?? null);
            $position++;
        };

        $expect("## {$answer['tool']}");
        $expect($answer['summary']);
        $expect($answer['window']['windowed'] ? self::windowLine($answer['window']) : "Not windowed: {$answer['window']['reason']}");
        Assert::assertStringStartsWith('Store clock: ', $lines[$position]);
        Assert::assertStringContainsString('(epoch '.Instant::epoch($answer['now']).')', $lines[$position]);
        $position++;

        if ($answer['empty'] !== null) {
            $expect($answer['empty']['message']);
        }

        foreach (array_keys($answer['result']) as $label) {
            Assert::assertNotEmpty(preg_grep('/^(### '.preg_quote($label, '/').'$|- \*\*'.preg_quote($label, '/').'\*\*: )/', $lines));
        }

        $brief = array_column(array_filter($answer['blind_spots'], fn (array $blindSpot) => ! isset($blindSpot['message'])), 'id');

        foreach ($answer['blind_spots'] as $blindSpot) {
            if (isset($blindSpot['message'])) {
                Assert::assertContains(__('firewatch::messages.blind_spot', ['id' => $blindSpot['id'], 'message' => $blindSpot['message']]), $lines);
            }
        }

        if ($brief !== []) {
            Assert::assertContains(__('firewatch::messages.blind_spots_named', ['ids' => implode(', ', $brief)]), $lines);
        }

        $firstBlind = array_key_first(preg_grep('/^(Blind spot \(|Also blind here)/', $lines));

        if ($firstBlind !== null) {
            $lastResult = max(array_keys(preg_grep('/^(### |- \*\*)/', $lines)) ?: [0]);
            Assert::assertGreaterThan($lastResult, $firstBlind, 'the result prints before the blind spots');
        }

        Assert::assertNotEmpty(preg_grep('/^Store: '.preg_quote($answer['coverage']['state'], '/').'/', $lines));
    }

    /**
     * Get the window line the markdown prints for a window of the answer.
     *
     * @param  array<string, mixed>  $window
     */
    protected static function windowLine(array $window): string
    {
        if (array_key_exists('test', $window)) {
            return self::windowLine(array_diff_key($window, ['test' => true]))."; test: {$window['test']}";
        }

        if (array_key_exists('scope', $window)) {
            return self::windowLine(array_diff_key($window, ['scope' => true]))."; scope: {$window['scope']}";
        }

        if ($window['since'] === null && $window['until'] === null) {
            return 'Window: none (unbounded)';
        }

        $bound = fn (?float $epoch) => $epoch === null ? 'none (unbounded)' : Date::createFromTimestamp($epoch, $window['timezone'])->format('Y-m-d H:i:s.u');

        $derived = implode(', ', array_map(fn (string $side) => __("firewatch::messages.window_derived_{$side}"), $window['derived'] ?? []));

        if ($derived !== '') {
            return "Window: since {$bound($window['since'])} until {$bound($window['until'])} ({$window['timezone']}, half-open; {$derived})";
        }

        return "Window: since {$bound($window['since'])} until {$bound($window['until'])} ({$window['timezone']}, half-open)";
    }
}
