<?php

use ClaudioDekker\Firewatch\Configuration\ConfigurationNormaliser;
use ClaudioDekker\Firewatch\Store\FileIdentity;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Filesystem\Filesystem;

function writerWith(?FileIdentity $identity = null, ?Closure $pid = null): Writer
{
    $directory = sys_get_temp_dir().'/firewatch-writer-tests/'.bin2hex(random_bytes(8));
    $normaliser = new ConfigurationNormaliser(basePath: '/app', publicPath: '/app/public', storagePath: '/app/storage');

    test()->directories = [...test()->directories, $directory];

    return new Writer($normaliser->resolve(['database' => $directory.'/firewatch.sqlite']), sqliteVersion: '3.51.3', identity: $identity, pid: $pid);
}

beforeEach(function () {
    $this->directories = [];
});

afterEach(function () {
    foreach ($this->directories as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
});

function fakeIdentity(?string &$value): FileIdentity
{
    return new class($value) extends FileIdentity
    {
        public function __construct(protected ?string &$value)
        {
            //
        }

        public function of(string $path): ?string
        {
            return $this->value;
        }
    };
}

function connectionOf(Writer $writer): SQLite3
{
    return $writer->transaction(fn (SQLite3 $connection) => $connection);
}

it('keeps its connection while the store keeps its identity', function (?string $identity) {
    $writer = writerWith(fakeIdentity($identity));

    $first = connectionOf($writer);
    $second = connectionOf($writer);

    expect($second)->toBe($first);
})->with([
    'an identity' => ['16:1234'],
    'no usable identity, as on Windows' => [null],
]);

it('reopens its connection once the identity of the store changes', function (?string $before, ?string $after) {
    $identity = $before;
    $writer = writerWith(fakeIdentity($identity));
    $first = connectionOf($writer);

    $identity = $after;
    $second = connectionOf($writer);

    expect($second)->not->toBe($first);
})->with([
    'another inode' => ['16:1234', '16:1235'],
    'another device' => ['16:1234', '17:1234'],
    'a store that is gone' => ['16:1234', null],
    'a store that appeared' => [null, '16:1234'],
]);

it('keeps its connection while the pid is unchanged and reopens it when the pid changes', function () {
    $pid = 100;
    $writer = writerWith(pid: function () use (&$pid) {
        return $pid;
    });
    $first = connectionOf($writer);
    $same = connectionOf($writer);

    $pid = 101;
    $forked = connectionOf($writer);

    expect($same)->toBe($first)
        ->and($forked)->not->toBe($first);
});
