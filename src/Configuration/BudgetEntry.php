<?php

namespace ClaudioDekker\Firewatch\Configuration;

use ClaudioDekker\Firewatch\ExecutionType;
use ClaudioDekker\Firewatch\Mcp\Stored;
use Illuminate\Support\Str;

/**
 * @internal
 */
readonly class BudgetEntry
{
    /**
     * Create a new budget entry instance.
     *
     * @param  list<string>|null  $methods
     */
    public function __construct(
        public int $number,
        public ExecutionType $type,
        public ?array $methods,
        public ?string $path,
        public ?string $name,
        public int|float|null $durationMilliseconds,
        public int|float|null $memoryMegabytes,
    ) {
        //
    }

    /**
     * Determine if the entry has no matcher and so governs what the type's specific entries do not.
     */
    public function isGlobal(): bool
    {
        return $this->methods === null && $this->path === null && $this->name === null;
    }

    /**
     * Determine if the entry has a matcher and every matcher it has matches the stored row.
     *
     * @param  array<string, mixed>  $row
     */
    public function matches(array $row): bool
    {
        if ($this->isGlobal()) {
            return false;
        }

        return $this->matchesMethods($row) && $this->matchesPath($row) && $this->matchesName($row);
    }

    /**
     * Determine if every method of the entry is one of the route's methods.
     *
     * @param  array<string, mixed>  $row
     */
    protected function matchesMethods(array $row): bool
    {
        if ($this->methods === null) {
            return true;
        }

        $routeMethods = Stored::json($row['route_methods'] ?? null);

        return is_array($routeMethods) && array_diff($this->methods, $routeMethods) === [];
    }

    /**
     * Determine if the route's path definition matches, ignoring a leading slash on either side.
     *
     * @param  array<string, mixed>  $row
     */
    protected function matchesPath(array $row): bool
    {
        if ($this->path === null) {
            return true;
        }

        $path = Stored::blank($row['route_path'] ?? null);

        return is_string($path) && Str::is(ltrim($this->path, '/'), ltrim($path, '/'));
    }

    /**
     * Determine if the name matches.
     *
     * @param  array<string, mixed>  $row
     */
    protected function matchesName(array $row): bool
    {
        if ($this->name === null) {
            return true;
        }

        $name = $row['name'] ?? null;

        return is_string($name) && Str::is($this->name, $name);
    }
}
