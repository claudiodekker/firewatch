<?php

namespace ClaudioDekker\Firewatch\Configuration;

use Closure;

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
     * The longest store path accepted, in bytes.
     */
    protected const MAXIMUM_PATH_BYTES = 4096;

    /**
     * The length a deploy identity is cut at, in bytes.
     */
    protected const MAXIMUM_DEPLOY_BYTES = 255;

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
        protected string $environment,
    ) {
        //
    }

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
        $budgets = Budgets::parse($raw);
        $retentionAgeSeconds = $this->durationSeconds($retentionAge);

        return new Configuration(
            enabled: $enabled,
            environments: $environments,
            database: $database,
            busyTimeoutMilliseconds: $busyTimeoutMilliseconds,
            retentionAge: $retentionAge,
            retentionAgeSeconds: $retentionAgeSeconds,
            retentionRecords: $retentionRecords,
            deploy: $deploy,
            captureLogs: $captureLogs,
            captureRequestPayload: $captureRequestPayload,
            redactPayloadFields: $redactPayloadFields,
            redactHeaders: $redactHeaders,
            budgets: $budgets,
            issues: [...$this->issues, ...$budgets->issues],
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
        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: ConfigurationIssue::describe($value)." is not a boolean ({$accepted})", default: $default ? 'true' : 'false');

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

        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: ConfigurationIssue::describe($value)." is not an integer from {$minimum} to {$maximum}", default: (string) $default);

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

        $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: ConfigurationIssue::describe($value)." is not a duration (digits then s, m, h, d or w, for example {$default})", default: $default);

        return $default;
    }

    /**
     * Convert a valid duration into seconds.
     */
    protected function durationSeconds(string $duration): int
    {
        $unit = substr($duration, -1);
        $count = intval(substr($duration, 0, -1));

        return $count * DurationUnit::from($unit)->seconds();
    }

    /**
     * Read the environments a process captures in.
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

        if (! $this->isList($raw['environments'])) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'environments', reason: ConfigurationIssue::describe($raw['environments']).static::NOT_A_LIST, default: $default);

            return static::DEFAULT_ENVIRONMENTS;
        }

        $environments = $this->list(
            $raw['environments'],
            key: 'environments',
            expected: static::ENVIRONMENT_NAME_DESCRIPTION,
            accepts: fn (string $item) => preg_match('/^[A-Za-z0-9_.-]+$/', $item) === 1,
        );

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

        if (! $this->isList($values[$name])) {
            $this->issues[] = ConfigurationIssue::fallBack(key: $key, reason: ConfigurationIssue::describe($values[$name]).static::NOT_A_LIST, default: 'none');

            return [];
        }

        return $this->list($values[$name], key: $key, expected: 'a string', accepts: fn (string $item) => true);
    }

    /**
     * Determine if a value is an array or a comma-separated string.
     */
    protected function isList(mixed $value): bool
    {
        return is_string($value) || is_array($value);
    }

    /**
     * Read a list from an array or a comma-separated string, dropping the items that are refused.
     *
     * @param  Closure(string): bool  $accepts
     * @return list<string>
     */
    protected function list(mixed $value, string $key, string $expected, Closure $accepts): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        $kept = [];

        foreach ($items as $item) {
            $trimmed = is_string($item) ? trim($item) : null;

            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === null || ! $accepts($trimmed)) {
                $this->issues[] = ConfigurationIssue::droppedItem(key: $key, reason: ConfigurationIssue::describe($item)." is not {$expected}");

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
        $default = $this->storagePath.'/firewatch/firewatch-'.$this->environment.'.sqlite';
        $value = $raw['database'] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            $this->issues[] = ConfigurationIssue::fallBack(key: 'database', reason: ConfigurationIssue::describe($value).' is not a file path', default: $default);

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
        $described = ConfigurationIssue::describe($path);

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
        $normalised = $this->withoutDotSegments($path);

        if ($this->isWindowsPath($path) || $this->isWindowsPath($this->publicPath)) {
            return str_starts_with(strtolower($normalised), strtolower($public));
        }

        return str_starts_with($normalised, $public);
    }

    /**
     * Determine if a path is written the Windows way, whose case doesn't matter.
     */
    protected function isWindowsPath(string $path): bool
    {
        return preg_match('#^[A-Za-z]:#', $path) === 1 || str_contains($path, '\\');
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
     * Read the deploy identity, or null when it is unset.
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
            $this->issues[] = ConfigurationIssue::fallBack(key: 'deploy', reason: ConfigurationIssue::describe($value).' is not a string', default: 'unset');

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
}
