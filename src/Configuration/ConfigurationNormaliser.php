<?php

namespace ClaudioDekker\Firewatch\Configuration;

use ClaudioDekker\Firewatch\ExecutionType;
use Closure;
use Illuminate\Support\Arr;

/**
 * @internal
 */
class ConfigurationNormaliser
{
    /**
     * The accepted boolean spellings and the values they stand for.
     */
    protected const BOOLEANS = [
        'true' => true,
        'false' => false,
        '1' => true,
        '0' => false,
        'yes' => true,
        'no' => false,
        'on' => true,
        'off' => false,
    ];

    /**
     * The seconds in each duration unit.
     */
    protected const DURATION_UNIT_SECONDS = [
        's' => 1,
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    /**
     * The busy timeout used when none is valid.
     */
    protected const DEFAULT_BUSY_TIMEOUT_MILLISECONDS = 300;

    /**
     * The longest busy timeout accepted.
     */
    protected const MAXIMUM_BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * The retention age used when none is valid.
     */
    protected const DEFAULT_RETENTION_AGE = '7d';

    /**
     * The record retention used when none is valid.
     */
    protected const DEFAULT_RETENTION_RECORDS = 100000;

    /**
     * The largest record retention accepted.
     */
    protected const MAXIMUM_RETENTION_RECORDS = 10000000;

    /**
     * The environments used when no valid name remains.
     */
    protected const DEFAULT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * The description of a valid environment name in an issue's reason.
     */
    protected const ENVIRONMENT_NAME_DESCRIPTION = 'an environment name (letters, digits, _ . -)';

    /**
     * The reason suffix for a list value that is neither an array nor a string.
     */
    protected const NOT_A_LIST = ' is not a list (an array or a comma-separated string)';

    /**
     * The characters of a string value quoted in an issue's reason.
     */
    protected const DESCRIBED_VALUE_CHARACTERS = 60;

    /**
     * The longest store path accepted, in bytes.
     */
    protected const MAXIMUM_PATH_BYTES = 4096;

    /**
     * The length a deploy identity is cut at, in bytes.
     */
    protected const MAXIMUM_DEPLOY_BYTES = 255;

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
     * The issues found while resolving the configuration.
     *
     * @var list<ConfigurationIssue>
     */
    protected array $issues = [];

    /**
     * Create a new configuration normaliser instance.
     */
    public function __construct(
        protected string $basePath,
        protected string $publicPath,
        protected string $storagePath,
    ) {}

    /**
     * Resolve the raw configuration into typed values and the issues found.
     *
     * @param  array<mixed>  $raw
     */
    public function resolve(array $raw): Configuration
    {
        $this->issues = [];

        $retention = $this->group($raw, 'retention');
        $capture = $this->group($raw, 'capture');

        $enabled = $this->boolean($raw, 'enabled', key: 'enabled', default: true);
        $environments = $this->environments($raw);
        $database = $this->database($raw);
        $busyTimeoutMilliseconds = $this->integer($raw, 'busy_timeout', key: 'busy_timeout', minimum: 0, maximum: static::MAXIMUM_BUSY_TIMEOUT_MILLISECONDS, default: static::DEFAULT_BUSY_TIMEOUT_MILLISECONDS);
        $retentionAge = $this->duration($retention, 'age', key: 'retention.age', default: static::DEFAULT_RETENTION_AGE);
        $retentionRecords = $this->integer($retention, 'records', key: 'retention.records', minimum: 1, maximum: static::MAXIMUM_RETENTION_RECORDS, default: static::DEFAULT_RETENTION_RECORDS);
        $deploy = $this->deploy($raw);
        $captureLogs = $this->boolean($capture, 'logs', key: 'capture.logs', default: true);
        $captureRequestPayload = $this->boolean($capture, 'request_payload', key: 'capture.request_payload', default: true);
        $redactPayloadFields = $this->redactList($capture, 'redact_payload_fields', key: 'capture.redact_payload_fields');
        $redactHeaders = $this->redactList($capture, 'redact_headers', key: 'capture.redact_headers');
        $budgets = $this->budgets($raw);

        return new Configuration(
            enabled: $enabled,
            environments: $environments,
            database: $database,
            busyTimeoutMilliseconds: $busyTimeoutMilliseconds,
            retentionAge: $retentionAge,
            retentionAgeSeconds: $this->durationSeconds($retentionAge),
            retentionRecords: $retentionRecords,
            deploy: $deploy,
            captureLogs: $captureLogs,
            captureRequestPayload: $captureRequestPayload,
            redactPayloadFields: $redactPayloadFields,
            redactHeaders: $redactHeaders,
            budgets: $budgets,
            ignoredBudgetEntries: $this->ignoredBudgetEntries($raw, $budgets),
            issues: $this->issues,
        );
    }

    /**
     * Get a nested group of keys, or none when the group is not an array.
     *
     * @param  array<mixed>  $raw
     * @return array<mixed>
     */
    protected function group(array $raw, string $name): array
    {
        $group = $raw[$name] ?? [];

        return is_array($group) ? $group : [];
    }

    /**
     * Read a boolean key, falling back to its default when the value is refused.
     *
     * @param  array<mixed>  $values
     */
    protected function boolean(array $values, string $name, string $key, bool $default): bool
    {
        if (! array_key_exists($name, $values)) {
            return $default;
        }

        $value = $values[$name];

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        $spelling = is_string($value) ? strtolower(trim($value)) : null;

        if ($spelling !== null && array_key_exists($spelling, static::BOOLEANS)) {
            return static::BOOLEANS[$spelling];
        }

        $accepted = implode(', ', array_keys(static::BOOLEANS));
        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: $this->describe($value)." is not a boolean ({$accepted})", default: $default ? 'true' : 'false');

        return $default;
    }

