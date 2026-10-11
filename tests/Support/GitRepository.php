<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

class GitRepository
{
    /**
     * The options every git call runs with, so a commit needs no global identity or signing key.
     */
    protected const IDENTITY = ['-c', 'user.name=Firewatch', '-c', 'user.email=firewatch@example.com', '-c', 'commit.gpgsign=false', '-c', 'protocol.file.allow=always'];

    /**
     * Create a repository on the main branch with a first commit.
     */
    public static function create(string $format = 'sha1'): static
    {
        return static::init($format)->commit();
    }

    /**
     * Create a repository on the main branch with no commit yet.
     */
    public static function init(string $format = 'sha1'): static
    {
        $repository = new static(static::temporaryPath());

        (new Filesystem)->ensureDirectoryExists($repository->path);

        $repository->run('init', '--initial-branch=main', "--object-format={$format}");

        return $repository;
    }

    /**
     * Get a new path in the temporary directory.
     */
    public static function temporaryPath(): string
    {
        return sys_get_temp_dir().'/firewatch-git/'.bin2hex(random_bytes(8));
    }

    /**
     * Create a new handle on the repository at a path.
     */
    public function __construct(public readonly string $path)
    {
        //
    }

    /**
     * Commit a change to the working tree.
     *
     * @return $this
     */
    public function commit(): static
    {
        file_put_contents("{$this->path}/file.txt", bin2hex(random_bytes(8)));

        $this->run('add', '.');
        $this->run('commit', '--message=change');

        return $this;
    }

    /**
     * Get the commit HEAD points to, as git itself resolves it.
     */
    public function head(): string
    {
        return $this->run('rev-parse', 'HEAD');
    }

    /**
     * Run a git command in the repository and get its trimmed output.
     *
     * @throws RuntimeException
     */
    public function run(string ...$arguments): string
    {
        $process = new Process(['git', ...static::IDENTITY, ...$arguments], $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Delete the repository's directory.
     */
    public function delete(): void
    {
        (new Filesystem)->deleteDirectory($this->path);
    }
}
