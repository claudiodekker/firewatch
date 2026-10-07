<?php

namespace Workbench\App\Fixtures;

enum Placeholder: string
{
    case TIMESTAMP = '{timestamp}';
    case UUID = '{uuid}';
    case HASH = '{hash}';
    case DURATION = '{duration}';
    case BYTES = '{bytes}';
    case LINE = '{line}';
    case DEPLOY = '{deploy}';
    case SERVER = '{server}';
    case IP = '{ip}';
    case PATH = '{path}';
    case TRACE = '{trace}';
    case VERSION = '{version}';

    /**
     * Get the placeholder for a wire field whose value varies between runs, or null for a field that does not vary.
     */
    public static function forField(string $field): ?self
    {
        return match ($field) {
            'timestamp' => self::TIMESTAMP,
            'trace_id', 'execution_id', 'job_id', 'attempt_id' => self::UUID,
            '_group' => self::HASH,
            'duration', 'bootstrap', 'before_middleware', 'action', 'render', 'after_middleware', 'sending', 'terminating' => self::DURATION,
            'peak_memory_usage' => self::BYTES,
            'line' => self::LINE,
            'deploy' => self::DEPLOY,
            'server' => self::SERVER,
            'ip' => self::IP,
            'file' => self::PATH,
            'trace' => self::TRACE,
            'php_version', 'laravel_version' => self::VERSION,
            default => null,
        };
    }

    /**
     * Get the kind of value the placeholder stands for.
     */
    public function kind(): string
    {
        return match ($this) {
            self::TIMESTAMP => 'number',
            self::DURATION, self::BYTES, self::LINE => 'integer',
            default => 'string',
        };
    }

    /**
     * Get the deterministic value a synthetic record resolves the placeholder to.
     */
    public function resolve(): mixed
    {
        return match ($this) {
            self::TIMESTAMP => 1767225600.25,
            self::UUID => '9f0c3a1e-5b7d-4c2a-8e6f-1a2b3c4d5e6f',
            self::HASH => str_repeat('a', 32),
            self::DURATION => 1000,
            self::BYTES => 16777216,
            self::LINE => 42,
            self::DEPLOY => '',
            self::SERVER => 'web-1',
            self::IP => '127.0.0.1',
            self::PATH => 'app/Http/Controllers/OrderController.php',
            self::TRACE => '[]',
            self::VERSION => '1.0.0',
        };
    }

    /**
     * Determine if the placeholder can stand for the given wire value.
     */
    public function accepts(mixed $value): bool
    {
        // Nightwatch sends a fatal error with no trace and no execution id, and a fixture keeps that.
        if ($value === '' && in_array($this, [self::UUID, self::TRACE], true)) {
            return false;
        }

        $kind = WireFixture::kindOf($value);

        // A JSON number may be written without a fraction.
        return $kind === $this->kind() || ($kind === 'integer' && $this->kind() === 'number');
    }
}
