<?php

use Workbench\App\Fixtures\Producer;
use Workbench\App\Fixtures\WireFixture;

it('matches each committed wire fixture\'s fields and their kinds with what the sensors write', function (Producer $producer) {
    $fixtures = app(WireFixture::class);

    $differences = $fixtures->differences($producer, $fixtures->produce($producer));

    expect($differences)->toBe([], 'The sensors changed shape: run `composer fixtures` and raise the verified line in the same pull request.');
})->with(Producer::cases());
