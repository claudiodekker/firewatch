<?php

use ClaudioDekker\Firewatch\NightwatchInstall;

it('compares the installed version with the verified line', function (string $version, bool $verified) {
    $install = new NightwatchInstall(version: $version, registeredFirst: false);

    expect($install->isVerified())->toBe($verified);
})->with([
    'the verified floor' => ['version' => 'v1.30.2', 'verified' => true],
    'a later patch' => ['version' => 'v1.30.17', 'verified' => true],
    'a patch without the v' => ['version' => '1.30.3', 'verified' => true],
    'a lower minor' => ['version' => 'v1.29.0', 'verified' => true],
    'the next minor' => ['version' => 'v1.31.0', 'verified' => false],
    'a two-digit minor' => ['version' => 'v1.300.0', 'verified' => false],
    'the next major' => ['version' => 'v2.0.0', 'verified' => false],
    'a named branch' => ['version' => 'dev-main', 'verified' => false],
    'a branch alias on the verified minor' => ['version' => '1.30.x-dev', 'verified' => false],
    'no version' => ['version' => '', 'verified' => false],
]);

it('finds the event the veto listens on', function () {
    $install = new NightwatchInstall(version: 'v1.30.2', registeredFirst: false);

    expect($install->missingVetoEvent())->toBeNull();
});

it('names the missing veto event and the installed version', function () {
    $install = new class(version: 'v1.31.0', registeredFirst: false) extends NightwatchInstall
    {
        protected function vetoEvent(): string
        {
            return 'Laravel\Nightwatch\Events\MissingEvents';
        }
    };

    expect($install->missingVetoEvent()?->getMessage())->toBe('Firewatch cannot veto Nightwatch\'s transmit: `Laravel\Nightwatch\Events\MissingEvents` is missing from Nightwatch v1.31.0.');
});
