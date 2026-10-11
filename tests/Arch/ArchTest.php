<?php

use ClaudioDekker\Firewatch\GitHead;
use ClaudioDekker\Firewatch\Ingest;
use ClaudioDekker\Firewatch\IngestReplacer;
use ClaudioDekker\Firewatch\NullIngest;
use ClaudioDekker\Firewatch\Tests\Support\PackageSource;

const OFF_MACHINE = [
    'fsockopen',
    'pfsockopen',
    'stream_socket_client',
    'GuzzleHttp',
    'Illuminate\\Http\\Client',
    'Illuminate\\Support\\Facades\\Http',
    'Illuminate\\Mail',
    'Illuminate\\Support\\Facades\\Mail',
    'Illuminate\\Notifications',
    'Illuminate\\Support\\Facades\\Notification',
];

const NIGHTWATCH_INGEST = [
    'Laravel\\Nightwatch\\Console',
    'Laravel\\Nightwatch\\Contracts\\Ingest',
    'Laravel\\Nightwatch\\Ingest',
    'Laravel\\Nightwatch\\SocketStreamFactory',
];

test('the package source never calls env()', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token, array $tokens, int $index) => PackageSource::calledFunction($token, $tokens, $index) === 'env');

    expect($offences)->toBe([]);
});

test('the package source writes to the PHP error log only through its notices', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token, array $tokens, int $index) => PackageSource::calledFunction($token, $tokens, $index) === 'error_log');

    expect($offences)->each->toStartWith('src/ErrorLogNotices.php:');
});

test('the package source holds no debugging or process-ending calls', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token, array $tokens, int $index) => $token->is(T_EXIT)
        || in_array(PackageSource::calledFunction($token, $tokens, $index), ['dd', 'dump', 'var_dump', 'print_r'], true));

    expect($offences)->toBe([]);
});

arch('classes stay open to extension')
    ->expect('ClaudioDekker\Firewatch')
    ->classes()
    ->not->toBeFinal();

test('every command signature starts with firewatch:', function () {
    $names = PackageSource::commandNames();

    $offences = array_values(array_filter($names, fn (string $name) => ! str_starts_with($name, 'firewatch:')));

    expect($offences)->toBe([]);
});

test('every command and tool is a supported entry point', function () {
    $offences = PackageSource::classesWithoutTag(['Console\\Commands', 'Mcp\\Tools'], 'api');

    expect($offences)->toBe([]);
});

arch('nothing in the package source can leave the machine')
    ->expect('ClaudioDekker\\Firewatch')
    ->not->toUse([...OFF_MACHINE, ...NIGHTWATCH_INGEST])
    ->ignoring([Ingest::class, IngestReplacer::class, NullIngest::class]);

foreach (['Ingest' => Ingest::class, 'IngestReplacer' => IngestReplacer::class, 'NullIngest' => NullIngest::class] as $name => $ingest) {
    arch("{$name} reaches Nightwatch's ingest contract and nothing else off the machine")
        ->expect($ingest)
        ->not->toUse([...OFF_MACHINE, ...array_diff(NIGHTWATCH_INGEST, ['Laravel\\Nightwatch\\Contracts\\Ingest'])]);
}

foreach (['Store', 'Actions'] as $area) {
    arch("{$area} is never reached through Laravel's database layer")
        ->expect("ClaudioDekker\\Firewatch\\{$area}")
        ->not->toUse(['Illuminate\\Database', 'Illuminate\\Support\\Facades\\DB']);
}

test('the store and its actions never name PDO, which arch cannot see', function () {
    $isPdo = fn (PhpToken $token) => $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ltrim($token->text, '\\') === 'PDO';

    expect([...PackageSource::offendingTokens('Store', $isPdo), ...PackageSource::offendingTokens('Actions', $isPdo)])->toBe([]);
});

test('nothing in the package source calls a network or mail function', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token, array $tokens, int $index) => preg_match(
        '/^(curl_|socket_|mail$|fsockopen$|pfsockopen$|stream_socket_client$)/',
        PackageSource::calledFunction($token, $tokens, $index) ?? '',
    ) === 1);

    expect($offences)->toBe([]);
});

test('nothing on the server path writes to standard output', function () {
    $offences = PackageSource::offendingTokens(PackageSource::SERVER_PATH, PackageSource::writesToStdout(...));

    expect($offences)->toBe([]);
});

arch('nothing in the package source needs the intl extension, which composer.json does not require')
    ->expect('ClaudioDekker\\Firewatch')
    ->not->toUse('Illuminate\\Support\\Number');

test('nothing in the package source names NumberFormatter, which arch cannot see', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token) => $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ltrim($token->text, '\\') === 'NumberFormatter');

    expect($offences)->toBe([]);
});

arch('the SQL child references nothing outside itself')
    ->expect(PackageSource::SQL_CHILD_NAMESPACE)
    ->toOnlyUse(PackageSource::SQL_CHILD_NAMESPACE);

test('the SQL child script references no framework or application code', function () {
    $offences = PackageSource::offendingTokens(PackageSource::SQL_CHILD_PATH, function (PhpToken $token, array $tokens, int $index) {
        $function = PackageSource::calledFunction($token, $tokens, $index);

        if ($function !== null) {
            return PackageSource::isLibraryFunction($function);
        }

        return $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && str_contains(ltrim($token->text, '\\'), '\\')
            && ! str_starts_with(ltrim($token->text, '\\').'\\', PackageSource::SQL_CHILD_NAMESPACE.'\\');
    });

    expect($offences)->toBe([]);
});

test('no keyed array with several elements is written on one line', function () {
    expect(PackageSource::inlineKeyedArrays())->toBe([]);
});

foreach (['Store', 'Actions', 'Capture', 'Configuration', 'Ingest'] as $area) {
    arch("{$area} never reaches the side that answers the assistant")
        ->expect("ClaudioDekker\\Firewatch\\{$area}")
        ->not->toUse('ClaudioDekker\\Firewatch\\Mcp');
}

arch('the git HEAD reader starts no process, because it runs at every boot')
    ->expect(GitHead::class)
    ->not->toUse(['proc_open', 'popen', 'exec', 'shell_exec', 'system', 'passthru', 'pcntl_exec', 'Symfony\\Component\\Process', 'Illuminate\\Support\\Facades\\Process', 'Illuminate\\Process']);

arch('every method in the package source is documented')
    ->expect('ClaudioDekker\\Firewatch')
    ->toHaveMethodsDocumented();

arch('every property in the package source is documented')
    ->expect('ClaudioDekker\\Firewatch')
    ->toHavePropertiesDocumented();

test('every constant in the package source is documented', function () {
    expect(PackageSource::undocumentedConstants())->toBe([]);
});

test('every class in the package source is marked internal or a supported entry point', function () {
    expect(PackageSource::declarationsWithoutSurfaceTag())->toBe([]);
});
