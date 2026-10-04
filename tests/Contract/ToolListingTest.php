<?php

use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use Laravel\Mcp\Server\Transport\FakeTransporter;

function toolListing(): array
{
    return app(FirewatchServer::class, ['transport' => new FakeTransporter])->listing();
}

test('every tool description is at most 150 words', function () {
    $words = array_map(fn (array $tool) => str_word_count($tool['description']), toolListing()['tools']);

    expect(max($words))->toBeLessThanOrEqual(150);
});

test('the tool listing is under 5,000 tokens at three characters a token', function () {
    $characters = strlen(json_encode(toolListing()['tools'], JSON_THROW_ON_ERROR));

    expect(intdiv($characters, 3))->toBeLessThan(5000);
});

test('every argument description is at most 30 words', function () {
    $words = [];

    foreach (toolListing()['tools'] as $tool) {
        foreach ($tool['inputSchema']['properties'] as $argument => $schema) {
            $words["{$tool['name']}.{$argument}"] = str_word_count($schema['description']);
        }
    }

    expect(array_filter($words, fn (int $count) => $count > 30))->toBe([]);
});