    /**
     * Read an integer key within its range, falling back to its default when the value is refused.
     *
     * @param  array<mixed>  $values
     */
    protected function integer(array $values, string $name, string $key, int $minimum, int $maximum, int $default): int
    {
        if (! array_key_exists($name, $values)) {
            return $default;
        }

        $value = $values[$name];
        $digits = match (true) {
            is_int($value) => (string) $value,
            is_string($value) => trim($value),
            default => '',
        };

        $integer = preg_match('/^[0-9]+$/', $digits) === 1
            ? filter_var(ltrim($digits, '0') ?: '0', FILTER_VALIDATE_INT, ['options' => [
                'min_range' => $minimum,
                'max_range' => $maximum,
            ]])
            : false;

        if ($integer !== false) {
            return $integer;
        }

        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: $this->describe($value)." is not an integer from {$minimum} to {$maximum}", default: (string) $default);

        return $default;
    }

    /**
     * Read a duration key, falling back to its default when the value is refused.
     *
     * @param  array<mixed>  $values
     */
    protected function duration(array $values, string $name, string $key, string $default): string
    {
        if (! array_key_exists($name, $values)) {
            return $default;
        }

        $value = $values[$name];

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,5}[smhdw]$/', $value) === 1) {
            return $value;
        }

        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: $this->describe($value)." is not a duration (digits then s, m, h, d or w, for example {$default})", default: $default);

        return $default;
    }

    /**
     * Convert a valid duration into seconds.
     */
    protected function durationSeconds(string $duration): int
    {
        $unit = substr($duration, -1);
        $count = intval(substr($duration, 0, -1));

        return $count * static::DURATION_UNIT_SECONDS[$unit];
    }

    /**
     * Read the environments a process captures in, using local and testing when none is valid.
     *
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    protected function environments(array $raw): array
    {
        if (! array_key_exists('environments', $raw)) {
            return static::DEFAULT_ENVIRONMENTS;
        }

        $default = implode(',', static::DEFAULT_ENVIRONMENTS);
        $environments = $this->list(
            $raw['environments'],
            key: 'environments',
            expected: static::ENVIRONMENT_NAME_DESCRIPTION,
            accepts: fn (string $item) => preg_match('/^[A-Za-z0-9_.-]+$/', $item) === 1,
        );

        if ($environments === null) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'environments', reason: $this->describe($raw['environments']).static::NOT_A_LIST, default: $default);

            return static::DEFAULT_ENVIRONMENTS;
        }

        if ($environments === []) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'environments', reason: 'no valid environment names', default: $default);

            return static::DEFAULT_ENVIRONMENTS;
        }

        return $environments;
    }

    /**
     * Read a list of fields or headers to redact, using none when the value is not a list.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    protected function redactList(array $values, string $name, string $key): array
    {
        if (! array_key_exists($name, $values)) {
            return [];
        }

        $items = $this->list($values[$name], key: $key, expected: 'a string', accepts: fn (string $item) => true);

        if ($items === null) {
            $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: $this->describe($values[$name]).static::NOT_A_LIST, default: 'none');

            return [];
        }

        return $items;
    }

    /**
     * Read a list from an array or a comma-separated string, or null when the value is neither.
     *
     * @param  Closure(string): bool  $accepts
     * @return list<string>|null
     */
    protected function list(mixed $value, string $key, string $expected, Closure $accepts): ?array
    {
        $items = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => null,
        };

        if ($items === null) {
            return null;
        }

        $kept = [];

        foreach ($items as $item) {
            $trimmed = is_string($item) ? trim($item) : null;

            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === null || ! $accepts($trimmed)) {
                $this->issues[] = ConfigurationIssue::droppedItem(key: $key, reason: $this->describe($item)." is not {$expected}");

                continue;
            }

            $kept[] = $trimmed;
        }

        return array_values(array_unique($kept));
    }

    /**
     * Resolve the store path, falling back to the default path when the value is refused.
     *
     * @param  array<mixed>  $raw
     */
    protected function database(array $raw): string
    {
        $default = $this->storagePath.'/firewatch/firewatch.sqlite';

        if (! array_key_exists('database', $raw)) {
            return $default;
        }

        $value = $raw['database'];

        if (! is_string($value)) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'database', reason: $this->describe($value).' is not a file path', default: $default);

            return $default;
        }

        $reason = $this->refusedPath($value);

        if ($reason !== null) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'database', reason: $reason, default: $default);

            return $default;
        }

        return $this->resolvedPath($value);
    }

    /**
     * Get the reason a store path is refused, or null when it is usable.
     */
    protected function refusedPath(string $path): ?string
    {
        $described = $this->describe($path);

        return match (true) {
            $path === '' => "{$described} is not a file path",
            $path === ':memory:' => "{$described} is an in-memory database, not a file path",
            str_starts_with($path, 'file:') => "{$described} is a URI, not a file path",
            str_contains($path, "\0") => "{$described} contains a NUL byte",
            strlen($path) > static::MAXIMUM_PATH_BYTES => "{$described} is longer than ".static::MAXIMUM_PATH_BYTES.' bytes',
            $this->isUnderPublicPath($this->resolvedPath($path)) => "{$described} is under the public directory",
            default => null,
        };
    }

    /**
     * Resolve a store path against the base path unless it is absolute.
     */
    protected function resolvedPath(string $path): string
    {
        return $this->isAbsolutePath($path) ? $path : $this->basePath.'/'.$path;
    }

    /**
     * Determine if a path is absolute, on POSIX or Windows.
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * Determine if a path lies inside the public directory.
     */
    protected function isUnderPublicPath(string $path): bool
    {
        $public = rtrim($this->withoutDotSegments($this->publicPath), '/').'/';

        return str_starts_with($this->withoutDotSegments($path), $public);
    }

    /**
     * Remove the dot segments from a path without touching the filesystem.
     */
    protected function withoutDotSegments(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        $root = str_starts_with($path, '/') || str_starts_with($path, '\\') ? '/' : '';

        return $root.implode('/', $segments);
    }

    /**
     * Read the deploy identity, trimmed and cut at 255 bytes, or null when it is unset.
     *
     * @param  array<mixed>  $raw
     */
    protected function deploy(array $raw): ?string
    {
        $value = $raw['deploy'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'deploy', reason: $this->describe($value).' is not a string', default: 'unset');

            return null;
        }

        $deploy = trim($this->withoutInvalidUtf8($value));

        if ($deploy === '') {
            return null;
        }

        return mb_strcut($deploy, 0, static::MAXIMUM_DEPLOY_BYTES, 'UTF-8');
    }

    /**
     * Remove the bytes that are not valid UTF-8.
     */
    protected function withoutInvalidUtf8(string $value): string
    {
        return (string) preg_replace('/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s', '$1', $value);
    }

    /**
     * Read the valid budget entries, numbered by their place in the file.
     *
     * @param  array<mixed>  $raw
     * @return list<BudgetEntry>
     */
    protected function budgets(array $raw): array
    {
        if (! array_key_exists('budgets', $raw)) {
            return [];
        }

        if (! is_array($raw['budgets'])) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'budgets', reason: $this->describe($raw['budgets']).' is not a list of budget entries', default: 'none');

            return [];
        }

        $budgets = [];

        foreach (array_values($raw['budgets']) as $index => $entry) {
            $number = $index + 1;
            $reason = $this->refusedBudgetEntry($entry);

            if ($reason !== null) {
                $this->issues[] = ConfigurationIssue::ignoredBudgetEntry(number: $number, reason: $reason);

                continue;
            }

            /** @var array{type: string, methods?: list<string>, path?: string, name?: string, duration?: int|float, memory?: int|float} $entry */
            $budgets[] = new BudgetEntry(
                number: $number,
                type: ExecutionType::from($entry['type']),
                methods: isset($entry['methods']) ? array_map(strtoupper(...), $entry['methods']) : null,
                path: $entry['path'] ?? null,
                name: $entry['name'] ?? null,
                durationMilliseconds: $entry['duration'] ?? null,
                memoryMegabytes: $entry['memory'] ?? null,
            );
        }

        return $budgets;
    }

    /**
     * Count the budget entries that were ignored as invalid.
     *
     * @param  array<mixed>  $raw
     * @param  list<BudgetEntry>  $budgets
     */
    protected function ignoredBudgetEntries(array $raw, array $budgets): int
    {
        return is_array($raw['budgets'] ?? null) ? count($raw['budgets']) - count($budgets) : 0;
    }

    /**
     * Get the reason a budget entry is ignored, or null when it is valid.
     */
    protected function refusedBudgetEntry(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return $this->describe($entry).' is not a budget entry';
        }

        if (! array_key_exists('type', $entry)) {
            return 'type is missing';
        }

        $type = is_string($entry['type']) ? ExecutionType::tryFrom($entry['type']) : null;

        if ($type === null) {
            $types = array_map(fn (ExecutionType $type) => $type->value, ExecutionType::cases());

            return 'type '.$this->describe($entry['type']).' is not '.Arr::join($types, ', ', ' or ');
        }

        return $this->refusedBudgetKeys($entry, $type)
            ?? $this->refusedMethods($entry)
            ?? $this->refusedPattern($entry, 'path')
            ?? $this->refusedPattern($entry, 'name')
            ?? $this->refusedCeiling($entry, 'duration', static::MAXIMUM_DURATION_MILLISECONDS)
            ?? $this->refusedCeiling($entry, 'memory', static::MAXIMUM_MEMORY_MEGABYTES)
            ?? (isset($entry['duration']) || isset($entry['memory']) ? null : 'no duration or memory ceiling');
    }

    /**
     * Get the reason a budget entry holds a key its type does not allow.
     *
     * @param  array<mixed>  $entry
     */
    protected function refusedBudgetKeys(array $entry, ExecutionType $type): ?string
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
                : 'key '.$this->describe($key).' is not allowed';
        }

        return null;
    }

    /**
     * Get the reason a budget entry's methods are refused.
     *
     * @param  array<mixed>  $entry
     */
    protected function refusedMethods(array $entry): ?string
    {
        if (! array_key_exists('methods', $entry)) {
            return null;
        }

        $methods = $entry['methods'];

        if (! is_array($methods) || $methods === [] || ! array_is_list($methods)) {
            return 'methods '.$this->describe($methods).' is not a non-empty list of HTTP verbs';
        }

        foreach ($methods as $method) {
            if (! is_string($method) || ! in_array(strtoupper($method), static::HTTP_VERBS, true)) {
                return 'method '.$this->describe($method).' is not '.Arr::join(static::HTTP_VERBS, ', ', ' or ');
            }
        }

        return null;
    }

    /**
     * Get the reason a budget entry's path or name pattern is refused.
     *
     * @param  array<mixed>  $entry
     */
    protected function refusedPattern(array $entry, string $matcher): ?string
    {
        if (! array_key_exists($matcher, $entry) || is_string($entry[$matcher])) {
            return null;
        }

        return "{$matcher} ".$this->describe($entry[$matcher]).' is not a string';
    }

    /**
     * Get the reason a budget entry's duration or memory ceiling is refused.
     *
     * @param  array<mixed>  $entry
     */
    protected function refusedCeiling(array $entry, string $ceiling, int $maximum): ?string
    {
        if (! array_key_exists($ceiling, $entry)) {
            return null;
        }

        $value = $entry[$ceiling];

        if ((is_int($value) || is_float($value)) && $value > 0 && $value <= $maximum) {
            return null;
        }

        return "{$ceiling} ".$this->describe($value)." is not a number greater than 0 and at most {$maximum}";
    }

    /**
     * Describe a value for an issue's reason, quoting strings and cutting them to 60 characters.
     */
    protected function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => json_encode(mb_substr($value, 0, static::DESCRIBED_VALUE_CHARACTERS), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $value === null => 'null',
            $value === [] => '[]',
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
