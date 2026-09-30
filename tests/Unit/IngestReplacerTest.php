<?php

use ClaudioDekker\Firewatch\IngestReplacer;
use ClaudioDekker\Firewatch\NullIngest;
use Laravel\Nightwatch\Contracts\Ingest;

it('replaces the ingest on a core with a public, writable ingest property', function () {
    $core = new class
    {
        public Ingest $ingest;
    };

    (new IngestReplacer)->replace($core);

    expect($core->ingest)->toBeInstanceOf(NullIngest::class);
});

it('refuses a core whose ingest property it cannot assign', function (object $core) {
    $replace = fn () => (new IngestReplacer)->replace($core);

    expect($replace)->toThrow(RuntimeException::class, 'its core has no assignable ingest property');
})->with([
    'no property' => fn () => new class {},
    'protected' => fn () => new class
    {
        protected Ingest $ingest;
    },
    'static' => fn () => new class
    {
        public static Ingest $ingest;
    },
    'readonly' => fn () => new class
    {
        public readonly Ingest $ingest;
    },
    'another type' => fn () => new class
    {
        public stdClass $ingest;
    },
    'untyped' => fn () => new class
    {
        public $ingest;
    },
]);

it('refuses when the ingest interface\'s signatures changed', function () {
    $core = new class
    {
        public Ingest $ingest;
    };
    $replacer = new class extends IngestReplacer
    {
        protected const EXPECTED_SIGNATURES = ['write(array $record): void'];
    };

    $replace = fn () => $replacer->replace($core);

    expect($replace)->toThrow(RuntimeException::class, 'its ingest interface changed');
});
