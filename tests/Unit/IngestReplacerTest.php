<?php

use ClaudioDekker\Firewatch\IngestReplacer;
use ClaudioDekker\Firewatch\NullIngest;
use Laravel\Nightwatch\Contracts\Ingest;

interface MatchingIngest
{
    public function write(array $record): void;
}

interface ByReferenceIngest
{
    public function write(array &$record): void;
}

interface StaticIngest
{
    public static function write(array $record): void;
}

interface ReferenceReturningIngest
{
    public function &write(array $record): array;
}

interface VariadicIngest
{
    public function write(array ...$record): void;
}

interface WiderIngest
{
    public function write(array $record, bool $now = false): void;
}

/**
 * @param  list<string>  $expected
 */
function ingestReplacerFor(string $interface, array $expected = ['write(array $record): void']): IngestReplacer
{
    $replacer = new class extends IngestReplacer
    {
        public static string $interface;

        /** @var list<string> */
        public static array $expected;

        protected function interface(): string
        {
            return self::$interface;
        }

        protected function expectedSignatures(): array
        {
            return self::$expected;
        }
    };

    [$replacer::$interface, $replacer::$expected] = [$interface, $expected];

    return $replacer;
}

function assignableCore(): object
{
    return new class
    {
        public Ingest $ingest;
    };
}

it('replaces the ingest when the interface\'s signatures match', function () {
    $core = assignableCore();

    ingestReplacerFor(MatchingIngest::class)->replace($core, fn () => new NullIngest);

    expect($core->ingest)->toBeInstanceOf(NullIngest::class);
});

it('refuses when the ingest interface\'s signatures changed', function (string $interface, array $expected) {
    $replace = fn () => ingestReplacerFor($interface, $expected)->replace(assignableCore(), fn () => new NullIngest);

    expect($replace)->toThrow(RuntimeException::class, 'its ingest interface changed');
})->with([
    'a by-reference parameter' => ['interface' => ByReferenceIngest::class, 'expected' => ['write(array $record): void']],
    'a static method' => ['interface' => StaticIngest::class, 'expected' => ['write(array $record): void']],
    'a by-reference return' => ['interface' => ReferenceReturningIngest::class, 'expected' => ['write(array $record): array']],
    'a variadic parameter' => ['interface' => VariadicIngest::class, 'expected' => ['write(array $record): void']],
    'an added parameter' => ['interface' => WiderIngest::class, 'expected' => ['write(array $record): void']],
    'a missing interface' => ['interface' => 'Laravel\Nightwatch\Contracts\MissingIngest', 'expected' => ['write(array $record): void']],
]);

it('refuses a core whose ingest property it cannot assign', function (object $core) {
    $replace = fn () => (new IngestReplacer)->replace($core, fn () => new NullIngest);

    expect($replace)->toThrow(RuntimeException::class, 'its core has no assignable ingest property');
})->with([
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
