<?php

use ClaudioDekker\Firewatch\Tests\Support\PackageSource;

test('the package source never calls env()', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token, array $tokens, int $index) => PackageSource::calledFunction($token, $tokens, $index) === 'env');

    expect($offences)->toBe([]);
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

arch('nothing in the package source can leave the machine')
    ->expect('ClaudioDekker\Firewatch')
    ->not->toUse([
        'fsockopen',
        'pfsockopen',
        'stream_socket_client',
        'GuzzleHttp',
        'Illuminate\Http\Client',
        'Illuminate\Support\Facades\Http',
        'Illuminate\Mail',
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Notifications',
        'Illuminate\Support\Facades\Notification',
        'Laravel\Nightwatch\Console',
        'Laravel\Nightwatch\Contracts\Ingest',
        'Laravel\Nightwatch\Ingest',
        'Laravel\Nightwatch\SocketStreamFactory',
    ]);

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
