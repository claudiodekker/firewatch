<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Registrar;

function firewatchVersion(): string
{
    return InstalledVersions::getPrettyVersion('claudiodekker/firewatch') ?? 'dev';
}

it('lists the tools under a header, one line each', function () {
    $result = Artisan::call('firewatch:server', ['--list' => true]);

    expect($result)->toBe(0)
        ->and(Artisan::output())->toBe(implode("\n", [
            trans_choice('firewatch::messages.listing', 1, ['version' => firewatchVersion()]),
            '  overview  Entry point.',
            '',
        ]));
});

it('cuts a tool\'s first sentence at 90 characters in the listing', function (string $sentence, string $expected) {
    app()->bind(Overview::class, fn () => new class($sentence) extends Overview
    {
        public function __construct(protected string $sentence)
        {
            //
        }

        public function description(): string
        {
            return $this->sentence.' The second sentence is never listed.';
        }
    });

    Artisan::call('firewatch:server', ['--list' => true]);

    expect(explode("\n", Artisan::output())[1])->toBe("  overview  {$expected}");
})->with([
    '90 characters' => ['sentence' => str_repeat('a', 89).'.', 'expected' => str_repeat('a', 89).'.'],
    '91 characters' => ['sentence' => str_repeat('a', 90).'.', 'expected' => str_repeat('a', 89).'…'],
]);

it('lists the tools as JSON with the server name and version', function () {
    $result = Artisan::call('firewatch:server', ['--list' => true, '--json' => true]);

    expect($result)->toBe(0)
        ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'server' => ['name' => 'firewatch', 'version' => firewatchVersion()],
            'tools' => [
                [
                    'name' => 'overview',
                    'description' => __('firewatch::messages.tools.overview'),
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                ],
            ],
        ]);
});

it('refuses --json without --list', function () {
    $result = Artisan::call('firewatch:server', ['--json' => true]);

    expect($result)->toBe(1)
        ->and(trim(Artisan::output()))->toBe(__('firewatch::messages.json_requires_list'));
});

it('lists the tools without creating anything where the store would be', function (array $options) {
    $path = app(Configuration::class)->database;

    Artisan::call('firewatch:server', $options);

    expect(dirname($path))->not->toBeDirectory();
})->with([
    'human' => ['options' => ['--list' => true]],
    'JSON' => ['options' => ['--list' => true, '--json' => true]],
]);

it('registers no laravel/mcp handle for mcp:start to run', function () {
    $servers = app(Registrar::class)->servers();

    expect($servers)->toBe([]);
});
