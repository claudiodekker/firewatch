<?php

use Illuminate\Foundation\AliasLoader;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;

it('registers Nightwatch\'s provider itself', function () {
    $provider = app()->getProvider(NightwatchServiceProvider::class);

    expect($provider)->toBeInstanceOf(NightwatchServiceProvider::class);
});

it('registers the Nightwatch facade alias itself', function () {
    $aliases = AliasLoader::getInstance()->getAliases();

    expect($aliases)->toHaveKey('Nightwatch', Nightwatch::class);
});
