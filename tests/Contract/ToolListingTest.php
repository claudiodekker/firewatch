<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use Illuminate\Support\Arr;
use Laravel\Mcp\Server\Transport\FakeTransporter;

function toolListing(): array
{
    return app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
}

test('every tool description is at most 150 words', function () {
    $words = array_map(fn (array $tool) => str_word_count($tool['description']), toolListing()['tools']);

    expect(max($words))->toBeLessThanOrEqual(150);
});

test('every tool the assistant-facing text names in backticks is registered', function () {
    $tools = array_column(toolListing()['tools'], 'name');
    $arguments = array_merge(...array_map(fn (array $tool) => array_keys($tool['inputSchema']['properties']), toolListing()['tools']));
    $answerFields = ['next', 'withheld', 'detail'];
    $otherWords = ['database', 'split_at'];
    $text = implode("\n", Arr::flatten(trans('firewatch::messages')));

    preg_match_all('/`([a-z_]+)(\(|`)/', $text, $matches, PREG_SET_ORDER);

    $calls = array_map(fn (array $match) => $match[1], array_filter($matches, fn (array $match) => $match[2] === '('));
    $words = array_map(fn (array $match) => $match[1], array_filter($matches, fn (array $match) => $match[2] === '`'));

    expect(array_values(array_unique(array_diff($calls, $tools))))->toBe([])
        ->and(array_values(array_unique(array_diff($words, $tools, $arguments, $answerFields, $otherWords))))->toBe([]);
});

test('the tool listing is under 5,000 tokens at three characters a token', function () {
    $characters = strlen(json_encode(toolListing()['tools'], JSON_THROW_ON_ERROR));

    expect(intdiv($characters, 3))->toBeLessThan(5000);
});
