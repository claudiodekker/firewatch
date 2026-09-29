<?php

use ClaudioDekker\Firewatch\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Exceptions::fake();
    })
    ->in('Scenario', 'Feature', 'Contract');

pest()->group('scenario')->in('Scenario');
pest()->group('feature')->in('Feature');
pest()->group('contract')->in('Contract');
pest()->group('unit')->in('Unit');
pest()->group('arch')->in('Arch');
