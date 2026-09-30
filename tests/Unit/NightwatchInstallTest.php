<?php

use ClaudioDekker\Firewatch\NightwatchInstall;

it('compares the installed version with the verified line', function (string $version, bool $verified) {
    $install = new NightwatchInstall(version: $version, registeredFirst: false);

    expect($install->isVerified())->toBe($verified);
})->with([
    'a patch of the verified line' => ['version' => 'v1.30.2', 'verified' => true],
    'a later patch' => ['version' => 'v1.30.17', 'verified' => true],
    'a pre-release of a verified patch' => ['version' => 'v1.30.3-RC1', 'verified' => true],
    'a patch without the v' => ['version' => '1.30.3', 'verified' => true],
    'a lower minor' => ['version' => 'v1.29.0', 'verified' => true],
    'the next minor' => ['version' => 'v1.31.0', 'verified' => false],
    'a two-digit minor' => ['version' => 'v1.300.0', 'verified' => false],
    'the next major' => ['version' => 'v2.0.0', 'verified' => false],
    'a named branch' => ['version' => 'dev-main', 'verified' => false],
    'a branch alias on the verified minor' => ['version' => '1.30.x-dev', 'verified' => false],
    'no version' => ['version' => '', 'verified' => false],
]);
