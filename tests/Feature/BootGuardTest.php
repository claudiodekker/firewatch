<?php

use ClaudioDekker\Firewatch\FirewatchServiceProvider;
use ClaudioDekker\Firewatch\NightwatchInstall;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\NightwatchServiceProvider;

it('reports once in a console process when Nightwatch\'s provider registered first', function (array $config) {
    config()->set($config);
    forgetProvider(FirewatchServiceProvider::class);

    app()->register(FirewatchServiceProvider::class);

    expect(notices())->toBe(['Nightwatch\'s provider was registered before Firewatch\'s, so Nightwatch read its configuration before Firewatch set it. Remove `Laravel\Nightwatch\NightwatchServiceProvider` from your providers and run `php artisan package:discover`.']);
    Exceptions::assertNothingReported();
})->with([
    'Active' => ['config' => []],
    'Off' => ['config' => ['firewatch.enabled' => false]],
]);

it('reports nothing when Firewatch registers Nightwatch\'s provider itself', function () {
    forgetProvider(FirewatchServiceProvider::class);
    forgetProvider(NightwatchServiceProvider::class);

    app()->register(FirewatchServiceProvider::class);

    expect(notices())->toBe([]);
});

it('keeps for the process whether Nightwatch\'s provider registered first', function (bool $first) {
    forgetProvider(FirewatchServiceProvider::class);

    if (! $first) {
        forgetProvider(NightwatchServiceProvider::class);
    }

    app()->register(FirewatchServiceProvider::class);

    expect(app(NightwatchInstall::class)->registeredFirst)->toBe($first);
})->with([
    'first' => ['first' => true],
    'after Firewatch' => ['first' => false],
]);

it('still vetoes every batch when Nightwatch\'s provider registered first', function () {
    app('events')->forget(IngestingEvents::class);
    forgetProvider(FirewatchServiceProvider::class);

    app()->register(FirewatchServiceProvider::class);

    expect(app('events')->until(new IngestingEvents([['t' => 'request', 'v' => 1]])))->toBeFalse();
});

it('reports only the stepped-aside notice about a provider registered first', function () {
    config()->set('firewatch.environments', 'local');
    forgetProvider(FirewatchServiceProvider::class);

    app()->register(FirewatchServiceProvider::class);

    expect(notices())->toHaveCount(1);
    expect(notices()[0])->toStartWith('Firewatch is installed but stepped aside');
    Exceptions::assertNothingReported();
});

it('stays silent about the provider order in a web process', function () {
    (fn () => $this->isRunningInConsole = false)->call(app());
    forgetProvider(FirewatchServiceProvider::class);

    app()->register(FirewatchServiceProvider::class);

    expect(notices())->toBe([]);
});

it('keeps the installed Nightwatch version', function () {
    registerFirewatch();

    expect(app(NightwatchInstall::class)->version)->toStartWith('v1.30.');
});

it('reports a missing veto event once in a console process when Active', function () {
    bindInstallWithoutVetoEvent();

    registerFirewatch();

    $version = app(NightwatchInstall::class)->version;
    expect(notices())->toBe(["Firewatch cannot veto Nightwatch's transmit: `Laravel\\Nightwatch\\Events\\MissingEvents` is missing from Nightwatch {$version}."]);
    Exceptions::assertNothingReported();
});

it('reports no missing veto event when Off', function () {
    config()->set('firewatch.enabled', false);
    bindInstallWithoutVetoEvent();

    registerFirewatch();

    expect(notices())->toBe([]);
});

function bindInstallWithoutVetoEvent(): void
{
    app()->bind(NightwatchInstall::class, fn ($app, array $parameters) => new class(...$parameters) extends NightwatchInstall
    {
        public function vetoEvent(): string
        {
            return 'Laravel\Nightwatch\Events\MissingEvents';
        }
    });
}
