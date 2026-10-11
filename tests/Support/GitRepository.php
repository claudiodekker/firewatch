<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A repository built with the real git CLI in a temporary directory, to give the reader files git itself wrote.
 */
class GitRepository
{
    protected const IDENTITY = ['-c', 'user.name=Firewatch', '-c', 'user.email=firewatch@example.com', '-c', 'commit.gpgsign=false', '-c', 'protocol.file.allow=always'];

    /**
     * Create a repository on a branch, with a first commit or without one.
     */
    public static function create(bool $commit = true, string $format = 'sha1'): self
    {
        $repository = new self(static::temporaryPath());

        (new Filesystem)->ensureDirectoryExists($repository->path);

        $repository->run('init', '--initial-branch=main', "--object-format={$format}");

        return $commit ? $repository->commit() : $repository;
    }

    public static function temporaryPath(): string
    {
        return sys_get_temp_dir().'/firewatch-git/'.bin2hex(random_bytes(8));
    }

    public function __construct(public readonly string $path)
    {
        //
    }

    /**
     * Commit a change.
     */
    public function commit(): self
    {
        file_put_contents("{$this->path}/file.txt", bin2hex(random_bytes(8)));

        $this->run('add', '.');
        $this->run('commit', '--message=change');

        return $this;
    }

    public function head(): string
    {
        return $this->run('rev-parse', 'HEAD');
    }

    public function run(string ...$arguments): string
    {
        $process = new Process(['git', ...static::IDENTITY, ...$arguments], $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    public function delete(): void
    {
        (new Filesystem)->deleteDirectory($this->path);
    }
}
