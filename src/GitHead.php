<?php

namespace ClaudioDekker\Firewatch;

use Throwable;

/**
 * The commit the git HEAD of the repository around a path points to, read from the repository's files.
 *
 * It starts a process never: it runs at every boot of the application.
 *
 * @internal
 */
class GitHead
{
    protected const COMMIT = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i';

    protected const ABSOLUTE_PATH = '#^(?:[A-Za-z]:)?[\\\\/]#';

    /**
     * Create a new reader that looks for the repository at a directory, then at each parent.
     */
    public function __construct(protected string $start)
    {
        //
    }

    /**
     * Get the full lowercase commit HEAD points to, or null when there is none to read.
     */
    public function commit(): ?string
    {
        try {
            return $this->resolve();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Follow the git directory to HEAD and HEAD to a commit.
     */
    protected function resolve(): ?string
    {
        $directory = $this->gitDirectory();
        $head = $directory === null ? null : $this->read("{$directory}/HEAD");

        if ($head === null) {
            return null;
        }

        if (preg_match(static::COMMIT, $head) === 1) {
            return strtolower($head);
        }

        $ref = str_starts_with($head, 'ref:') ? trim(substr($head, 4)) : '';

        // A reftable repository keeps the placeholder `refs/heads/.invalid` here, which no file resolves.
        if (! str_starts_with($ref, 'refs/') || str_contains($ref, '..')) {
            return null;
        }

        $common = $this->commonDirectory($directory);

        return $this->looseRef($common, $ref) ?? $this->packedRef($common, $ref);
    }

    /**
     * Find the git directory: the `.git` directory at the start or a parent, or the one a `.git` file names.
     */
    protected function gitDirectory(): ?string
    {
        $directory = $this->start;

        while (true) {
            $git = "{$directory}/.git";

            if (is_dir($git)) {
                return $git;
            }

            if (is_file($git)) {
                return $this->linkedDirectory($git, $directory);
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }

    /**
     * Get the directory a linked worktree's or submodule's `.git` file names.
     */
    protected function linkedDirectory(string $file, string $directory): ?string
    {
        $line = $this->read($file);

        if ($line === null || ! str_starts_with($line, 'gitdir:')) {
            return null;
        }

        $path = $this->resolvePath(trim(substr($line, 7)), $directory);

        return is_dir($path) ? $path : null;
    }

    /**
     * Get the directory that holds the refs: the one a worktree's `commondir` file names, or the git directory itself.
     */
    protected function commonDirectory(string $directory): string
    {
        $path = $this->read("{$directory}/commondir");

        if ($path === null || $path === '') {
            return $directory;
        }

        $common = $this->resolvePath($path, $directory);

        return is_dir($common) ? $common : $directory;
    }

    /**
     * Get the commit a loose ref file holds.
     */
    protected function looseRef(string $common, string $ref): ?string
    {
        $contents = $this->read("{$common}/{$ref}");

        return $contents !== null && preg_match(static::COMMIT, $contents) === 1 ? strtolower($contents) : null;
    }

    /**
     * Get the commit a ref has in `packed-refs`, skipping its header and peeled lines.
     */
    protected function packedRef(string $common, string $ref): ?string
    {
        $contents = $this->read("{$common}/packed-refs");

        foreach (preg_split('/\r?\n/', $contents ?? '') ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            [$commit, $name] = array_pad(explode(' ', $line, 2), 2, '');

            if ($name === $ref && preg_match(static::COMMIT, $commit) === 1) {
                return strtolower($commit);
            }
        }

        return null;
    }

    /**
     * Anchor a relative path at a directory.
     */
    protected function resolvePath(string $path, string $directory): string
    {
        return preg_match(static::ABSOLUTE_PATH, $path) === 1 ? $path : "{$directory}/{$path}";
    }

    /**
     * Get a file's trimmed contents, or null when it can't be read.
     */
    protected function read(string $path): ?string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents === false ? null : trim($contents);
    }
}
