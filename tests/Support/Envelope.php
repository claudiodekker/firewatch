<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Instant;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Server\Tool;
use PHPUnit\Framework\Assert;

// The markdown layout is asserted here once for every tool; the tool's own tests assert its facts.
class Envelope
{
    /**
     * The keys of every envelope, in order.
     */
    public const KEYS = ['tool', 'now', 'window', 'summary', 'empty', 'result', 'coverage', 'blind_spots', 'notes', 'truncated', 'next'];

    /**
     * Call the tool in JSON and in markdown, assert that both carry the same answer in the fixed shape, and get the envelope.
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
            return self::calls($tool, $arguments);
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
        $envelope = (fn () => $this->structuredContent())->call($json);
        $blocks = (fn () => $this->content())->call($json);

        Assert::assertSame(self::KEYS, array_keys($envelope));
        Assert::assertCount(1, $blocks);
        Assert::assertEquals($envelope, json_decode($blocks[0], associative: true, flags: JSON_THROW_ON_ERROR));

        $markdown = (fn () => $this->content())->call(FirewatchServer::tool($tool, $arguments));

        Assert::assertCount(1, $markdown);
        self::assertLayout($envelope, explode("\n", $markdown[0]));

        return $envelope;
    }

    /**
     * Assert that the markdown has the lines of the envelope, in the fixed order.
     *
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $lines
     */
    protected static function assertLayout(array $envelope, array $lines): void
    {
        $position = 0;
        $expect = function (string $line) use (&$position, $lines) {
            Assert::assertSame($line, $lines[$position] ?? null);
            $position++;
        };

        $expect("## {$envelope['tool']}");
        $expect($envelope['summary']);
        $expect($envelope['window']['windowed'] ? self::windowLine($envelope['window']) : "Not windowed: {$envelope['window']['reason']}");
        Assert::assertStringStartsWith('Store clock: ', $lines[$position]);
        Assert::assertStringContainsString('(epoch '.Instant::epoch($envelope['now']).')', $lines[$position]);
        $position++;

        if ($envelope['empty'] !== null) {
            $expect($envelope['empty']['message']);
        }

        foreach (array_keys($envelope['result']) as $label) {
            Assert::assertNotEmpty(preg_grep('/^(### '.preg_quote($label, '/').'$|- \*\*'.preg_quote($label, '/').'\*\*: )/', $lines));
        }

        Assert::assertNotEmpty(preg_grep('/^Store: '.preg_quote($envelope['coverage']['state'], '/').'/', $lines));
    }

    /**
     * Get the window line the markdown prints for a window of the envelope.
     *
     * @param  array<string, mixed>  $window
     */
    protected static function windowLine(array $window): string
    {
        if ($window['since'] === null && $window['until'] === null) {
            return 'Window: none (unbounded)';
        }

        $bound = fn (?float $epoch) => $epoch === null ? 'none (unbounded)' : Date::createFromTimestamp($epoch, $window['timezone'])->format('Y-m-d H:i:s.u');

        return "Window: since {$bound($window['since'])} until {$bound($window['until'])} ({$window['timezone']}, half-open)";
    }
}
