<?php

namespace ClaudioDekker\Firewatch\Configuration;

use ClaudioDekker\Firewatch\ExecutionType;
use Illuminate\Support\Arr;

/**
 * @internal
 */
readonly class Budgets
{
    /**
     * The HTTP verbs a request budget may match.
     */
    protected const HTTP_VERBS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * The matchers a request budget entry may hold.
     */
    protected const REQUEST_MATCHERS = ['methods', 'path'];

    /**
     * The matchers a command, job attempt or scheduled task budget entry may hold.
     */
    protected const NAME_MATCHERS = ['name'];

    /**
     * The largest duration ceiling accepted.
     */
    protected const MAXIMUM_DURATION_MILLISECONDS = 3600000;

    /**
     * The largest memory ceiling accepted.
     */
    protected const MAXIMUM_MEMORY_MEGABYTES = 65536;

    /**
     * Create a new budgets instance.
     *
     * @param  list<BudgetEntry>  $entries
     * @param  list<ConfigurationIssue>  $issues
     */
    public function __construct(
        public array $entries,
        public int $ignored,
        public array $issues,
    ) {
        //
    }

    /**
     * Read the budget entries of the raw configuration, numbered by their place in the file, and the issues of the ones ignored.
     *
     * @param  array<mixed>  $raw
     */
    public static function parse(array $raw): self
    {
        if (! array_key_exists('budgets', $raw)) {
            return new self([], 0, []);
        }

        if (! is_array($raw['budgets'])) {
            return new self([], 0, [ConfigurationIssue::fallBack(key: 'budgets', reason: ConfigurationIssue::describe($raw['budgets']).' is not a list of budget entries', default: 'none')]);
        }

        $entries = [];
        $issues = [];

        foreach (array_values($raw['budgets']) as $index => $entry) {
            $number = $index + 1;
            $reason = static::refusal($entry);

            if ($reason !== null) {
                $issues[] = ConfigurationIssue::ignoredBudgetEntry(number: $number, reason: $reason);

                continue;
            }

            /** @var array{type: string, methods?: list<string>, path?: string, name?: string, duration?: int|float, memory?: int|float} $entry */
            $entries[] = new BudgetEntry(
                number: $number,
                type: ExecutionType::from($entry['type']),
                methods: isset($entry['methods']) ? array_map(strtoupper(...), $entry['methods']) : null,
                path: $entry['path'] ?? null,
                name: $entry['name'] ?? null,
                durationMilliseconds: $entry['duration'] ?? null,
                memoryMegabytes: $entry['memory'] ?? null,
            );
        }

        return new self($entries, count($raw['budgets']) - count($entries), $issues);
    }

    /**
     * Determine if no entry is valid.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Get the entry that governs the execution: the first specific entry of the type that matches, else the type's first global entry.
     *
     * @param  array<string, mixed>  $row
     */
    public function governing(ExecutionType $type, array $row): ?BudgetEntry
    {
        $entries = array_filter($this->entries, fn (BudgetEntry $entry) => $entry->type === $type);

        foreach ($entries as $entry) {
            if ($entry->matches($row)) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if ($entry->isGlobal()) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Get the numbers of the global entries that an earlier global entry of the same type shadows.
     *
     * @return list<int>
     */
    public function shadowed(): array
    {
        $governed = [];
        $shadowed = [];

        foreach ($this->entries as $entry) {
            if (! $entry->isGlobal()) {
                continue;
            }

            if (isset($governed[$entry->type->value])) {
                $shadowed[] = $entry->number;
            }

            $governed[$entry->type->value] = true;
        }

        return $shadowed;
    }

    /**
     * Get the reason a budget entry is ignored, or null when it is valid.
     */
    protected static function refusal(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return ConfigurationIssue::describe($entry).' is not a budget entry';
        }

        if (! array_key_exists('type', $entry)) {
            return 'type is missing';
        }

        $type = is_string($entry['type']) ? ExecutionType::tryFrom($entry['type']) : null;

        if ($type === null) {
            $types = array_map(fn (ExecutionType $type) => $type->value, ExecutionType::cases());

            return 'type '.ConfigurationIssue::describe($entry['type']).' is not '.Arr::join($types, ', ', ' or ');
        }

        return static::refusedKeys($entry, $type)
            ?? static::refusedMethods($entry)
            ?? static::refusedPattern($entry, 'path')
            ?? static::refusedPattern($entry, 'name')
            ?? static::refusedCeiling($entry, 'duration', static::MAXIMUM_DURATION_MILLISECONDS)
            ?? static::refusedCeiling($entry, 'memory', static::MAXIMUM_MEMORY_MEGABYTES)
            ?? (isset($entry['duration']) || isset($entry['memory']) ? null : 'no duration or memory ceiling');
    }

    /**
     * Get the reason a budget entry holds a key its type does not allow.
     *
     * @param  array<mixed>  $entry
     */
    protected static function refusedKeys(array $entry, ExecutionType $type): ?string
    {
        $matchers = match ($type) {
            ExecutionType::REQUEST => static::REQUEST_MATCHERS,
            ExecutionType::COMMAND, ExecutionType::JOB_ATTEMPT, ExecutionType::SCHEDULED_TASK => static::NAME_MATCHERS,
        };

        foreach (array_keys($entry) as $key) {
            if (in_array($key, ['type', 'duration', 'memory', ...$matchers], true)) {
                continue;
            }

            return in_array($key, [...static::REQUEST_MATCHERS, ...static::NAME_MATCHERS], true)
                ? "matcher \"{$key}\" is not allowed for type {$type->value}"
                : 'key '.ConfigurationIssue::describe($key).' is not allowed';
        }

        return null;
    }

    /**
     * Get the reason a budget entry's methods are refused.
     *
     * @param  array<mixed>  $entry
     */
    protected static function refusedMethods(array $entry): ?string
    {
        if (! array_key_exists('methods', $entry)) {
            return null;
        }

        $methods = $entry['methods'];

        if (! is_array($methods) || $methods === [] || ! array_is_list($methods)) {
            return 'methods '.ConfigurationIssue::describe($methods).' is not a non-empty list of HTTP verbs';
        }

        foreach ($methods as $method) {
            if (! is_string($method) || ! in_array(strtoupper($method), static::HTTP_VERBS, true)) {
                return 'method '.ConfigurationIssue::describe($method).' is not '.Arr::join(static::HTTP_VERBS, ', ', ' or ');
            }
        }

        return null;
    }

    /**
     * Get the reason a budget entry's path or name pattern is refused.
     *
     * @param  array<mixed>  $entry
     */
    protected static function refusedPattern(array $entry, string $matcher): ?string
    {
        if (! array_key_exists($matcher, $entry) || is_string($entry[$matcher])) {
            return null;
        }

        return "{$matcher} ".ConfigurationIssue::describe($entry[$matcher]).' is not a string';
    }

    /**
     * Get the reason a budget entry's duration or memory ceiling is refused.
     *
     * @param  array<mixed>  $entry
     */
    protected static function refusedCeiling(array $entry, string $ceiling, int $maximum): ?string
    {
        if (! array_key_exists($ceiling, $entry)) {
            return null;
        }

        $value = $entry[$ceiling];

        if ((is_int($value) || is_float($value)) && $value > 0 && $value <= $maximum) {
            return null;
        }

        return "{$ceiling} ".ConfigurationIssue::describe($value)." is not a number greater than 0 and at most {$maximum}";
    }
}
