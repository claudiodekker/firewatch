<?php

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

arch('only Firewatch\'s ingests and their replacer reach Nightwatch\'s ingest contract')
    ->expect([Ingest::class, IngestReplacer::class, NullIngest::class])
    ->not->toUse([...OFF_MACHINE, ...array_diff(NIGHTWATCH_INGEST, ['Laravel\\Nightwatch\\Contracts\\Ingest'])]);

arch('the store is never reached through Laravel\'s database layer')
    ->expect(['ClaudioDekker\\Firewatch\\Store', 'ClaudioDekker\\Firewatch\\Actions'])
    ->not->toUse(['Illuminate\\Database', 'Illuminate\\Support\\Facades\\DB', 'PDO']);

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
