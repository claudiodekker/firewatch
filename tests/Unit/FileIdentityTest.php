<?php

use ClaudioDekker\Firewatch\Store\FileIdentity;

function fileIdentityPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'firewatch-identity-');

    test()->afterEach(fn () => @unlink($path));

    return $path;
}

it('names no identity for a path that is no file', function () {
    $path = fileIdentityPath();
    unlink($path);

    expect((new FileIdentity)->of($path))->toBeNull();
});

it('keeps the identity of a file that is written to', function () {
    $path = fileIdentityPath();
    $before = (new FileIdentity)->of($path);

    file_put_contents($path, 'written');

    expect((new FileIdentity)->of($path))->toBe($before)->not->toBeNull();
});

it('tells two files apart', function () {
    expect((new FileIdentity)->of(fileIdentityPath()))->not->toBe((new FileIdentity)->of(fileIdentityPath()));
});

it('changes with the file when it is deleted and created again', function () {
    $path = fileIdentityPath();
    $other = fileIdentityPath();
    $before = (new FileIdentity)->of($path);

    rename($other, $path);

    expect((new FileIdentity)->of($path))->not->toBe($before);
})->group('posix');

it('sees a file deleted by another process although it was just looked at', function () {
    $path = fileIdentityPath();
    $identity = new FileIdentity;
    $before = $identity->of($path);

    exec('rm -f '.escapeshellarg($path));

    expect($before)->not->toBeNull()
        ->and($identity->of($path))->toBeNull();
})->group('process', 'posix');

it('names a file by its device and inode', function () {
    $path = fileIdentityPath();
    $stat = stat($path);

    expect((new FileIdentity)->of($path))->toBe($stat['dev'].':'.$stat['ino']);
});
