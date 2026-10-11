<?php

namespace ClaudioDekker\Firewatch;

use Throwable;

/**
 * @internal
 */
class GitHead
{
    protected const COMMIT = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i';

    protected const ABSOLUTE_PATH = '#^(?:[A-Za-z]:)?[\\\\/]#';

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

    protected function resolve(): ?string
    {
        $directory = $this->gitDirectory();
        $head = $directory === null ? null : $this->read("{$directory}/HEAD");

        if ($head === null) {
            return null;
        }

        if (($commit = $this->commitFrom($head)) !== null) {
            return $commit;
        }

        $ref = str_starts_with($head, 'ref:') ? trim(substr($head, 4)) : '';

        // A reftable repository keeps the placeholder `refs/heads/.invalid` here, which no file resolves.
        if (! str_starts_with($ref, 'refs/') || str_contains($ref, '..')) {
            return null;
        }

        $common = $this->commonDirectory($directory);

        return $this->looseRef($common, $ref) ?? $this->packedRef($common, $ref);
    }

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

    protected function linkedDirectory(string $file, string $directory): ?string
    {
        $line = $this->read($file);

        if ($line === null || ! str_starts_with($line, 'gitdir:')) {
            return null;
        }

        $path = $this->resolvePath(trim(substr($line, 7)), $directory);

        return is_dir($path) ? $path : null;
    }

    protected function commonDirectory(string $directory): string
    {
        $path = $this->read("{$directory}/commondir");

        if ($path === null || $path === '') {
            return $directory;
        }

        $common = $this->resolvePath($path, $directory);

        return is_dir($common) ? $common : $directory;
    }

    protected function looseRef(string $common, string $ref): ?string
    {
        $contents = $this->read("{$common}/{$ref}");

        return $contents === null ? null : $this->commitFrom($contents);
    }

    protected function commitFrom(string $text): ?string
    {
        return preg_match(static::COMMIT, $text) === 1 ? strtolower($text) : null;
    }

    protected function packedRef(string $common, string $ref): ?string
    {
        $contents = $this->read("{$common}/packed-refs");

        foreach (preg_split('/\r?\n/', $contents ?? '') ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            [$commit, $name] = array_pad(explode(' ', $line, 2), 2, '');

            if ($name === $ref) {
                return $this->commitFrom($commit);
            }
        }

        return null;
    }

    protected function resolvePath(string $path, string $directory): string
    {
        return preg_match(static::ABSOLUTE_PATH, $path) === 1 ? $path : "{$directory}/{$path}";
    }

    protected function read(string $path): ?string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents === false ? null : trim($contents);
    }
}
